<?php

/**
 * RateLimiterStore — thin DB adapter that gathers rate-limit counts + config (§3.7).
 *
 * Counts come from the module's own append-only chat tables (read-only SELECTs). The
 * caller supplies the in-flight-turn count, since "one active turn per session" is owned
 * by U11's turn lifecycle/lock, not derivable from the append-only ledger alone. Config
 * (the four limits) comes from mod_copilot_cadence with spec defaults. The decision itself
 * is the pure, isolated-tested RateLimiter — this class is only I/O.
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

final class RateLimiterStore
{
    public const KEY_SESSION_ACTIVE = 'ratelimit:per_session_active';
    public const KEY_SESSION_TURNS = 'ratelimit:per_session_max_turns';
    public const KEY_USER_SESSIONS = 'ratelimit:per_user_max_sessions';
    public const KEY_USER_HOUR_TURNS = 'ratelimit:per_user_turns_per_hour';

    public function __construct(private readonly CadenceConfigReader $config)
    {
    }

    public function config(): RateLimitConfig
    {
        $defaults = new RateLimitConfig();
        return new RateLimitConfig(
            $this->config->getInt(self::KEY_SESSION_ACTIVE, $defaults->maxActiveTurnsPerSession),
            $this->config->getInt(self::KEY_SESSION_TURNS, $defaults->maxTurnsPerSession),
            $this->config->getInt(self::KEY_USER_SESSIONS, $defaults->maxActiveSessionsPerUser),
            $this->config->getInt(self::KEY_USER_HOUR_TURNS, $defaults->maxTurnsPerUserPerHour),
        );
    }

    /**
     * Gather current counts. $activeTurnsInSession is the in-flight-turn count from U11's
     * turn lock (0 or 1 in normal operation).
     */
    public function gather(int $sessionId, int $userId, int $activeTurnsInSession): RateLimitCounts
    {
        $turnsInSession = $this->count(
            "SELECT COUNT(*) AS c FROM mod_copilot_chat_turn WHERE session_id = ? AND role = 'user'",
            [$sessionId],
        );
        $activeSessionsForUser = $this->count(
            "SELECT COUNT(*) AS c FROM mod_copilot_chat_session WHERE user_id = ? AND status = 'active'",
            [$userId],
        );
        $turnsForUserThisHour = $this->count(
            "SELECT COUNT(*) AS c
             FROM mod_copilot_chat_turn t
             JOIN mod_copilot_chat_session s ON s.id = t.session_id
             WHERE s.user_id = ? AND t.role = 'user' AND t.created_at >= (NOW() - INTERVAL 1 HOUR)",
            [$userId],
        );

        return new RateLimitCounts(
            $activeTurnsInSession,
            $turnsInSession,
            $activeSessionsForUser,
            $turnsForUserThisHour,
        );
    }

    /**
     * @param list<int|string> $binds
     */
    private function count(string $sql, array $binds): int
    {
        $value = QueryUtils::fetchSingleValue($sql, 'c', $binds);
        return is_numeric($value) ? (int) $value : 0;
    }
}
