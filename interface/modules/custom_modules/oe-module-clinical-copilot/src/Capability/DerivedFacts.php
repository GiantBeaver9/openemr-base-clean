<?php

/**
 * DerivedFacts — the deterministic delta / count / span / expected-result-date builders.
 *
 * Derived facts are computed by capabilities, NEVER by the LLM and NEVER by the verifier
 * (V4). Each carries its result number in a typed FactValue and CITES the raw facts it was
 * computed from (by copying their citations) — so prose can say "rose 0.6 over three draws"
 * while the number itself is a checkable, cited fact and the verifier can re-run the exact
 * arithmetic. Every builder is pure and total: identical inputs → identical output; no
 * clock, no globals, no I/O.
 *
 * Quantitative-only rule: deltas and spans use only quantitative, NON-censored trend
 * points (parsed numeric + known canonical unit; a `<7.0` censored value proves a direction
 * but never an exact number, C3), so a derived delta is never fabricated from a censored
 * endpoint.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;

final class DerivedFacts
{
    /** Derived numbers are rounded to this many decimals (A1c delta landmine expects 1.36). */
    private const PRECISION = 2;

    /**
     * derived_delta over the whole trend: first quantitative draw → last quantitative draw.
     * Null unless at least two quantitative, non-censored points exist. Cites the first and
     * last raw trend-point rows (L1 known-answer: cites [6101, 6103]).
     *
     * @param list<Fact> $orderedPoints trend points ordered ascending by clinical date
     */
    public function delta(array $orderedPoints, Capability $capability, string $version, ?string $analyte = null): ?Fact
    {
        $quant = $this->quantitative($orderedPoints);
        if (count($quant) < 2) {
            return null;
        }
        $first = $quant[0];
        $last = $quant[count($quant) - 1];
        $from = $first->value?->parsed;
        $to = $last->value?->parsed;
        if ($from === null || $to === null) {
            return null;
        }
        $delta = round($to - $from, self::PRECISION);
        $direction = $delta > 0 ? 'rising' : ($delta < 0 ? 'falling' : 'flat');
        $unit = $last->value?->unitCanonical;

        $flags = ['direction:' . $direction];
        if ($analyte !== null) {
            $flags[] = 'analyte:' . $analyte;
        }

        return new Fact(
            $capability,
            $version,
            FactKind::DerivedDelta,
            $first->pid,
            $last->clinicalDate,
            $last->dateSource,
            new FactValue(
                sprintf('%+.2f', $delta),
                $delta,
                Comparator::None,
                $unit ?? '',
                $unit,
                null,
            ),
            FactStatus::Unstated,
            $flags,
            $this->mergeCitations([$first, $last]),
        );
    }

    /**
     * derived_count of the trend points used (quantitative draws). Cites every raw row.
     *
     * @param list<Fact> $orderedPoints
     */
    public function count(array $orderedPoints, Capability $capability, string $version, ?string $analyte = null): ?Fact
    {
        $quant = $this->quantitative($orderedPoints);
        if ($quant === []) {
            return null;
        }
        $n = count($quant);
        $last = $quant[$n - 1];

        $flags = [];
        if ($analyte !== null) {
            $flags[] = 'analyte:' . $analyte;
        }

        return new Fact(
            $capability,
            $version,
            FactKind::DerivedCount,
            $last->pid,
            $last->clinicalDate,
            $last->dateSource,
            new FactValue((string) $n, (float) $n, Comparator::None, '', null, null),
            FactStatus::Unstated,
            $flags,
            $this->mergeCitations($quant),
        );
    }

    /**
     * derived_span: whole-number days between the first and last quantitative draw.
     * Null unless two dated quantitative points exist. Cites first and last raw rows.
     *
     * @param list<Fact> $orderedPoints
     */
    public function span(array $orderedPoints, Capability $capability, string $version, ?string $analyte = null): ?Fact
    {
        $quant = $this->quantitative($orderedPoints);
        if (count($quant) < 2) {
            return null;
        }
        $first = $quant[0];
        $last = $quant[count($quant) - 1];
        if ($first->clinicalDate === null || $last->clinicalDate === null) {
            return null;
        }
        try {
            $start = new \DateTimeImmutable($first->clinicalDate);
            $end = new \DateTimeImmutable($last->clinicalDate);
        } catch (\Throwable) {
            return null;
        }
        $days = (int) $start->diff($end)->days;

        $flags = [];
        if ($analyte !== null) {
            $flags[] = 'analyte:' . $analyte;
        }

        return new Fact(
            $capability,
            $version,
            FactKind::DerivedSpan,
            $last->pid,
            $last->clinicalDate,
            $last->dateSource,
            new FactValue($days . ' days', (float) $days, Comparator::None, 'days', 'days', null),
            FactStatus::Unstated,
            $flags,
            $this->mergeCitations([$first, $last]),
        );
    }

    /**
     * expected_result_date = collection date + turnaround days (from versioned cadence
     * config). Deterministic, cites the ordering row that anchors it (L7: order 4203,
     * collection 2026-07-02, turnaround:a1c=2 → 2026-07-04). Null if the date is unusable.
     */
    public function expectedResultDate(
        int $pid,
        string $collectionDate,
        int $turnaroundDays,
        string $turnaroundVersion,
        Citation $orderCitation,
        Capability $capability,
        string $version,
    ): ?Fact {
        try {
            $collected = new \DateTimeImmutable($collectionDate);
        } catch (\Throwable) {
            return null;
        }
        $expected = $collected->modify('+' . max(0, $turnaroundDays) . ' days')->format('Y-m-d');

        return new Fact(
            $capability,
            $version,
            FactKind::ExpectedResultDate,
            $pid,
            $expected,
            DateSource::Collected,
            new FactValue(
                $expected,
                null,
                Comparator::None,
                '',
                null,
                $turnaroundVersion,
            ),
            FactStatus::Unstated,
            ['collection:' . $collected->format('Y-m-d'), 'turnaround_days:' . max(0, $turnaroundDays)],
            [$orderCitation],
        );
    }

    /**
     * Quantitative, non-censored trend points (parsed number + canonical unit, comparator
     * none) — the only points a derived number may be computed from.
     *
     * @param list<Fact> $points
     * @return list<Fact>
     */
    private function quantitative(array $points): array
    {
        $out = [];
        foreach ($points as $point) {
            $value = $point->value;
            if ($value === null) {
                continue;
            }
            if (!$value->isQuantitative() || $value->comparator->isCensored()) {
                continue;
            }
            $out[] = $point;
        }
        return $out;
    }

    /**
     * Copy the citations of the raw facts a derived fact is computed from (de-duplicated
     * on table+pk+field), so the derived number points back at exactly its inputs (V4).
     *
     * @param list<Fact> $facts
     * @return list<Citation>
     */
    private function mergeCitations(array $facts): array
    {
        $seen = [];
        $out = [];
        foreach ($facts as $fact) {
            foreach ($fact->citations as $citation) {
                $signature = $citation->table . '|' . $citation->pk . '|' . ($citation->field ?? '');
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;
                $out[] = $citation;
            }
        }
        return $out;
    }
}
