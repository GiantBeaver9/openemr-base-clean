<?php

/**
 * RateLimitConfig — the versioned per-session / per-user limits (§3.7).
 *
 * Initial values live in mod_copilot_cadence config rows and are loaded into this
 * immutable object at the request boundary; the pure decision logic (RateLimiter) reads
 * it, never the DB. Defaults mirror the spec: 1 active turn + 30 turns per session,
 * 3 active sessions + 60 turns/hour per user.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final readonly class RateLimitConfig
{
    public function __construct(
        public int $maxActiveTurnsPerSession = 1,
        public int $maxTurnsPerSession = 30,
        public int $maxActiveSessionsPerUser = 3,
        public int $maxTurnsPerUserPerHour = 60,
    ) {
    }
}
