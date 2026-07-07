<?php

/**
 * CadenceConfigReader — reads the versioned config rows the breaker / rate limits use.
 *
 * mod_copilot_cadence holds clinical cadence AND the observability limits (breaker caps,
 * rate-limit counts, manual breaker state). This interface exposes a typed getter surface
 * over the latest-version row per config_key, with a Db impl (CadenceConfigStore) and an
 * array impl (ArrayCadenceConfig) for isolated tests. Version is a digest input (E5), so
 * callers read the *current* version's value.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

interface CadenceConfigReader
{
    public function getString(string $configKey, string $default): string;

    public function getInt(string $configKey, int $default): int;

    public function getFloat(string $configKey, float $default): float;
}
