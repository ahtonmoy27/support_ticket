<?php
namespace App\Domain\SLA\Services;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
class SlaDeadlineCalculator {
    /** @param array{timezone:string,hours:array,exceptions?:array} $calendar */
    public function calculate(CarbonImmutable $start, int $minutes, array $calendar): CarbonImmutable {
        if ($minutes < 0) { throw ValidationException::withMessages(['resolution_minutes' => 'Duration cannot be negative.']); }
        if ($minutes === 0) { return $start->utc(); }
        $cursor = $start->setTimezone($calendar['timezone']);
        $remaining = $minutes * 60;
        for ($day = 0; $day < 3660; $day++) {
            $date = $cursor->toDateString();
            $exceptions = $calendar['exceptions'] ?? [];
            $intervals = array_key_exists($date, $exceptions) ? $exceptions[$date] : ($calendar['hours'][$cursor->dayOfWeek] ?? []);
            foreach ($intervals as $interval) {
                $open = $cursor->startOfDay()->setTimeFromTimeString($interval[0]);
                $close = $cursor->startOfDay()->setTimeFromTimeString($interval[1]);
                if ($close->lessThanOrEqualTo($cursor)) { continue; }
                $effectiveStart = $cursor->greaterThan($open) ? $cursor : $open;
                $available = (int) $effectiveStart->diffInSeconds($close);
                if ($remaining <= $available) { return $effectiveStart->addSeconds($remaining)->utc(); }
                $remaining -= $available;
                $cursor = $close;
            }
            $cursor = $cursor->addDay()->startOfDay();
        }
        throw ValidationException::withMessages(['calendar' => 'Calendar has insufficient business hours in the next ten years.']);
    }
    public function snapshot(\App\Models\BusinessCalendar $calendar): array {
        return ['timezone'=>$calendar->timezone,'hours'=>$calendar->hours,'exceptions'=>$calendar->exceptions ?? []];
    }
}