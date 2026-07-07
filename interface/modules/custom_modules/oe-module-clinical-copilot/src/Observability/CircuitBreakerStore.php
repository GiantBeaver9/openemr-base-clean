<?php

/**
 * CircuitBreakerStore — thin DB adapter around the pure CircuitBreaker (§3.7).
 *
 * Reads the caps + manual override from mod_copilot_cadence, sums windowed LLM spend from
 * mod_copilot_trace (calendar day + calendar hour, so a window rollover auto-resets the
 * breaker with no extra state), then delegates the decision to CircuitBreaker::evaluate.
 * The state-transition logic itself is pure and isolated-tested; this class is only I/O.
 * `currentDecision()` is what the LLM call path checks before every request.
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
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;

final class CircuitBreakerStore
{
    public const KEY_DAILY_CAP = 'breaker:daily_cap_usd';
    public const KEY_HOURLY_CAP = 'breaker:hourly_cap_usd';
    public const KEY_MANUAL_STATE = 'breaker:manual_state';
    public const CONFIG_VERSION = 'breaker@1';

    private const DEFAULT_DAILY_CAP = 50.0;
    private const DEFAULT_HOURLY_CAP = 10.0;

    public function __construct(
        private readonly CadenceConfigReader $config,
        private readonly ?SystemLogger $logger = null,
    ) {
    }

    /**
     * Resolve the breaker state right now (checked before every LLM call).
     */
    public function currentDecision(): BreakerDecision
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s.v');
        $hourStart = $now->setTime((int) $now->format('H'), 0, 0)->format('Y-m-d H:i:s.v');

        $dailySpend = $this->spendSince($dayStart);
        $hourlySpend = $this->spendSince($hourStart);

        $dailyCap = $this->config->getFloat(self::KEY_DAILY_CAP, self::DEFAULT_DAILY_CAP);
        $hourlyCap = $this->config->getFloat(self::KEY_HOURLY_CAP, self::DEFAULT_HOURLY_CAP);

        $manual = BreakerState::tryFrom($this->config->getString(self::KEY_MANUAL_STATE, BreakerState::Closed->value))
            ?? BreakerState::Closed;

        return CircuitBreaker::evaluate($dailySpend, $hourlySpend, $dailyCap, $hourlyCap, $manual);
    }

    /**
     * Manual reset (or trip) of the breaker. ACL is enforced at the page boundary; this
     * records the audit trail (§3.7 — manual reset is ACL-gated and audit-logged).
     */
    public function manualOverride(BreakerState $state, int $userId): void
    {
        if (!$this->config instanceof CadenceConfigStore) {
            return;
        }
        $this->config->setManualBreakerState($state, self::CONFIG_VERSION);

        EventAuditLogger::getInstance()->newEvent(
            'copilot-breaker-' . $state->value,
            (string) $userId,
            '',
            1,
            'Clinical Co-Pilot circuit breaker manual override: ' . $state->value,
        );
        $this->logger?->info('Clinical Co-Pilot breaker manual override', [
            'state' => $state->value,
            'user_id' => $userId,
        ]);
    }

    private function spendSince(string $sinceIso): float
    {
        $sql = "SELECT COALESCE(SUM(cost_usd), 0) AS spend
                FROM mod_copilot_trace
                WHERE started_at >= ?";
        $value = QueryUtils::fetchSingleValue($sql, 'spend', [$sinceIso]);
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
