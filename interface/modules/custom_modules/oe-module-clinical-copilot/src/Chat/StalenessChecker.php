<?php

/**
 * StalenessChecker — the cheap per-turn drift check (T19, §1.1).
 *
 * Fact re-extraction is the inexpensive step (T5's economics; no LLM), so every turn recomputes
 * the pinned patient's facts + digest and compares to the session's seed digest. On a match the
 * turn proceeds normally; on drift it still answers, under a disclosed banner, with no auto
 * re-seed. This never mutates the session — re-seeding is the physician's explicit one-click action.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;

final class StalenessChecker
{
    public function __construct(private readonly SeedBuilder $seedBuilder = new SeedBuilder())
    {
    }

    /**
     * Recompute the pinned patient's digest and compare it to the seed digest.
     */
    public function check(CapabilityFactory $factory, int $pid, string $seedDigest): StalenessResult
    {
        $facts = $this->seedBuilder->buildFactSet($factory, $pid);
        $current = $this->seedBuilder->digestFor($factory, $facts);
        return new StalenessResult($current !== $seedDigest, $seedDigest, $current);
    }
}
