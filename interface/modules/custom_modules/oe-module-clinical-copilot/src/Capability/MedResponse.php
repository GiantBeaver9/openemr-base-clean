<?php

/**
 * MedResponse (UC1, UC3) — medications, paired with lab movement, never a cause.
 *
 * It unions the T4 medication sources (prescriptions + lists) through a MedReader and emits
 * one `med_event` per distinct drug. A drug reconciled across BOTH tables (the metformin
 * duplicate, L2/D6) is linked via the app-maintained normalized-drug identity: the med_event
 * cites BOTH source rows, and the dropped duplicate is surfaced as a VISIBLE exclusion (I5) —
 * never silently merged away.
 *
 * Each med is laid against the patient's A1c movement (from ControlProxy's derived_delta) by
 * co-citing both sides — the med rows AND the lab rows that bracket the trend — and flagging
 * the direction. It is STRUCTURALLY incapable of asserting causation: the fact carries only
 * the juxtaposition (stable dose, rising A1c) and its citations; no causal token is ever
 * emitted (the "because" is refused by construction, per USERS.md §1 and UC3).
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
use OpenEMR\Modules\ClinicalCopilot\Fact\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;

final class MedResponse implements CapabilityInterface
{
    public const VERSION = 'med_response@1';

    /** The analyte a diabetes regimen is laid against for the paired trend (UC3). */
    private const PAIR_ANALYTE = 'a1c';

    public function __construct(
        private readonly MedReader $meds,
        private readonly ControlProxy $controlProxy,
        private readonly string $version = self::VERSION,
    ) {
    }

    public function forPatient(int $pid): array
    {
        $meds = $this->meds->readMeds($pid);
        [$direction, $pairCitations] = $this->labPairing($pid);

        $facts = [];
        foreach ($this->groupByDrug($meds) as $group) {
            foreach ($this->factsForGroup($pid, $group, $direction, $pairCitations) as $fact) {
                $facts[] = $fact;
            }
        }

        return $facts;
    }

    /**
     * One med_event for the reconciled drug (citing every source row) plus a visible
     * exclusion for each dropped cross-table duplicate.
     *
     * @param non-empty-list<MedRecord> $group
     * @param list<Citation>            $pairCitations
     * @return list<Fact>
     */
    private function factsForGroup(int $pid, array $group, ?string $direction, array $pairCitations): array
    {
        $primary = $group[0];
        $normalized = $primary->normalizedDrug();

        $sourceCitations = array_map(
            static fn(MedRecord $m): Citation => new Citation(
                $m->sourceTable,
                $m->id,
                $m->sourceTable === 'prescriptions' ? 'drug' : 'title',
                DateSource::Collected,
            ),
            $group,
        );

        $flags = ['drug:' . $normalized];
        $flags[] = $this->doseChanged($group) ? 'dose_changed' : 'dose_stable';
        if ($direction !== null && $pairCitations !== []) {
            $flags[] = 'paired';
            $flags[] = 'lab_trend:' . self::PAIR_ANALYTE . ':' . $direction;
        }

        $dosage = $primary->dosage;
        $value = new FactValue(
            $dosage,
            is_numeric($dosage) ? (float) $dosage : null,
            Comparator::None,
            '',
            null,
            null,
        );

        $facts = [];
        $facts[] = new Fact(
            Capability::MedResponse,
            $this->version,
            FactKind::MedEvent,
            $pid,
            $primary->startDate,
            DateSource::Collected,
            $value,
            FactStatus::Unstated,
            $flags,
            $this->mergeCitations([...$sourceCitations, ...$pairCitations]),
        );

        // Every dropped cross-table duplicate is visible (I5), never silently merged.
        foreach (array_slice($group, 1) as $duplicate) {
            $facts[] = new Fact(
                Capability::MedResponse,
                $this->version,
                FactKind::Exclusion,
                $pid,
                $duplicate->startDate,
                DateSource::Collected,
                null,
                FactStatus::Excluded,
                ['drug:' . $normalized, 'duplicate_med', Flag::excludedReason(ExclusionReason::Superseded)],
                [new Citation(
                    $duplicate->sourceTable,
                    $duplicate->id,
                    $duplicate->sourceTable === 'prescriptions' ? 'drug' : 'title',
                    DateSource::Collected,
                )],
            );
        }

        return $facts;
    }

    /**
     * The A1c trend direction + the raw lab rows behind it, from ControlProxy's derived_delta
     * (analyte:a1c). Returns [null, []] when there is no A1c movement to pair against.
     *
     * @return array{0: ?string, 1: list<Citation>}
     */
    private function labPairing(int $pid): array
    {
        foreach ($this->controlProxy->forPatient($pid) as $fact) {
            if ($fact->kind !== FactKind::DerivedDelta) {
                continue;
            }
            if (!$fact->hasFlag('analyte:' . self::PAIR_ANALYTE)) {
                continue;
            }
            $direction = null;
            foreach ($fact->flags as $flag) {
                if (str_starts_with($flag, 'direction:')) {
                    $direction = substr($flag, strlen('direction:'));
                    break;
                }
            }
            return [$direction, $fact->citations];
        }
        return [null, []];
    }

    /**
     * Group med records by normalized drug identity (the reconciliation link). Within a
     * group prescriptions rows sort first (the in-house, dose-bearing row is the primary),
     * then by id — a deterministic order.
     *
     * @param list<MedRecord> $meds
     * @return list<non-empty-list<MedRecord>>
     */
    private function groupByDrug(array $meds): array
    {
        /** @var array<string, list<MedRecord>> $groups */
        $groups = [];
        foreach ($meds as $med) {
            $groups[$med->normalizedDrug()][] = $med;
        }
        ksort($groups);

        $out = [];
        foreach ($groups as $group) {
            usort($group, static function (MedRecord $a, MedRecord $b): int {
                $rank = static fn(MedRecord $m): int => $m->sourceTable === 'prescriptions' ? 0 : 1;
                $cmp = $rank($a) <=> $rank($b);
                return $cmp !== 0 ? $cmp : $a->id <=> $b->id;
            });
            $out[] = $group;
        }
        return $out;
    }

    /**
     * True when the group carries more than one distinct non-empty dosage (a real dose
     * change on record); a single dose = stable.
     *
     * @param non-empty-list<MedRecord> $group
     */
    private function doseChanged(array $group): bool
    {
        $doses = [];
        foreach ($group as $med) {
            $dose = trim($med->dosage);
            if ($dose !== '') {
                $doses[$dose] = true;
            }
        }
        return count($doses) > 1;
    }

    /**
     * @param list<Citation> $citations
     * @return list<Citation>
     */
    private function mergeCitations(array $citations): array
    {
        $seen = [];
        $out = [];
        foreach ($citations as $citation) {
            $signature = $citation->table . '|' . $citation->pk . '|' . ($citation->field ?? '');
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $out[] = $citation;
        }
        return $out;
    }

    public function version(): string
    {
        return $this->version;
    }
}
