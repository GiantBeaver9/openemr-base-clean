<?php

/**
 * Reduces a retrieval query to non-PHI terms before it crosses to the external store.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

/**
 * The segregation boundary, in code. The knowledge Postgres is a non-BAA store,
 * so nothing patient-identifying may travel to it — not at rest (the corpus is
 * PHI-free by construction) and not in transit (this class). A retrieval query
 * can be a raw chat question ("why is Jane's A1c 9.4 on 3/2?"), so we do NOT
 * forward it verbatim. Instead:
 *
 *   1. The structured analyte/topic {@see $tags} are the PRIMARY signal — they
 *      are derived deterministically from the chart's out-of-range facts and are
 *      non-PHI by construction ("a1c", "ldl", "tsh"). They are always kept.
 *   2. Free text is kept only as clinical KEYWORDS, on an ALLOWLIST — a token
 *      survives only if it is a recognized clinical term (an analyte code like
 *      "a1c"/"b12", or a word in {@see self::CLINICAL_TERMS}). Everything else is
 *      dropped, including any name. This is deliberately an allowlist, not a
 *      "drop things that look like names" blacklist: a blacklist keyed on
 *      capitalization let a lowercase name ("why is jane's a1c high" → "janes")
 *      leak straight through to the non-BAA store. With an allowlist, an
 *      unrecognized token — lowercase name, nickname, misspelling, free-text PHI
 *      of any shape — cannot pass, because it is not on the list.
 *
 * The result is a space-joined bag of safe keywords (possibly empty). Recall on
 * the external store is bounded to the clinical vocabulary + the structured tags,
 * which is the intended trade: recall loss is acceptable; a PHI leak to a non-BAA
 * database is not.
 */
final class KnowledgeQueryScrubber
{
    /**
     * Analyte / lab codes that legitimately mix letters and digits and carry NO
     * PHI: a1c, hba1c, b12, t4, o2, spo2, sglt2, glp1, d3, covid19. Shape: 1-6
     * letters, then 1-3 digits, then an optional trailing letter. A patient
     * identifier or value never fits this (it starts with a digit, has too many
     * digits, or contains separators), so these are kept while "9.4", "55",
     * "3/2", and "2024-01-01" are dropped.
     */
    private const ANALYTE_CODE = '/^[a-z]{1,6}\d{1,3}[a-z]?$/i';

    /**
     * The free-text allowlist: a plain lowercase word survives only if it is one
     * of these recognized, non-PHI clinical/care terms. Anything else is dropped
     * — a name in any casing is not on the list, so it cannot leak. Scoped to the
     * endocrinology/diabetes domain the module serves plus general lab/care
     * vocabulary; recall is intentionally bounded to this set + the structured
     * tags (see the class docblock). Extend here as the domain grows.
     *
     * @var list<string>
     */
    private const CLINICAL_TERMS = [
        // analytes / labs
        'a1c', 'hba1c', 'glucose', 'cholesterol', 'ldl', 'hdl', 'lipid', 'lipids',
        'triglyceride', 'triglycerides', 'acr', 'microalbumin', 'albumin', 'creatinine',
        'egfr', 'gfr', 'potassium', 'sodium', 'tsh', 'thyroid', 'vitamin', 'ferritin',
        'hemoglobin', 'hematocrit', 'platelet', 'platelets', 'weight', 'bmi', 'pressure',
        'blood', 'panel', 'level', 'levels', 'range', 'reference', 'value', 'result', 'results',
        // conditions
        'diabetes', 'diabetic', 'hypertension', 'hyperlipidemia', 'dyslipidemia',
        'kidney', 'renal', 'nephropathy', 'retinopathy', 'neuropathy', 'cardiovascular',
        'obesity', 'prediabetes', 'ckd',
        // medications / classes
        'metformin', 'insulin', 'glipizide', 'glyburide', 'sitagliptin', 'empagliflozin',
        'dapagliflozin', 'liraglutide', 'semaglutide', 'sulfonylurea', 'sglt2', 'statin',
        'statins', 'aspirin', 'dose', 'dosage', 'therapy', 'medication', 'medications',
        // care / guideline vocabulary (generic, never patient-identifying)
        'target', 'targets', 'goal', 'goals', 'guideline', 'guidelines', 'screening',
        'monitoring', 'management', 'control', 'treatment', 'fasting', 'abnormal',
        'elevated', 'high', 'low', 'overdue', 'followup', 'recommendation',
        'recommendations', 'reasonable', 'threshold',
    ];

    /**
     * @param list<string> $tags analyte/topic tags (non-PHI by construction)
     *
     * @return string a space-joined bag of safe keywords; may be empty
     */
    public function scrub(string $rawQuery, array $tags): string
    {
        /** @var array<string, true> $kept ordered set (preserves first-seen order) */
        $kept = [];

        foreach ($tags as $tag) {
            $normalized = $this->normalizeTag($tag);
            if ($normalized !== '') {
                $kept[$normalized] = true;
            }
        }

        foreach (preg_split('/\s+/', trim($rawQuery)) ?: [] as $token) {
            $safe = $this->safeKeyword($token);
            if ($safe !== null) {
                $kept[$safe] = true;
            }
        }

        return implode(' ', array_keys($kept));
    }

    private function normalizeTag(string $tag): string
    {
        // Tags are trusted non-PHI, but normalize to the shared canonical shape so
        // they collapse against free-text keywords and match the stored tags.
        return TagNormalizer::normalize($tag);
    }

    private function safeKeyword(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        if (str_contains($token, '@')) {
            return null; // email
        }

        // Strip surrounding punctuation but keep the original casing for the
        // analyte-code and proper-noun tests below.
        $trimmed = trim($token, ".,;:!?()[]{}\"'`");
        if ($trimmed === '') {
            return null;
        }

        // Analyte codes come first: they carry a digit but are non-PHI clinical
        // terms ("A1c", "B12"), so they must survive the digit filter — even when
        // capitalized like a proper noun.
        if (preg_match(self::ANALYTE_CODE, $trimmed) === 1) {
            return strtolower($trimmed);
        }

        if (preg_match('/\d/', $trimmed) === 1) {
            return null; // MRN, SSN, phone, date, lab value, dosage
        }

        $keyword = strtolower($trimmed);
        $keyword = preg_replace('/[^a-z-]/', '', $keyword) ?? '';
        $keyword = trim($keyword, '-');

        if (mb_strlen($keyword) < 3) {
            return null;
        }

        // Allowlist, not blacklist: a plain word crosses to the non-BAA store
        // ONLY if it is a recognized clinical term. Anything unrecognized — a
        // name in any casing, a nickname, a misspelling — is dropped, so free
        // text cannot leak PHI regardless of how it was typed.
        return in_array($keyword, self::CLINICAL_TERMS, true) ? $keyword : null;
    }
}
