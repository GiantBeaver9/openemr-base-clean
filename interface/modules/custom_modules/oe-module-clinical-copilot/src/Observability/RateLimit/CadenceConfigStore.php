<?php

/**
 * Read/write access to mod_copilot_cadence config rows used by rate limiting.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmEnv;

/**
 * `mod_copilot_cadence` is the one module ledger where UPDATE is permitted in
 * code -- config only (table.sql: "I3 exempts config rows, not the ledgers").
 * This class reads the `rate_limit_breaker` (limits, versioned) and
 * `rate_limit_breaker_state` (the one manual-override mutable flag, §3.7)
 * code_set rows, and is the only place either is written from application
 * code (besides the seed rows in table.sql/install.sql).
 */
final class CadenceConfigStore
{
    private const LIMITS_CODE_SET = 'rate_limit_breaker';
    private const STATE_CODE_SET = 'rate_limit_breaker_state';
    private const LOAD_TEST_CODE_SET = 'load_test_mode';

    /** The per-user chat caps' value while load-test mode is active. */
    private const LOAD_TEST_UNCAPPED = 1_000_000;

    /** Hard bound on how long load-test mode can be enabled for (minutes). */
    private const LOAD_TEST_MAX_MINUTES = 240;

    /**
     * @return array{
     *     max_requests_per_minute: int, breaker_error_threshold: int,
     *     breaker_window_seconds: int, breaker_cooldown_seconds: int,
     *     max_active_sessions_per_user: int, max_turns_per_user_per_hour: int,
     *     daily_llm_spend_cap_usd: float, hourly_llm_burn_cap_usd: float,
     *     per_tick_worker_llm_budget_usd: float
     * }
     */
    public function limits(): array
    {
        $limits = self::resolveLimits($this->loadConfigJson(self::LIMITS_CODE_SET));

        // Load-test mode (temporary, auto-reverting): lift the PER-USER chat
        // throttle so a burst/load test is not rate-limited. The daily/hourly $
        // spend caps and the circuit breaker deliberately stay ON as the hard
        // cost backstop, and the lift disappears the moment the window expires
        // ({@see self::loadTestModeActive()}) — no cron, no manual reset needed.
        if ($this->loadTestModeActive()) {
            $limits['max_active_sessions_per_user'] = self::LOAD_TEST_UNCAPPED;
            $limits['max_turns_per_user_per_hour'] = self::LOAD_TEST_UNCAPPED;
        }

        return $limits;
    }

    /**
     * True while load-test mode is enabled AND its window has not expired.
     * Expiry is checked at READ time, so the lift auto-reverts with no cron.
     */
    public function loadTestModeActive(?\DateTimeImmutable $now = null): bool
    {
        return self::isLoadTestActive($this->loadConfigJson(self::LOAD_TEST_CODE_SET), $now ?? new \DateTimeImmutable());
    }

    /**
     * Pure expiry check (no I/O) so the auto-revert semantics are unit-testable.
     * Active only when the stored flag is true AND now is before expires_at.
     *
     * @param array<string, mixed> $config
     */
    public static function isLoadTestActive(array $config, \DateTimeImmutable $now): bool
    {
        if (($config['active'] ?? false) !== true) {
            return false;
        }
        $expiresAt = is_string($config['expires_at'] ?? null) ? $config['expires_at'] : null;
        if ($expiresAt === null) {
            return false;
        }
        try {
            $expires = new \DateTimeImmutable($expiresAt);
        } catch (\Throwable) {
            return false;
        }

        return $now < $expires;
    }

    /**
     * Load-test-mode status for the dashboard.
     *
     * @return array{active: bool, expires_at: ?string, set_by: ?string, seconds_remaining: int}
     */
    public function loadTestMode(): array
    {
        $config = $this->loadConfigJson(self::LOAD_TEST_CODE_SET);
        $now = new \DateTimeImmutable();
        $active = self::isLoadTestActive($config, $now);
        $expiresAt = is_string($config['expires_at'] ?? null) ? $config['expires_at'] : null;

        $secondsRemaining = 0;
        if ($active && $expiresAt !== null) {
            try {
                $secondsRemaining = max(0, (new \DateTimeImmutable($expiresAt))->getTimestamp() - $now->getTimestamp());
            } catch (\Throwable) {
                $secondsRemaining = 0;
            }
        }

        return [
            'active' => $active,
            'expires_at' => $expiresAt,
            'set_by' => is_string($config['set_by'] ?? null) ? $config['set_by'] : null,
            'seconds_remaining' => $secondsRemaining,
        ];
    }

    /**
     * Enable load-test mode for a bounded window (clamped to
     * {@see self::LOAD_TEST_MAX_MINUTES}), after which it auto-reverts.
     */
    public function enableLoadTestMode(string $actor, int $durationMinutes): void
    {
        $durationMinutes = max(1, min(self::LOAD_TEST_MAX_MINUTES, $durationMinutes));
        $now = new \DateTimeImmutable();
        $this->upsertLoadTestState([
            'active' => true,
            'expires_at' => $now->add(new \DateInterval('PT' . $durationMinutes . 'M'))->format(DATE_ATOM),
            'set_by' => $actor,
            'set_at' => $now->format(DATE_ATOM),
        ]);
    }

    public function disableLoadTestMode(string $actor): void
    {
        $this->upsertLoadTestState([
            'active' => false,
            'expires_at' => null,
            'set_by' => $actor,
            'set_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    /**
     * Upsert the load-test-mode config row (it is not seeded, so INSERT-or-UPDATE
     * on the unique code_set).
     *
     * @param array<string, mixed> $config
     */
    private function upsertLoadTestState(array $config): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `mod_copilot_cadence` (`code_set`, `config_json`, `version`, `updated_at`)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `config_json` = VALUES(`config_json`), `updated_at` = VALUES(`updated_at`)',
            [
                self::LOAD_TEST_CODE_SET,
                json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'v1',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Resolve the effective limits from a config row, applying the precedence
     * **env var > DB config row > hard-coded default** for the four
     * operator-facing caps (the ones the dashboard shows). Deployment-level
     * caps belong in deployment config, so a site tunes cost/abuse limits in
     * its Railway/Apache/PHP-FPM environment rather than editing a seeded DB
     * row — while the seeded row (and these defaults) remain the fallback when
     * no env var is set. Pure over its input (env read aside) so it is unit
     * testable with no DB.
     *
     * @param array<string, mixed> $config
     * @return array{
     *     max_requests_per_minute: int, breaker_error_threshold: int,
     *     breaker_window_seconds: int, breaker_cooldown_seconds: int,
     *     max_active_sessions_per_user: int, max_turns_per_user_per_hour: int,
     *     daily_llm_spend_cap_usd: float, hourly_llm_burn_cap_usd: float,
     *     per_tick_worker_llm_budget_usd: float
     * }
     */
    public static function resolveLimits(array $config): array
    {
        return [
            'max_requests_per_minute' => (int)($config['max_requests_per_minute'] ?? 30),
            'breaker_error_threshold' => (int)($config['breaker_error_threshold'] ?? 5),
            'breaker_window_seconds' => (int)($config['breaker_window_seconds'] ?? 60),
            'breaker_cooldown_seconds' => (int)($config['breaker_cooldown_seconds'] ?? 120),
            'max_active_sessions_per_user' => self::envInt(
                'CLINICAL_COPILOT_MAX_ACTIVE_SESSIONS_PER_USER',
                (int)($config['max_active_sessions_per_user'] ?? 3),
            ),
            'max_turns_per_user_per_hour' => self::envInt(
                'CLINICAL_COPILOT_MAX_TURNS_PER_USER_PER_HOUR',
                (int)($config['max_turns_per_user_per_hour'] ?? 60),
            ),
            'daily_llm_spend_cap_usd' => self::envFloat(
                'CLINICAL_COPILOT_DAILY_LLM_SPEND_CAP_USD',
                (float)($config['daily_llm_spend_cap_usd'] ?? 50.0),
            ),
            'hourly_llm_burn_cap_usd' => self::envFloat(
                'CLINICAL_COPILOT_HOURLY_LLM_BURN_CAP_USD',
                (float)($config['hourly_llm_burn_cap_usd'] ?? 10.0),
            ),
            'per_tick_worker_llm_budget_usd' => (float)($config['per_tick_worker_llm_budget_usd'] ?? 2.0),
        ];
    }

    /**
     * A positive whole-number env override, else the fallback. A blank, non-
     * numeric, zero, or negative value falls back — a cap can never be silently
     * disabled by a mis-set variable.
     */
    private static function envInt(string $env, int $fallback): int
    {
        $raw = trim(LlmEnv::getString($env));

        return ($raw !== '' && ctype_digit($raw) && (int)$raw >= 1) ? (int)$raw : $fallback;
    }

    /** A positive numeric env override (dollars), else the fallback. */
    private static function envFloat(string $env, float $fallback): float
    {
        $raw = trim(LlmEnv::getString($env));

        return ($raw !== '' && is_numeric($raw) && (float)$raw > 0.0) ? (float)$raw : $fallback;
    }

    /**
     * @return array{forced_open: bool, forced_at: ?string, forced_by: ?string, forced_reason: ?string}
     */
    public function state(): array
    {
        $config = $this->loadConfigJson(self::STATE_CODE_SET);

        return [
            'forced_open' => (bool)($config['forced_open'] ?? false),
            'forced_at' => isset($config['forced_at']) && is_string($config['forced_at']) ? $config['forced_at'] : null,
            'forced_by' => isset($config['forced_by']) && is_string($config['forced_by']) ? $config['forced_by'] : null,
            'forced_reason' => isset($config['forced_reason']) && is_string($config['forced_reason']) ? $config['forced_reason'] : null,
        ];
    }

    /**
     * Manual trip -- ARCHITECTURE.md §3.7: "manual reset is ACL-gated and
     * audit-logged." The ACL check and audit-log entry are the CALLER's job
     * (the dashboard admin action, mirroring how {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LoggingAlertSink}
     * writes its own audit entry at the point of a privileged action) -- this
     * method only performs the persisted state change itself.
     */
    public function forceOpen(string $actor, string $reason = ''): void
    {
        $this->writeState([
            'forced_open' => true,
            'forced_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'forced_by' => $actor,
            'forced_reason' => $reason !== '' ? $reason : 'manually forced open',
        ]);
    }

    public function manualReset(string $actor): void
    {
        $this->writeState([
            'forced_open' => false,
            'forced_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'forced_by' => $actor,
            'forced_reason' => 'manual reset',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfigJson(string $codeSet): array
    {
        $raw = QueryUtils::fetchSingleValue(
            'SELECT `config_json` FROM `mod_copilot_cadence` WHERE `code_set` = ?',
            'config_json',
            [$codeSet],
        );

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeState(array $config): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `mod_copilot_cadence` SET `config_json` = ?, `updated_at` = ? WHERE `code_set` = ?',
            [
                json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                self::STATE_CODE_SET,
            ],
        );
    }
}
