<?php

/**
 * PatientContext — the direct identifiers that head a reduce prompt and MUST be redacted
 * before egress (ARCHITECTURE.md §4 boundary 3).
 *
 * These four fields — name, MRN, DOB, address — are the direct identifiers the module
 * replaces with per-session pseudonym tokens before any Vertex call. They are deliberately
 * kept OUT of the canonical fact bytes (which carry only pid + clinical values), so the
 * fact block in the prompt stays byte-identical to the digest input.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final readonly class PatientContext
{
    public function __construct(
        public int $pid,
        public ?string $name = null,
        public ?string $mrn = null,
        public ?string $dob = null,
        public ?string $address = null,
    ) {
    }

    /**
     * The direct identifiers present on this context, keyed by kind. Nulls and empties are
     * dropped — only real identifier strings become redaction tokens.
     *
     * @return array<string, string>
     */
    public function directIdentifiers(): array
    {
        $out = [];
        foreach (['name' => $this->name, 'mrn' => $this->mrn, 'dob' => $this->dob, 'address' => $this->address] as $kind => $value) {
            if ($value !== null && $value !== '') {
                $out[$kind] = $value;
            }
        }
        return $out;
    }
}
