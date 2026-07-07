<?php

/**
 * ArrayCadenceConfig — in-memory CadenceConfigReader for isolated tests (no DB).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class ArrayCadenceConfig implements CadenceConfigReader
{
    /**
     * @param array<string, string> $values config_key => config_value
     */
    public function __construct(private readonly array $values)
    {
    }

    public function getString(string $configKey, string $default): string
    {
        return $this->values[$configKey] ?? $default;
    }

    public function getInt(string $configKey, int $default): int
    {
        $raw = $this->values[$configKey] ?? null;
        if ($raw !== null && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        return $default;
    }

    public function getFloat(string $configKey, float $default): float
    {
        $raw = $this->values[$configKey] ?? null;
        if ($raw !== null && is_numeric($raw)) {
            return (float) $raw;
        }
        return $default;
    }
}
