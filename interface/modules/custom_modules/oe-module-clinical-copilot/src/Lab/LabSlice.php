<?php

/**
 * LabSlice — composes the C1–C4 lab contract over a LabRowSource and emits Facts.
 *
 * It is the single input path the lab-facing capabilities (ControlProxy, OverdueTests,
 * PendingResults) share, so the hard-won data-quality rules live in exactly one place:
 *   - C1 date precedence (DateResolver) → clinical_date + date_source per fact.
 *   - C2 status (StatusResolver) → presentable status or a VISIBLE exclusion; plus
 *     supersession within (pid, result_code, clinical_date): corrected > final >
 *     unstated > preliminary, ties → highest procedure_result_id. Winner presented; each
 *     loser is a VISIBLE exclusion flagged superseded_n + excluded_reason:superseded.
 *   - C3 parsing (ValueParser) → parsed|null, comparator (censored). No numeric claim
 *     without a parsed number.
 *   - C4 units (UnitConverter) → canonical value/unit or "no unit, no math" exclusion.
 *   - Out-of-range ONLY via the two admissible proofs (parsed vs threshold; abnormal +
 *     range); a disagreement is presented with the conflict flag, nothing adjudicated (I8).
 *
 * NO SILENT EXCLUSION (I5): every filtered row emits a visible exclusion Fact carrying
 * its ExclusionReason and a citation to the row it excluded. Every Fact carries ≥1
 * Citation. Facts are stamped with the ControlProxy capability (the lab trend/result
 * producer); the other lab capabilities build on top of this slice.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;

final class LabSlice
{
    private const RESULT_TABLE = 'procedure_result';

    /** result_data_type values that carry a numeric contract (C3). */
    private const NUMERIC_TYPES = ['N', 'S'];

    private readonly DateResolver $dateResolver;
    private readonly StatusResolver $statusResolver;
    private readonly ValueParser $valueParser;

    public function __construct(
        private readonly LabRowSource $source,
        private readonly UnitConverter $unitConverter,
        private readonly LabCadenceConfig $config,
        private readonly string $capabilityVersion = 'control_proxy@1',
        ?DateResolver $dateResolver = null,
        ?StatusResolver $statusResolver = null,
        ?ValueParser $valueParser = null,
    ) {
        $this->dateResolver = $dateResolver ?? new DateResolver();
        $this->statusResolver = $statusResolver ?? new StatusResolver();
        $this->valueParser = $valueParser ?? new ValueParser();
    }

    /**
     * Emit the full lab-slice fact list for one patient.
     *
     * @return list<Fact>
     */
    public function extract(int $pid): array
    {
        $facts = [];
        /** @var array<string, list<array{row: LabRow, value: FactValue, status: FactStatus, date: DateResolution, comparator: Comparator, canonical: ?float}>> $groups */
        $groups = [];

        foreach ($this->source->fetchForPatient($pid) as $row) {
            $date = $this->dateResolver->resolve(
                $row->reportDateCollected,
                $row->orderDateCollected,
                $row->resultDate,
                $row->reportDateReport,
            );
            $statusRes = $this->statusResolver->resolve($row->resultStatus);

            // C2 — status exclusion (unperformed / unrecognized): visible, never guessed.
            if ($statusRes->isExcluded()) {
                $reason = $statusRes->exclusionReason ?? ExclusionReason::UnrecognizedStatus;
                $facts[] = $this->exclusionFact(
                    $pid,
                    $row,
                    $date,
                    $reason,
                    new FactValue($row->result, null, Comparator::None, $row->units, null, null),
                    [],
                    'result_status',
                );
                continue;
            }

            // C3 — non-numeric result_data_type carries no numeric contract.
            if (!in_array($row->resultDataType, self::NUMERIC_TYPES, true)) {
                $facts[] = $this->exclusionFact(
                    $pid,
                    $row,
                    $date,
                    ExclusionReason::NonNumericType,
                    new FactValue($row->result, null, Comparator::None, $row->units, null, null),
                    [],
                    'result_data_type',
                );
                continue;
            }

            $parsed = $this->valueParser->parse($row->result, $row->resultDataType);

            // C3 — unparseable value: no numeric claim, excluded and flagged.
            if ($parsed->parsed === null) {
                $facts[] = $this->exclusionFact(
                    $pid,
                    $row,
                    $date,
                    ExclusionReason::Unparseable,
                    new FactValue($row->result, null, $parsed->comparator, $row->units, null, null),
                    [],
                    'result',
                );
                continue;
            }

            $conversion = $this->unitConverter->convert($row->resultCode, $parsed->parsed, $row->units);

            // C4 — no unit, no math: empty/unrecognized unit excluded but VISIBLE.
            if ($conversion->isUnitless()) {
                $facts[] = $this->exclusionFact(
                    $pid,
                    $row,
                    $date,
                    ExclusionReason::NoUnit,
                    new FactValue($row->result, $parsed->parsed, $parsed->comparator, $row->units, null, null),
                    [],
                    'units',
                );
                continue;
            }

            $value = new FactValue(
                $row->result,
                $conversion->canonicalValue,
                $parsed->comparator,
                $row->units,
                $conversion->unitCanonical,
                $conversion->conversionVersion,
            );

            // C2 — preliminary: in-flight section only, never a trend point, no clock reset.
            if ($statusRes->status === FactStatus::Preliminary) {
                $prelimFlags = $parsed->comparator->isCensored() ? [Flag::CENSORED] : [];
                $facts[] = new Fact(
                    Capability::ControlProxy,
                    $this->capabilityVersion,
                    FactKind::PreliminaryResult,
                    $pid,
                    $date->clinicalDate,
                    $date->source,
                    $value,
                    FactStatus::Preliminary,
                    $prelimFlags,
                    [new Citation(self::RESULT_TABLE, $row->procedureResultId, 'result', $date->source)],
                );
                continue;
            }

            // Presentable performed result (final/corrected/unstated) → supersession group.
            $groupKey = $row->resultCode . '|' . ($date->clinicalDate ?? 'null');
            $groups[$groupKey][] = [
                'row' => $row,
                'value' => $value,
                'status' => $statusRes->status,
                'date' => $date,
                'comparator' => $parsed->comparator,
                'canonical' => $conversion->canonicalValue,
            ];
        }

        foreach ($groups as $group) {
            foreach ($this->resolveSupersession($pid, $group) as $fact) {
                $facts[] = $fact;
            }
        }

        return $facts;
    }

    /**
     * Resolve one (pid, result_code, clinical_date) group: pick the winner and emit it as
     * a trend point; emit each loser as a visible superseded exclusion.
     *
     * @param list<array{row: LabRow, value: FactValue, status: FactStatus, date: DateResolution, comparator: Comparator, canonical: ?float}> $group
     * @return list<Fact>
     */
    private function resolveSupersession(int $pid, array $group): array
    {
        usort($group, static function (array $a, array $b): int {
            $rank = $b['status']->supersessionRank() <=> $a['status']->supersessionRank();
            if ($rank !== 0) {
                return $rank;
            }
            // Tie → highest procedure_result_id wins.
            return $b['row']->procedureResultId <=> $a['row']->procedureResultId;
        });

        $winner = $group[0];
        $losers = array_slice($group, 1);
        $supersededCount = count($losers);

        $flags = $this->outOfRangeFlags($winner['row'], $winner['canonical'], $winner['comparator']);
        if ($winner['comparator']->isCensored()) {
            $flags[] = Flag::CENSORED;
        }
        if ($supersededCount >= 1) {
            // Winner supersedes N prior results (C2).
            $flags[] = Flag::superseded($supersededCount);
        }

        $facts = [];
        $facts[] = new Fact(
            Capability::ControlProxy,
            $this->capabilityVersion,
            FactKind::TrendPoint,
            $pid,
            $winner['date']->clinicalDate,
            $winner['date']->source,
            $winner['value'],
            $winner['status'],
            $flags,
            [new Citation(self::RESULT_TABLE, $winner['row']->procedureResultId, 'result', $winner['date']->source)],
        );

        foreach ($losers as $loser) {
            $facts[] = $this->exclusionFact(
                $pid,
                $loser['row'],
                $loser['date'],
                ExclusionReason::Superseded,
                $loser['value'],
                [Flag::superseded($supersededCount)],
                'result',
            );
        }

        return $facts;
    }

    /**
     * The two admissible out-of-range proofs (C3):
     *   (a) parsed value vs. threshold (skipped for censored values — no exact claim),
     *   (b) lab abnormal ∈ {yes,high,low} + a reported range.
     * If both are available and disagree, both flags are set and CONFLICT is added —
     * nothing is adjudicated (I8).
     *
     * @return list<string>
     */
    private function outOfRangeFlags(LabRow $row, ?float $canonicalValue, Comparator $comparator): array
    {
        $flags = [];

        // Proof (a): parsed numeric vs. threshold.
        $valueAvailable = false;
        $valueOutOfRange = false;
        $analyte = $this->config->analyteForLoinc($row->resultCode);
        $threshold = $analyte !== null ? $this->config->thresholdConfig($analyte) : null;
        if ($threshold !== null && $canonicalValue !== null && !$comparator->isCensored()) {
            $valueAvailable = true;
            $max = $this->numericOrNull($threshold['target_max'] ?? null);
            $min = $this->numericOrNull($threshold['target_min'] ?? null);
            if (($max !== null && $canonicalValue > $max) || ($min !== null && $canonicalValue < $min)) {
                $valueOutOfRange = true;
            }
        }

        // Proof (b): lab abnormal flag + reported range.
        $labAvailable = false;
        $labOutOfRange = false;
        $abnormal = strtolower(trim($row->abnormal));
        $hasRange = trim($row->range) !== '';
        if (in_array($abnormal, ['yes', 'high', 'low', 'h', 'l', 'a', 'abnormal'], true) && $hasRange) {
            $labAvailable = true;
            $labOutOfRange = true;
        } elseif (in_array($abnormal, ['no', 'normal', 'n'], true)) {
            $labAvailable = true;
            $labOutOfRange = false;
        }

        if ($valueOutOfRange) {
            $flags[] = Flag::OUT_OF_RANGE_BY_VALUE;
        }
        if ($labOutOfRange) {
            $flags[] = Flag::OUT_OF_RANGE_BY_LAB_FLAG;
        }
        if ($valueAvailable && $labAvailable && $valueOutOfRange !== $labOutOfRange) {
            // Two proofs disagree — present both, adjudicate nothing (I8).
            $flags[] = Flag::CONFLICT;
        }

        return $flags;
    }

    /**
     * Build a visible exclusion fact (I5). `excluded_reason:<enum>` is always appended.
     *
     * @param list<string> $extraFlags
     */
    private function exclusionFact(
        int $pid,
        LabRow $row,
        DateResolution $date,
        ExclusionReason $reason,
        FactValue $value,
        array $extraFlags,
        string $citedField,
    ): Fact {
        $flags = $extraFlags;
        $flags[] = Flag::excludedReason($reason);

        return new Fact(
            Capability::ControlProxy,
            $this->capabilityVersion,
            FactKind::Exclusion,
            $pid,
            $date->clinicalDate,
            $date->source,
            $value,
            FactStatus::Excluded,
            $flags,
            [new Citation(self::RESULT_TABLE, $row->procedureResultId, $citedField, $date->source)],
        );
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Per-reason exclusion tally over an emitted fact list (I5 — the unitless-exclusion
     * rate and its siblings are recorded on the doc row). Keyed by ExclusionReason value.
     *
     * @param list<Fact> $facts
     * @return array<string, int>
     */
    public static function excludedCountsByReason(array $facts): array
    {
        $counts = [];
        foreach ($facts as $fact) {
            if ($fact->kind !== FactKind::Exclusion) {
                continue;
            }
            foreach ($fact->flags as $flag) {
                if (str_starts_with($flag, 'excluded_reason:')) {
                    $reason = substr($flag, strlen('excluded_reason:'));
                    $counts[$reason] = ($counts[$reason] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }
}
