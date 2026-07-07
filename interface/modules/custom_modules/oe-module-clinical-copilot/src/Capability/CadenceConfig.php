<?php

/**
 * CadenceConfig — the capability-layer view of the versioned `mod_copilot_cadence` config.
 *
 * `LabCadenceConfig` (U4) reads the slice-facing config (unit whitelist, thresholds, LOINC
 * → analyte); this reader is its sibling for the three keys the capabilities in this unit
 * need and that LabCadenceConfig deliberately does not expose:
 *   - `code_set:<analyte>`  → LOINC set + `interval_days` (OverdueTests cadence).
 *   - `turnaround:<analyte>` → `days` (PendingResults expected_result_date derivation).
 * It also carries the config `version` so a cadence bump is a digest input (E5). Like its
 * sibling it is READ-ONLY and does no clinical math — it hands typed config to the
 * capabilities, which do the deterministic arithmetic through DerivedFacts.
 *
 * Row shape (per fixtures README): { config_key, config_value (JSON string), version }.
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

final readonly class CadenceConfig
{
    /**
     * @param array<string, string>    $loincToAnalyte  LOINC → analyte key
     * @param array<string, list<string>> $analyteToLoincs analyte → LOINC set
     * @param array<string, int>       $intervalDays    analyte → recommended interval (days)
     * @param array<string, int>       $turnaroundDays  analyte → lab turnaround (days)
     */
    private function __construct(
        private array $loincToAnalyte,
        private array $analyteToLoincs,
        private array $intervalDays,
        private array $turnaroundDays,
        private string $version,
    ) {
    }

    /**
     * Build from raw cadence rows (each ['config_key' => ?, 'config_value' => json, ...]).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function fromRows(array $rows): self
    {
        $loincToAnalyte = [];
        $analyteToLoincs = [];
        $intervalDays = [];
        $turnaroundDays = [];
        $version = '';

        foreach ($rows as $row) {
            $key = $row['config_key'] ?? null;
            if (!is_string($key)) {
                continue;
            }
            if ($version === '' && isset($row['version']) && is_string($row['version'])) {
                $version = $row['version'];
            }
            $rawValue = $row['config_value'] ?? null;
            if (!is_string($rawValue)) {
                continue;
            }
            $decoded = json_decode($rawValue, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (str_starts_with($key, 'code_set:')) {
                $analyte = substr($key, strlen('code_set:'));
                $loincList = [];
                $rawLoinc = $decoded['loinc'] ?? [];
                if (is_array($rawLoinc)) {
                    foreach ($rawLoinc as $loinc) {
                        if (is_string($loinc)) {
                            $loincToAnalyte[$loinc] = $analyte;
                            $loincList[] = $loinc;
                        }
                    }
                }
                $analyteToLoincs[$analyte] = $loincList;
                if (isset($decoded['interval_days']) && is_numeric($decoded['interval_days'])) {
                    $intervalDays[$analyte] = (int) $decoded['interval_days'];
                }
            } elseif (str_starts_with($key, 'turnaround:')) {
                $analyte = substr($key, strlen('turnaround:'));
                if (isset($decoded['days']) && is_numeric($decoded['days'])) {
                    $turnaroundDays[$analyte] = (int) $decoded['days'];
                }
            }
        }

        return new self($loincToAnalyte, $analyteToLoincs, $intervalDays, $turnaroundDays, $version);
    }

    /**
     * Build directly from a `mod_copilot_cadence.json` fixture file (isolated tests).
     */
    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Cadence fixture not readable: ' . $path);
        }
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            throw new \RuntimeException('Cadence fixture is not a JSON array: ' . $path);
        }
        /** @var list<array<string, mixed>> $rows */
        return self::fromRows(array_values($rows));
    }

    public function analyteForLoinc(string $loinc): ?string
    {
        return $this->loincToAnalyte[$loinc] ?? null;
    }

    /**
     * @return list<string>
     */
    public function loincsForAnalyte(string $analyte): array
    {
        return $this->analyteToLoincs[$analyte] ?? [];
    }

    public function intervalDays(string $analyte): ?int
    {
        return $this->intervalDays[$analyte] ?? null;
    }

    public function turnaroundDays(string $analyte): ?int
    {
        return $this->turnaroundDays[$analyte] ?? null;
    }

    /**
     * All analytes that declare a monitoring interval (the OverdueTests candidate set).
     *
     * @return list<string>
     */
    public function analytesWithInterval(): array
    {
        return array_keys($this->intervalDays);
    }

    public function version(): string
    {
        return $this->version;
    }
}
