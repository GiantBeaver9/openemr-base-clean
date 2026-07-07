<?php

/**
 * SessionSeed — the exact content a chat session is preloaded with (§1.1).
 *
 * Turn 1 needs ZERO retrieval because the program has already pulled all five capabilities: this
 * carries that pre-pull (the pinned FactSet), the verified narrative the physician is reading, and
 * the fact digest that content-addresses them. The digest is pinned onto the session row so every
 * later turn can cheaply detect mid-conversation drift (T19) without re-seeding.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;

final readonly class SessionSeed
{
    public function __construct(
        public FactSet $facts,
        public string $narrative,
        public string $factDigest,
    ) {
    }
}
