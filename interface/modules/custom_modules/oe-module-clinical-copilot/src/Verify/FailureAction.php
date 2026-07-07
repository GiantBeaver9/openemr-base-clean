<?php

/**
 * FailureAction — the fail-closed policy the verifier hands the caller (ARCHITECTURE.md §2.3).
 *
 * The verifier decides the KIND of outcome; the read/chat path (I11) acts on it. There is no
 * fourth "show anyway" option — an unverified narrative is never rendered.
 *
 *  - Pass       render the model narrative (all checks passed).
 *  - Regenerate one, and only one, regeneration with the verdict's findings appended to the prompt.
 *  - Discard    drop the narrative; synthesis falls back to facts-only ("narrative unavailable"),
 *               chat to "I couldn't produce a verifiable answer — here are the facts I retrieved."
 *  - Freeze     the SEV-1 path: a V3 (patient identity) failure. NOT a retry — discard the
 *               response, freeze the session, alert (§3.5), audit-log. Something is wrong upstream
 *               of the LLM and continuing the conversation is the wrong move.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

enum FailureAction: string
{
    case Pass = 'pass';
    case Regenerate = 'regenerate';
    case Discard = 'discard';
    case Freeze = 'freeze';
}
