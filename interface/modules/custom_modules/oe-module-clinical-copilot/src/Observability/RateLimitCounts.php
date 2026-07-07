<?php

/**
 * RateLimitCounts — the current usage counts a rate-limit decision is made against.
 *
 * Gathered from the module's own append-only tables (chat_session / chat_turn) at the
 * request boundary and handed to the pure RateLimiter. Kept as a value object so the
 * decision logic takes typed counts, never four bare ints in ambiguous order.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final readonly class RateLimitCounts
{
    public function __construct(
        public int $activeTurnsInSession,
        public int $turnsInSession,
        public int $activeSessionsForUser,
        public int $turnsForUserThisHour,
    ) {
    }
}
