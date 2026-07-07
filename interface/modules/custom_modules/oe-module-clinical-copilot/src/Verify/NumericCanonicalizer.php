<?php

/**
 * NumericCanonicalizer — the deterministic number/date normalization behind V4 (ARCHITECTURE.md
 * §2.2 numeric grounding).
 *
 * V4's guarantee: every number in a claim's TEXT (values, dates, counts) must already appear in a
 * fact that claim cites, after canonicalization (decimal normalization, ISO dates). The verifier
 * does NO arithmetic and neither does the model — a derived number (delta/count/span/expected
 * date) is grounded ONLY because a deterministic capability emitted a derived_* fact carrying it,
 * which this class then sees as an ordinary cited value. This class only PARSES and COMPARES; it
 * never computes.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

final class NumericCanonicalizer
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private function __construct()
    {
    }

    /**
     * Canonical decimal string: "8.40" -> "8.4", "08" -> "8", "0.60" -> "0.6", "-0.60" -> "-0.6".
     * Returns the trimmed input unchanged if it is not a plain decimal (defensive; callers only
     * pass matched numeric tokens).
     */
    public static function canonicalNumber(string $raw): string
    {
        $n = trim($raw);
        $neg = false;
        if (str_starts_with($n, '-')) {
            $neg = true;
            $n = substr($n, 1);
        } elseif (str_starts_with($n, '+')) {
            $n = substr($n, 1);
        }

        if (preg_match('/^\d+(\.\d+)?$/', $n) !== 1) {
            return trim($raw);
        }

        if (str_contains($n, '.')) {
            [$int, $frac] = explode('.', $n, 2);
            $frac = rtrim($frac, '0');
            $int = ltrim($int, '0');
            if ($int === '') {
                $int = '0';
            }
            $n = $frac === '' ? $int : $int . '.' . $frac;
        } else {
            $n = ltrim($n, '0');
            if ($n === '') {
                $n = '0';
            }
        }

        return ($neg && $n !== '0') ? '-' . $n : $n;
    }

    /**
     * Canonicalize a single date-like token to ISO "YYYY-MM-DD", or null if it is not a valid date.
     * Accepts ISO, US M/D/Y, and "Mon D, YYYY" forms.
     */
    public static function canonicalDate(string $raw): ?string
    {
        $s = trim($raw);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m) === 1) {
            return self::assembleDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $s, $m) === 1) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }
            return self::assembleDate($year, (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^([a-z]{3,})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/i', $s, $m) === 1) {
            $mon = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? null;
            if ($mon !== null) {
                return self::assembleDate((int) $m[3], $mon, (int) $m[2]);
            }
        }

        return null;
    }

    /**
     * Extract canonical numbers and ISO dates from free text. Date tokens are consumed first so
     * their components are not double-counted as bare numbers.
     *
     * @return array{numbers: list<string>, dates: list<string>}
     */
    public static function extract(string $text): array
    {
        $dates = [];
        $working = $text;

        $datePatterns = [
            '/\b\d{4}-\d{1,2}-\d{1,2}\b/',
            '#\b\d{1,2}/\d{1,2}/\d{2,4}\b#',
            '/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}\b/i',
        ];
        foreach ($datePatterns as $pattern) {
            $working = (string) preg_replace_callback($pattern, static function (array $m) use (&$dates): string {
                $iso = self::canonicalDate($m[0]);
                if ($iso !== null) {
                    $dates[] = $iso;
                    return ' '; // consume so components are not re-read as numbers
                }
                return $m[0];
            }, $working);
        }

        // Standalone numbers only: a digit glued to a letter is part of an analyte token
        // ("A1c", "T4", "B12"), not a numeric claim — the lookbehind/lookahead exclude those.
        $numbers = [];
        if (preg_match_all('/(?<![A-Za-z0-9.])-?\d+(?:\.\d+)?(?![A-Za-z0-9])/', $working, $mm) !== false) {
            foreach ($mm[0] as $token) {
                $numbers[] = self::canonicalNumber($token);
            }
        }

        return [
            'numbers' => array_values(array_unique($numbers)),
            'dates' => array_values(array_unique($dates)),
        ];
    }

    /**
     * Build the grounded number/date membership sets for the facts a single claim cites. A number
     * or date in the claim text is grounded iff it is a key in one of these maps.
     *
     * Sources per cited fact: the parsed value; every number/date lexically present in the raw
     * chart text; the clinical_date (ISO) and its year/month/day components (so "in 2026" or a bare
     * day-of-month grounds against the cited fact's own date).
     *
     * @param list<Fact> $facts
     *
     * @return array{numbers: array<string, true>, dates: array<string, true>}
     */
    public static function groundedForFacts(array $facts): array
    {
        $numbers = [];
        $dates = [];

        foreach ($facts as $fact) {
            $value = $fact->value;
            if ($value !== null) {
                if ($value->parsed !== null) {
                    $numbers[self::canonicalNumber((string) $value->parsed)] = true;
                }
                $fromRaw = self::extract($value->raw);
                foreach ($fromRaw['numbers'] as $n) {
                    $numbers[$n] = true;
                }
                foreach ($fromRaw['dates'] as $d) {
                    $dates[$d] = true;
                }
            }

            if ($fact->clinicalDate !== null) {
                $iso = self::canonicalDate($fact->clinicalDate);
                if ($iso !== null) {
                    $dates[$iso] = true;
                    [$y, $mo, $d] = explode('-', $iso);
                    $numbers[self::canonicalNumber($y)] = true;
                    $numbers[self::canonicalNumber($mo)] = true;
                    $numbers[self::canonicalNumber($d)] = true;
                }
            }
        }

        return ['numbers' => $numbers, 'dates' => $dates];
    }

    private static function assembleDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
