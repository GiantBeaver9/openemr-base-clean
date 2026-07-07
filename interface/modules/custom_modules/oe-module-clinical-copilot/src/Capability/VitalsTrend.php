<?php

/**
 * VitalsTrend (UC1, UC3) — weight / blood-pressure / BMI over `form_vitals`.
 *
 * For each active vitals row it emits a `vital` fact for every value ACTUALLY PRESENT in the
 * row — weight, BMI, and blood pressure (systolic/diastolic as one reading) — each citing the
 * exact `form_vitals` row and column. The invariant is deliberately conservative: a fact (and
 * any flag on it) exists only if its value exists in the row; nothing is fabricated or
 * inferred. This is the regimen context UC3 lays beside med changes — never a diagnosis, never
 * a threshold judgment the chart does not already carry.
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

final class VitalsTrend implements CapabilityInterface
{
    public const VERSION = 'vitals_trend@1';

    private const TABLE = 'form_vitals';

    public function __construct(
        private readonly VitalsReader $reader,
        private readonly string $version = self::VERSION,
    ) {
    }

    public function forPatient(int $pid): array
    {
        $facts = [];
        foreach ($this->reader->readVitals($pid) as $row) {
            $date = $this->normalizeDate($row->date);

            $weight = trim($row->weight);
            if ($weight !== '') {
                $facts[] = $this->vitalFact($pid, $row->id, 'weight', $date, $weight, $this->numeric($weight), 'lb', 'measure:weight');
            }

            $bmi = trim($row->bmi);
            if ($bmi !== '') {
                $facts[] = $this->vitalFact($pid, $row->id, 'BMI', $date, $bmi, $this->numeric($bmi), 'kg/m2', 'measure:bmi');
            }

            $systolic = trim($row->systolic);
            $diastolic = trim($row->diastolic);
            if ($systolic !== '' && $diastolic !== '') {
                // Blood pressure is a paired reading; the raw text carries both, parsed stays
                // null (no single scalar), so no exact numeric trend is implied off one number.
                $facts[] = $this->vitalFact(
                    $pid,
                    $row->id,
                    'bps',
                    $date,
                    $systolic . '/' . $diastolic,
                    null,
                    'mmHg',
                    'measure:bp',
                );
            }
        }
        return $facts;
    }

    private function vitalFact(
        int $pid,
        int $rowId,
        string $field,
        ?string $date,
        string $raw,
        ?float $parsed,
        string $unit,
        string $measureFlag,
    ): Fact {
        return new Fact(
            Capability::VitalsTrend,
            $this->version,
            FactKind::Vital,
            $pid,
            $date,
            DateSource::Collected,
            new FactValue($raw, $parsed, Comparator::None, $unit, $unit, null),
            FactStatus::Unstated,
            [$measureFlag],
            [new Citation(self::TABLE, $rowId, $field, DateSource::Collected)],
        );
    }

    private function numeric(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public function version(): string
    {
        return $this->version;
    }
}
