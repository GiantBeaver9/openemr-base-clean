<?php

/**
 * DateResolver — the C1 clinical-date precedence (ARCHITECTURE_COMPLETE.md "Two time axes").
 *
 * Precedence: procedure_report.date_collected → procedure_order.date_collected →
 * procedure_result.date → procedure_report.date_report. The first two are authoritative
 * collection dates (DateSource::Collected); the last two are fallbacks
 * (DateSource::Fallback). A NULL/empty/unparseable candidate falls through to the next.
 * All trend ordering and OverdueTests math runs on the resulting clinical date.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;

final class DateResolver
{
    /**
     * Walk the four candidates in C1 order, returning the first that normalizes to a
     * real date. Collected sources are tried before fallback sources.
     */
    public function resolve(
        ?string $reportDateCollected,
        ?string $orderDateCollected,
        ?string $resultDate,
        ?string $reportDateReport,
    ): DateResolution {
        $collected = $this->firstUsable([$reportDateCollected, $orderDateCollected]);
        if ($collected !== null) {
            return new DateResolution($collected, DateSource::Collected);
        }

        $fallback = $this->firstUsable([$resultDate, $reportDateReport]);
        if ($fallback !== null) {
            return new DateResolution($fallback, DateSource::Fallback);
        }

        // Nothing usable anywhere; a dateless fact is flagged as a fallback (least trust).
        return new DateResolution(null, DateSource::Fallback);
    }

    /**
     * First candidate that normalizes to Y-m-d, else null.
     *
     * @param list<?string> $candidates
     */
    private function firstUsable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return null;
    }

    /**
     * Normalize a host datetime string to an ISO date (Y-m-d). NULL, '', the SQL zero
     * date, and anything unparseable all return null so the precedence falls through.
     */
    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($trimmed);
        } catch (\Throwable) {
            return null;
        }
        return $date->format('Y-m-d');
    }
}
