<?php

/**
 * AgentOutcome — the terminal state of one chat turn's agent loop (§1, §6.2).
 *
 *  - Answered:  verification passed; the rehydrated, cited prose is shown.
 *  - FactsOnly: the LLM was unavailable, verification failed twice, or the tool budget was
 *               exhausted — the answer degrades to the retrieved facts rendered as cited tables
 *               ("here are the facts I retrieved"). The facts panel is always the failsafe (I6/I11).
 *  - Frozen:    a V3 patient-identity guard trip (SEV-1) — the response is discarded, the session
 *               is frozen, and the conversation is not continued (§2.3).
 *
 * There is deliberately no "show anyway" state — unverified prose is never rendered.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

enum AgentOutcome: string
{
    case Answered = 'answered';
    case FactsOnly = 'facts_only';
    case Frozen = 'frozen';
}
