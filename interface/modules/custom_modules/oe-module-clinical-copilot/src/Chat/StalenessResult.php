<?php

/**
 * StalenessResult — the outcome of the per-turn mid-conversation drift check (T19).
 *
 * `stale` is true when the chart has changed since the session seeded (the freshly recomputed
 * digest differs from the pinned seed digest). Staleness is DISCLOSED, never silent (the same
 * honesty rule as I5): the turn still answers, but under a visible "chart changed — refresh to
 * re-seed" banner. There is no auto re-seed — spending a full re-pull every turn is cost without
 * benefit (T19); tool results are live regardless (I2).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final readonly class StalenessResult
{
    public function __construct(
        public bool $stale,
        public string $seedDigest,
        public string $currentDigest,
    ) {
    }

    public const BANNER = 'The chart has changed since this summary — refresh to re-seed.';
}
