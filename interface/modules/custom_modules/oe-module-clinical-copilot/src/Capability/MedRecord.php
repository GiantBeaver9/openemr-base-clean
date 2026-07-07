<?php

/**
 * MedRecord — one medication row from the T4 union (`prescriptions` OR `lists`).
 *
 * In-house scripts land in `prescriptions`; externally-reported / reconciled meds land in
 * `lists` (type=medication) — the outside metformin an endocrinologist must not miss. This
 * carrier keeps its `sourceTable` so a med_event can cite exactly where it came from, and so
 * a duplicate present in both tables can be reconciled to one med with a visible exclusion
 * for the dropped row (I5). Plain and immutable: MedResponse interprets it.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

final readonly class MedRecord
{
    public function __construct(
        public string $sourceTable, // 'prescriptions' | 'lists'
        public int $id,
        public string $drug,        // raw label (may include dose, e.g. "Metformin 500mg")
        public string $dosage,      // '' allowed
        public ?string $startDate,
        public bool $active,
    ) {
    }

    /**
     * Normalized drug identity for the app-maintained cross-table reconciliation link:
     * lowercased, dose/unit tokens stripped, whitespace collapsed. "Metformin" and
     * "Metformin 500mg" both normalize to "metformin" so the same drug in both tables
     * de-duplicates to one med (L2).
     */
    public function normalizedDrug(): string
    {
        $name = strtolower($this->drug);
        // Drop dose/unit tokens (e.g. "500mg", "5 mg", "100 unit/ml", bare numbers).
        $name = preg_replace('/\b\d+(\.\d+)?\s*(mg|mcg|g|ml|units?|unit\/ml|iu|%)\b/i', ' ', $name) ?? $name;
        $name = preg_replace('/\b\d+(\.\d+)?\b/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return trim($name);
    }
}
