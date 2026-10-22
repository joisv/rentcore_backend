<?php

namespace App\Services;

use Carbon\Carbon;

class TimezoneService
{
    /**
     * Resolve timezone-aware query boundaries and localized labels.
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @param string|null $dateParam
     * @param string $timezone Fallback to 'Asia/Jakarta'
     * @return array
     */
    public static function resolveQueryBounds(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $dateParam = null,
        string $timezone = 'Asia/Jakarta'
    ): array {
        // Validate timezone or fallback to Asia/Jakarta
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'Asia/Jakarta';
        }

        if ($startDate && $endDate) {
            $localStart = Carbon::parse($startDate, $timezone)->startOfDay();
            $localEnd = Carbon::parse($endDate, $timezone)->endOfDay();
            $dateLabel = $localStart->toDateString() . ' - ' . $localEnd->toDateString();
        } elseif ($dateParam) {
            $localStart = Carbon::parse($dateParam, $timezone)->startOfDay();
            $localEnd = Carbon::parse($dateParam, $timezone)->endOfDay();
            $dateLabel = $localStart->toDateString();
        } else {
            $localNow = Carbon::now($timezone);
            $localStart = (clone $localNow)->startOfDay();
            $localEnd = (clone $localNow)->endOfDay();
            $dateLabel = $localNow->toDateString();
        }

        $startUtc = (clone $localStart)->setTimezone('UTC');
        $endUtc = (clone $localEnd)->setTimezone('UTC');

        return [
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'local_start' => $localStart,
            'local_end' => $localEnd,
            'start_date' => $localStart->toDateString(),
            'end_date' => $localEnd->toDateString(),
            'date_label' => $dateLabel,
            'timezone' => $timezone,
        ];
    }
}
