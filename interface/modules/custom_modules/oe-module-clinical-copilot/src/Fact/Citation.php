<?php

/**
 * Citation — an immutable pointer from a fact to the exact chart row it came from.
 *
 * Every fact carries >=1 citation; the verifier (V2) resolves each one, and the UI
 * renders it as a click-through to the underlying record. Citations participate in
 * the fact_id hash and therefore in the digest.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final readonly class Citation
{
    public function __construct(
        public string $table,          // e.g. procedure_result, prescriptions, lists, form_vitals
        public int $pk,                // primary key of the cited row
        public ?string $field,         // specific column, when relevant
        public DateSource $dateSource, // whether the fact's date came from a collected or fallback source
    ) {
    }

    /**
     * Canonical associative form for serialization/digest. Key order is fixed here.
     *
     * @return array{table: string, pk: int, field: string|null, date_source: string}
     */
    public function toCanonical(): array
    {
        return [
            'table' => $this->table,
            'pk' => $this->pk,
            'field' => $this->field,
            'date_source' => $this->dateSource->value,
        ];
    }
}
