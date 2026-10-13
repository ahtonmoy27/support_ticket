<?php

namespace Tests\Feature;

use App\Domain\SLA\Services\SlaDeadlineCalculator;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function calendar(): array
    {
        return ['timezone' => 'Asia/Dhaka', 'hours' => array_fill_keys([0, 1, 2, 3, 4], [['09:00', '18:00']]), 'exceptions' => []];
    }

    public function test_thursday_evening_rolls_over_weekend_to_sunday(): void
    {
        $due = app(SlaDeadlineCalculator::class)->calculate(CarbonImmutable::parse('2026-10-15 17:00', 'Asia/Dhaka'), 120, $this->calendar());
        $this->assertSame('2026-10-18 10:00', $due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $this->assertSame('UTC', $due->timezoneName);
    }

    public function test_holidays_split_shifts_and_special_openings(): void
    {
        $calendar = $this->calendar();
        $calendar['hours'][0] = [['09:00', '12:00'], ['13:00', '18:00']];
        $calendar['exceptions']['2026-10-18'] = [];
        $due = app(SlaDeadlineCalculator::class)->calculate(CarbonImmutable::parse('2026-10-15 17:00', 'Asia/Dhaka'), 120, $calendar);
        $this->assertSame('2026-10-19 10:00', $due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
        unset($calendar['exceptions']['2026-10-18']);
        $due = app(SlaDeadlineCalculator::class)->calculate(CarbonImmutable::parse('2026-10-18 11:30', 'Asia/Dhaka'), 60, $calendar);
        $this->assertSame('2026-10-18 13:30', $due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $calendar['exceptions']['2026-10-16'] = [['10:00', '11:00']];
        $due = app(SlaDeadlineCalculator::class)->calculate(CarbonImmutable::parse('2026-10-15 18:00', 'Asia/Dhaka'), 60, $calendar);
        $this->assertSame('2026-10-16 11:00', $due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
    }

    public function test_seconds_are_preserved_and_exact_closing_boundary_is_allowed(): void
    {
        $calculator = app(SlaDeadlineCalculator::class);
        $due = $calculator->calculate(CarbonImmutable::parse('2026-10-18 09:00:30', 'Asia/Dhaka'), 60, $this->calendar());
        $this->assertSame('10:00:30', $due->setTimezone('Asia/Dhaka')->format('H:i:s'));
        $due = $calculator->calculate(CarbonImmutable::parse('2026-10-18 17:00', 'Asia/Dhaka'), 60, $this->calendar());
        $this->assertSame('18:00', $due->setTimezone('Asia/Dhaka')->format('H:i'));
    }

    private function policyPayload(): array
    {
        return ['name' => 'New SLA'] + $this->calendar() + ['rules' => array_map(fn ($priority) => ['priority' => $priority, 'resolution_minutes' => 60, 'warning_percent' => 80], ['low', 'medium', 'high', 'critical'])];
    }

    public function test_policy_versions_do_not_change_existing_ticket_deadlines(): void
    {
        config(['support.auto_assign' => false, 'support.admin_email' => null, 'support.admin_password' => null]);
        $this->seed(SupportSeeder::class);
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['subject' => 'Example', 'description' => 'Example description', 'category_id' => Category::first()->id, 'priority' => 'critical'];
        $old = app(CreateTicket::class)->execute($customer, $data);
        $due = $old->sla_due_at;
        $calendar = $old->sla_calendar_snapshot;
        $this->actingAs($customer)->postJson('/api/v1/admin/sla-policies', $this->policyPayload())->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/admin/sla-policies', $this->policyPayload())->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertSame(1, SlaPolicy::where('is_active', true)->count());
        $this->assertTrue($old->refresh()->sla_due_at->equalTo($due));
        $this->assertEquals($calendar, $old->sla_calendar_snapshot);
        $new = app(CreateTicket::class)->execute($customer, $data);
        $this->assertSame(60, $new->sla_target_minutes);
    }

    public function test_invalid_timezone_overlapping_intervals_and_duplicate_priorities_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->postJson('/api/v1/admin/sla-policies', array_replace($this->policyPayload(), ['timezone' => 'Invalid/Zone']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/sla-policies', array_replace($this->policyPayload(), ['hours' => [0 => [['09:00', '12:00'], ['11:00', '13:00']]]]))->assertUnprocessable();
        $data = $this->policyPayload();
        $data['rules'][1]['priority'] = 'low';
        $this->postJson('/api/v1/admin/sla-policies', $data)->assertUnprocessable();
        $data = $this->policyPayload();
        $data['exceptions'] = ['2026-02-30' => []];
        $this->postJson('/api/v1/admin/sla-policies', $data)->assertUnprocessable();
        $data = $this->policyPayload();
        $data['hours'] = [0 => [['start' => '09:00', 'end' => '18:00']]];
        $this->postJson('/api/v1/admin/sla-policies', $data)->assertUnprocessable();
    }

    public function test_daylight_saving_consumes_elapsed_business_time(): void
    {
        $calendar = ['timezone' => 'America/New_York', 'hours' => [0 => [['01:00', '04:00']]], 'exceptions' => []];
        $due = app(SlaDeadlineCalculator::class)->calculate(CarbonImmutable::parse('2026-03-08 01:00', 'America/New_York'), 120, $calendar);
        $this->assertSame('2026-03-08 04:00', $due->setTimezone('America/New_York')->format('Y-m-d H:i'));
    }
}
