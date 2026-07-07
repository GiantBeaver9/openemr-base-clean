<?php

/**
 * LabCadenceConfig — the versioned lab configuration parsed out of `mod_copilot_cadence`.
 *
 * The cadence table is the single, versioned home for everything the lab slice needs
 * beyond the chart rows themselves (C4 unit whitelist, C3 thresholds, LOINC → analyte
 * code sets). Bundling it in one typed reader keeps "which config feeds a fact" auditable
 * and its version a digest input. This reader is READ-ONLY and does no math itself — it
 * hands structured config to UnitConverter and LabSlice.
 *
 * Row shape (per README): { config_key, config_value (JSON string), version }. Keys:
 *   - code_set:<analyte>  → {"loinc":[...], "interval_days":N}
 *   - unit:<analyte>      → {"canonical":"%", "conversions":{...}, "conversion_version":"conv:..."}
 *   - threshold:<analyte> → {"target_max":7.0, "threshold_version":"thr:..."}
 * Other keys (turnaround:*, etc.) are ignored here — they belong to other capabilities.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final readonly class LabCadenceConfig
{
    /**
     * @param array<string, string>              $loincToAnalyte LOINC code → analyte key
     * @param array<string, array<string, mixed>> $unitConfigs    analyte → unit config block
     * @param array<string, array<string, mixed>> $thresholds     analyte → threshold config block
     */
    private function __construct(
        private array $loincToAnalyte,
        private array $unitConfigs,
        private array $thresholds,
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
        $unitConfigs = [];
        $thresholds = [];

        foreach ($rows as $row) {
            $key = $row['config_key'] ?? null;
            if (!is_string($key)) {
                continue;
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
                $loincList = $decoded['loinc'] ?? [];
                if (is_array($loincList)) {
                    foreach ($loincList as $loinc) {
                        if (is_string($loinc)) {
                            $loincToAnalyte[$loinc] = $analyte;
                        }
                    }
                }
            } elseif (str_starts_with($key, 'unit:')) {
                $unitConfigs[substr($key, strlen('unit:'))] = $decoded;
            } elseif (str_starts_with($key, 'threshold:')) {
                $thresholds[substr($key, strlen('threshold:'))] = $decoded;
            }
        }

        return new self($loincToAnalyte, $unitConfigs, $thresholds);
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
     * @return array<string, mixed>|null
     */
    public function unitConfig(string $analyte): ?array
    {
        return $this->unitConfigs[$analyte] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function thresholdConfig(string $analyte): ?array
    {
        return $this->thresholds[$analyte] ?? null;
    }
}
