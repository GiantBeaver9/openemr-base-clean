<?php

/**
 * CheckId — the closed, ordered set of verification checks (ARCHITECTURE.md §2.2).
 *
 * The order is load-bearing: the verifier runs V1→V6 and records every one, so a verdict always
 * carries a complete per-check ledger for audit and for building the regeneration prompt.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

enum CheckId: string
{
    case V1SchemaGate = 'V1';
    case V2CitationResolution = 'V2';
    case V3PatientIdentityGuard = 'V3';
    case V4NumericGrounding = 'V4';
    case V5BannedClaimLint = 'V5';
    case V6ConflictPassthrough = 'V6';

    public function label(): string
    {
        return match ($this) {
            self::V1SchemaGate => 'schema gate',
            self::V2CitationResolution => 'citation resolution',
            self::V3PatientIdentityGuard => 'patient identity guard',
            self::V4NumericGrounding => 'numeric grounding',
            self::V5BannedClaimLint => 'banned-claim lint',
            self::V6ConflictPassthrough => 'conflict passthrough',
        };
    }
}
