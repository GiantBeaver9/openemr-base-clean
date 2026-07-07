<?php

/**
 * DbVitalsReader — the production VitalsReader: wraps the host `OpenEMR\Services\VitalsService`
 * (its `search()` returns the `form_vitals` rows for a patient, including bps/bpd/weight/BMI).
 * The module reads only; it never writes vitals. Rows are mapped into VitalRow carriers in the
 * same shape the Fixture impl yields.
 *
 * Framework-coupled: needs the OpenEMR DB stack, so it is exercised in-stack, not by the
 * isolated runner; it is `php -l`-clean. FixtureVitalsReader is its isolated twin.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Services\VitalsService;

final class DbVitalsReader implements VitalsReader
{
    private readonly VitalsService $vitals;

    public function __construct(?VitalsService $vitals = null)
    {
        $this->vitals = $vitals ?? new VitalsService();
    }

    public function readVitals(int $pid): array
    {
        $result = $this->vitals->search(['pid' => $pid]);
        /** @var list<array<string, mixed>> $records */
        $records = $result->getData();

        $rows = [];
        foreach ($records as $record) {
            if ($this->intOrZero($record['activity'] ?? 1) !== 1 && isset($record['activity'])) {
                continue;
            }
            $rows[] = new VitalRow(
                $this->intOrZero($record['id'] ?? 0),
                $pid,
                $this->nullableStr($record['date'] ?? null),
                $this->str($record['bps'] ?? ''),
                $this->str($record['bpd'] ?? ''),
                $this->str($record['weight'] ?? ''),
                $this->str($record['BMI'] ?? ''),
                1,
            );
        }
        return $rows;
    }

    private function intOrZero(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function nullableStr(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return $this->str($value);
    }
}
