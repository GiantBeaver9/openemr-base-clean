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
 *   2. Free text is kept only as clinical KEYWORDS, filtered hard:
 *        - any token containing a digit is dropped (MRN, SSN, phone, dates,
 *          "9.4", "500mg" — the value is PHI or irrelevant to guideline lookup),
 *        - any token containing "@" is dropped (emails),
 *        - any mixed-case Capitalized token is dropped (likely a proper noun /
 *          patient or provider name); lowercase clinical words ("cholesterol")
 *          and ALL-CAPS acronyms ("LDL", "TSH") survive,
 *        - stopwords and sub-3-character noise are dropped.
 *
 * The result is a space-joined bag of safe keywords (possibly empty). It is a
 * conservative filter, deliberately biased toward dropping a borderline term
 * rather than leaking one — recall loss on the external store is acceptable; a
 * PHI leak to a non-BAA database is not.
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

    /** Common English/question stopwords that carry no retrieval signal. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'why', 'what', 'how', 'was', 'were', 'are',
        'his', 'her', 'their', 'this', 'that', 'from', 'has', 'have', 'had',
        'should', 'would', 'could', 'about', 'into', 'out', 'off', 'per',
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
        if ($this->looksLikeProperNoun($trimmed)) {
            return null; // "Jane", "Dr", "Smith" — keep names off the external store
        }

        $keyword = strtolower($trimmed);
        $keyword = preg_replace('/[^a-z-]/', '', $keyword) ?? '';
        $keyword = trim($keyword, '-');

        if (mb_strlen($keyword) < 3) {
            return null;
        }
        if (in_array($keyword, self::STOPWORDS, true)) {
            return null;
        }

        return $keyword;
    }

    /**
     * A mixed-case Capitalized token (first letter upper, at least one later
     * letter lower) reads as a proper noun. ALL-CAPS acronyms (LDL, TSH, HbA1c
     * screened out earlier by the digit rule) and all-lowercase clinical terms
     * are NOT proper nouns and pass.
     */
    private function looksLikeProperNoun(string $token): bool
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $token) ?? '';
        if (mb_strlen($letters) < 2) {
            return false;
        }

        return ctype_upper($letters[0]) && $letters !== strtoupper($letters);
    }
}
