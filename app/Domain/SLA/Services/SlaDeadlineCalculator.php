<?php

namespace App\Domain\SLA\Services;

use App\Models\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SlaDeadlineCalculator
{
    /**
     * Calculates ticket deadline based on configured business hours and holidays.
     * Skips non-working days (weekends) and non-working hours.
     */
    public function calculate(CarbonImmutable $start, int $minutes, array $calendar): CarbonImmutable
    {
        if ($minutes < 0) {
            throw ValidationException::withMessages(['resolution_minutes' => 'Duration cannot be negative.']);
        }
        if ($minutes === 0) {
            return $start->utc();
        }

        // Align starting cursor to the calendar's timezone
        $cursor = $start->setTimezone($calendar['timezone']);
        $remaining = $minutes * 60; // Convert duration to seconds

        // Advance through days until all required SLA minutes are consumed
        for ($day = 0; $day < 3660; $day++) {
            $date = $cursor->toDateString();
            $exceptions = $calendar['exceptions'] ?? [];

            // Use holiday/custom exceptions if defined, otherwise use standard weekly business hours
            $intervals = array_key_exists($date, $exceptions) ? $exceptions[$date] : ($calendar['hours'][$cursor->dayOfWeek] ?? []);

            foreach ($intervals as $interval) {
                $open = $cursor->startOfDay()->setTimeFromTimeString($interval[0]);
                $close = $cursor->startOfDay()->setTimeFromTimeString($interval[1]);

                // Skip intervals that already ended before current cursor
                if ($close->lessThanOrEqualTo($cursor)) {
                    continue;
                }

                // Count seconds remaining within today's working window
                $effectiveStart = $cursor->greaterThan($open) ? $cursor : $open;
                $available = (int) $effectiveStart->diffInSeconds($close);

                // If remaining SLA fits in this window, we found the exact deadline
                if ($remaining <= $available) {
                    return $effectiveStart->addSeconds($remaining)->utc();
                }

                $remaining -= $available;
                $cursor = $close;
            }

            // Move cursor to the beginning of next day
            $cursor = $cursor->addDay()->startOfDay();
        }

        throw ValidationException::withMessages(['calendar' => 'Calendar has insufficient business hours in the next ten years.']);
    }

    public function snapshot(BusinessCalendar $calendar): array
    {
        return ['timezone' => $calendar->timezone, 'hours' => $calendar->hours, 'exceptions' => $calendar->exceptions ?? []];
    }
}
