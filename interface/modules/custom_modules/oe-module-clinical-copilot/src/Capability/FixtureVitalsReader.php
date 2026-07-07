<?php

/**
 * FixtureVitalsReader — the isolated-test VitalsReader. Reads `form_vitals.json` and returns
 * the active rows for one patient in the same shape the Db impl (VitalsService) yields.
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

final class FixtureVitalsReader implements VitalsReader
{
    public function __construct(private readonly string $fixturesDir)
    {
    }

    public function readVitals(int $pid): array
    {
        $rows = [];
        foreach ($this->load('form_vitals.json') as $row) {
            if ($this->intOrNull($row['pid'] ?? null) !== $pid) {
                continue;
            }
            if ($this->intOrNull($row['activity'] ?? null) !== 1) {
                continue;
            }
            $id = $this->intOrNull($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $rows[] = new VitalRow(
                $id,
                $pid,
                $this->nullableStr($row['date'] ?? null),
                $this->str($row['bps'] ?? ''),
                $this->str($row['bpd'] ?? ''),
                $this->str($row['weight'] ?? ''),
                $this->str($row['BMI'] ?? ''),
                1,
            );
        }
        return $rows;
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
