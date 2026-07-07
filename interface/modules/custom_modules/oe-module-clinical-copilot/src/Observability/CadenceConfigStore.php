<?php

/**
 * CadenceConfigStore — DB-backed CadenceConfigReader over mod_copilot_cadence (§3.7).
 *
 * Reads the latest-version row for a config_key (highest id wins as the current version).
 * Also owns the one narrow write this module makes to config: the manual breaker state
 * (`breaker:manual_state`), which is module-owned data — the ACL check + audit log live at
 * the call site (CircuitBreakerStore::manualOverride). All reads are parameterized SELECTs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

use OpenEMR\Common\Database\QueryUtils;

final class CadenceConfigStore implements CadenceConfigReader
{
    public function getString(string $configKey, string $default): string
    {
        $sql = "SELECT config_value
                FROM mod_copilot_cadence
                WHERE config_key = ?
                ORDER BY id DESC
                LIMIT 1";
        $value = QueryUtils::fetchSingleValue($sql, 'config_value', [$configKey]);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return $default;
    }

    public function getInt(string $configKey, int $default): int
    {
        $raw = $this->getString($configKey, '');
        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : $default;
    }

    public function getFloat(string $configKey, float $default): float
    {
        $raw = $this->getString($configKey, '');
        return is_numeric($raw) ? (float) $raw : $default;
    }

    /**
     * Persist the manual breaker override. Module-owned table, so the write is permitted;
     * the ACL gate + audit log are the caller's responsibility.
     */
    public function setManualBreakerState(BreakerState $state, string $version): void
    {
        $sql = "INSERT INTO mod_copilot_cadence (config_key, config_value, version, updated_at)
                VALUES ('breaker:manual_state', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()";
        QueryUtils::sqlStatementThrowException($sql, [$state->value, $version]);
    }
}
