<?php

/**
 * ReadOutcome — the closed set of terminal states the synthesis read path can reach.
 *
 * Every synthesisFor() call ends in exactly one of these. The doc page renders facts-first in
 * ALL of them (I6): a narrative is a bonus the physician may or may not get, never a precondition
 * for seeing the data.
 *
 *  - CacheHit   the (pid, digest) address already held a verified doc; its narrative is served.
 *  - Generated  a fresh reduce passed verification; the narrative was built, stored, and served.
 *  - FactsOnly  no narrative — LLM unavailable after retries (I6) or verification discarded it (I11).
 *  - Paused     a capability threw during extraction (§6.1): no digest, no ledger write, surviving
 *               facts render under a named banner.
 *  - Frozen     the SEV-1 patient-identity guard (V3) tripped: facts-only plus a sev-1 signal.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

enum ReadOutcome: string
{
    case CacheHit = 'cache_hit';
    case Generated = 'generated';
    case FactsOnly = 'facts_only';
    case Paused = 'paused';
    case Frozen = 'frozen';

    /**
     * Whether this outcome carries a served narrative. False ⇒ the page renders facts-only.
     */
    public function hasNarrative(): bool
    {
        return match ($this) {
            self::CacheHit, self::Generated => true,
            self::FactsOnly, self::Paused, self::Frozen => false,
        };
    }
}
