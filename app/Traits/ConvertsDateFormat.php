<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait ConvertsDateFormat
{
    /**
     * Convert date format from various formats to MySQL date format (Y-m-d)
     * 
     * Handles formats like:
     * - M/d/Y (e.g., 1/12/2025 -> 2025-01-12)
     * - d/m/Y (e.g., 12/1/2025 -> 2025-01-12)
     * - Y-m-d (already MySQL format)
     * - Other common formats
     * 
     * @param string|null $date
     * @return string|null
     */
    protected function convertDateFormat(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        // If already in MySQL format (Y-m-d), return as is
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        try {
            // Try to parse the date using common formats
            $formats = [
                'm/d/Y',      // 1/12/2025 (US format: month/day/year)
                'd/m/Y',      // 12/1/2025 (European format: day/month/year)
                'Y/m/d',      // 2025/01/12
                'm-d-Y',      // 1-12-2025
                'd-m-Y',      // 12-1-2025
            ];

            foreach ($formats as $format) {
                $parsedDate = \DateTime::createFromFormat($format, $date);
                if ($parsedDate !== false) {
                    return $parsedDate->format('Y-m-d');
                }
            }

            // If none of the formats work, try strtotime as fallback
            // This handles many natural language dates and flexible formats
            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $convertedDate = date('Y-m-d', $timestamp);
                // Verify the conversion makes sense (not too far in past/future)
                $year = (int)date('Y', $timestamp);
                if ($year >= 1900 && $year <= 2100) {
                    return $convertedDate;
                }
            }

            // If all parsing fails, log warning and return null
            Log::warning("⚠️ Could not parse date format: {$date}");
            return null;
        } catch (\Exception $e) {
            Log::error("❌ Error converting date format for '{$date}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert datetime format from various formats to MySQL datetime format (Y-m-d H:i:s)
     * 
     * @param string|null $datetime
     * @return string|null
     */
    protected function convertDateTimeFormat(?string $datetime): ?string
    {
        if (empty($datetime)) {
            return null;
        }

        // If already in MySQL datetime format, return as is
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime)) {
            return $datetime;
        }

        try {
            // Try Carbon parse first (handles many formats)
            $parsed = \Carbon\Carbon::parse($datetime);
            return $parsed->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning("⚠️ Could not parse datetime format: {$datetime}");
            return null;
        }
    }
}

