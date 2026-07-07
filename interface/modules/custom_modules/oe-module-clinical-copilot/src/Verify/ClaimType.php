<?php

/**
 * ClaimType — the closed enum every model-emitted claim declares (ARCHITECTURE.md §2.1).
 *
 * The four zero-citation types (greeting, refusal, retrieval_status, uncertainty_statement)
 * are the ONLY types V2 permits without a citation, and even then only when the claim text is
 * not lexically clinical (any analyte/medication/number/date/patient-attribute makes a claim
 * clinical regardless of its declared type — see Verifier V2). Every other type is clinical and
 * MUST cite.
 *
 * The set is deliberately a superset of the provider response schema (Reduce/PromptAssembler):
 * whatever the model can legally emit must parse here, or V1 would reject a well-formed answer.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

enum ClaimType: string
{
    // Zero-citation-allowed types (only these, and only when not lexically clinical).
    case Greeting = 'greeting';
    case Refusal = 'refusal';
    case RetrievalStatus = 'retrieval_status';
    case UncertaintyStatement = 'uncertainty_statement';

    // Clinical types — every one of these MUST carry >=1 resolving citation (V2).
    case Result = 'result';
    case Trend = 'trend';
    case MedEvent = 'med_event';
    case Overdue = 'overdue';
    case Pending = 'pending';
    case Comparison = 'comparison';
    case Summary = 'summary';
    case Observation = 'observation';
    case Conflict = 'conflict';
    case ExclusionNote = 'exclusion_note';

    /**
     * True only for the four types allowed to carry zero citations. The lexical clinical
     * re-check in V2 can still force one of these to cite (an "uncertainty_statement" that
     * names an analyte and a number is clinical prose in disguise).
     */
    public function allowsZeroCitations(): bool
    {
        return match ($this) {
            self::Greeting,
            self::Refusal,
            self::RetrievalStatus,
            self::UncertaintyStatement => true,
            default => false,
        };
    }
}
