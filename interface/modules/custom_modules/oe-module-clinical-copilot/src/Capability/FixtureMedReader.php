<?php

/**
 * FixtureMedReader — the isolated-test MedReader. Reads the U2 `prescriptions.json` and
 * `lists.json` fixtures and unions them into MedRecords exactly as the Db impl's
 * PrescriptionService query would (T4). Only ACTIVE meds are returned (prescriptions.active
 * = 1 / lists.activity = 1 and type = 'medication') — the current regimen.
 *
 * `_`-prefixed documentation keys never appear on real rows and are ignored for free.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

final class FixtureMedReader implements MedReader
{
    public function __construct(private readonly string $fixturesDir)
    {
    }

    public function readMeds(int $pid): array
    {
        $meds = [];

        foreach ($this->load('prescriptions.json') as $row) {
            if ($this->intOrNull($row['patient_id'] ?? null) !== $pid) {
                continue;
            }
            if ($this->intOrNull($row['active'] ?? null) !== 1) {
                continue;
            }
            $id = $this->intOrNull($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $meds[] = new MedRecord(
                'prescriptions',
                $id,
                $this->str($row['drug'] ?? ''),
                $this->str($row['dosage'] ?? ''),
                $this->nullableStr($row['start_date'] ?? null),
                true,
            );
        }

        foreach ($this->load('lists.json') as $row) {
            if ($this->intOrNull($row['pid'] ?? null) !== $pid) {
                continue;
            }
            if ($this->str($row['type'] ?? '') !== 'medication') {
                continue;
            }
            if ($this->intOrNull($row['activity'] ?? null) !== 1) {
                continue;
            }
            $id = $this->intOrNull($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $meds[] = new MedRecord(
                'lists',
                $id,
                $this->str($row['title'] ?? ''),
                '',
                $this->nullableStr($row['begdate'] ?? null),
                true,
            );
        }

        return $meds;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function load(string $file): array
    {
        $path = rtrim($this->fixturesDir, '/') . '/' . $file;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Fixture not readable: ' . $path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture is not a JSON array: ' . $path);
        }
        /** @var list<array<string, mixed>> $decoded */
        return array_values($decoded);
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return null;
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
