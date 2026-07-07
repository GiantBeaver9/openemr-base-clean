<?php

/**
 * BannedLexicon — the version-pinned lexicon behind V2's clinical re-check and V5's banned-claim
 * lint (ARCHITECTURE.md §2.2, §2.4).
 *
 * Everything here is a deterministic, closed constant set — NO general medical dictionary, NO
 * inference. The version string (VERSION) is recorded on every verdict so a lexicon change is a
 * visible, auditable event and a stored verdict can be re-interpreted against the exact rules that
 * produced it.
 *
 * V5 rejects the LEXICAL class only: causation/recommendation/diagnosis/dosage/interaction phrased
 * WITH one of these trigger words. Paraphrase that asserts the same thing without a trigger word
 * ("the rise tracks the missed refills") is a stated residual (§2.4) — hunted by adversarial evals,
 * not deterministically blocked here. Do not treat this list as complete coverage of the intent.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

final class BannedLexicon
{
    public const VERSION = 'v5-lexicon@1';

    /**
     * V5 banned pattern classes. Each value is a list of case-insensitive PCRE fragments (already
     * anchored with word boundaries where a bare substring would over-match). The key is the class
     * name surfaced in findings. Order is fixed for deterministic finding output.
     *
     * causation: over med<->lab pairings per §2.2, but applied unconditionally here — USERS.md §1
     *   bans causal assertions outright ("A1c rose after the dose change" is fact and uses the
     *   permitted temporal "after"; "because of" is banned). Temporal words are deliberately absent.
     *
     * @var array<string, list<string>>
     */
    public const BANNED = [
        'causation' => [
            '\bbecause\b',
            '\bdue to\b',
            '\bcaused? by\b',
            '\bcaused\b',
            '\bled to\b',
            '\bleads? to\b',
            '\battributable to\b',
            '\battributed to\b',
            '\btracks?\b',
            '\bresulted? in\b',
            '\bexplains? the\b',
        ],
        'recommendation' => [
            '\bshould\s+(start|increase|decrease|stop|switch|titrate|adjust|change|hold|discontinue|begin|lower|raise|reduce|add)\b',
            '\b(recommend|recommended|recommending|recommendation)\b',
            '\b(suggest|consider)\s+(start|increas|decreas|stop|switch|titrat|adjust|chang|hold|discontinu|add|lower|rais|reduc)',
            '\btitrate\b',
            '\bup-?titrate\b',
        ],
        'diagnosis' => [
            '\bdiagnos(is|e|ed|tic)\b',
            '\bconsistent with\b',
            '\bindicative of\b',
            '\bsuggestive of\b',
            '\brule out\b',
            '\br/o\b',
        ],
        'dosage' => [
            '\bincrease (the )?dose\b',
            '\bdecrease (the )?dose\b',
            '\blower (the )?dose\b',
            '\braise (the )?dose\b',
            '\breduce (the )?dose\b',
            '\badjust (the )?dose\b',
            '\bincrease to \d',
            '\bdecrease to \d',
        ],
        'interaction' => [
            '\binteracts? with\b',
            '\bdrug[- ]interaction\b',
            '\binteraction between\b',
            '\bcontraindicated\b',
            '\bpotentiates?\b',
        ],
    ];

    /**
     * Analyte terms — the closed set of lab analytes this module surfaces. A claim naming any of
     * these is clinical (V2) even if it declared itself a greeting.
     *
     * @var list<string>
     */
    public const ANALYTES = [
        'a1c', 'hba1c', 'hemoglobin a1c', 'glucose', 'fasting glucose',
        'ldl', 'hdl', 'cholesterol', 'triglycerides', 'lipid', 'lipids',
        'creatinine', 'egfr', 'gfr', 'bun', 'acr', 'microalbumin', 'albumin',
        'urine acr', 'potassium', 'sodium', 'chloride', 'bicarbonate',
        'tsh', 't4', 't3', 'alt', 'ast', 'alkaline phosphatase',
        'hemoglobin', 'hematocrit', 'platelets', 'wbc', 'blood pressure',
    ];

    /**
     * Medication terms — the closed set of drugs the med capability reconciles. A claim naming any
     * of these is clinical (V2). Not a general drug dictionary — the module's own code set.
     *
     * @var list<string>
     */
    public const MEDICATIONS = [
        'metformin', 'insulin', 'glipizide', 'glimepiride', 'glyburide',
        'sitagliptin', 'linagliptin', 'empagliflozin', 'dapagliflozin',
        'canagliflozin', 'liraglutide', 'semaglutide', 'dulaglutide',
        'pioglitazone', 'lisinopril', 'losartan', 'amlodipine',
        'atorvastatin', 'rosuvastatin', 'simvastatin', 'hydrochlorothiazide',
        'aspirin', 'gabapentin', 'levothyroxine',
    ];

    /**
     * Patient-attribute cues — a claim naming any of these speaks to the patient and is clinical.
     *
     * @var list<string>
     */
    public const PATIENT_ATTRIBUTES = [
        '\bage\b', '\baged\b', '\byears? old\b', '\by/o\b', '\byo\b',
        '\bmale\b', '\bfemale\b', '\bgender\b', '\bsex\b',
        '\bdob\b', '\bdate of birth\b', '\bmrn\b', '\bweight\b', '\bheight\b', '\bbmi\b',
    ];

    private function __construct()
    {
    }

    /**
     * True when the claim text lexically mentions an analyte, medication, number, date, or
     * patient attribute — i.e. it is clinical regardless of its declared claim_type (V2).
     */
    public static function mentionsClinical(string $text): bool
    {
        // Any digit is a number/date/count reference.
        if (preg_match('/\d/', $text) === 1) {
            return true;
        }

        $lower = mb_strtolower($text);

        foreach (self::ANALYTES as $analyte) {
            if (str_contains($lower, $analyte)) {
                return true;
            }
        }
        foreach (self::MEDICATIONS as $med) {
            if (str_contains($lower, $med)) {
                return true;
            }
        }
        foreach (self::PATIENT_ATTRIBUTES as $pattern) {
            if (preg_match('~' . $pattern . '~i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan claim text for banned lexical patterns. Returns every (class, trigger) match — a claim
     * may trip more than one class. Empty list ⇒ V5 clean for this claim.
     *
     * @return list<array{class: string, trigger: string}>
     */
    public static function bannedMatches(string $text): array
    {
        $matches = [];
        foreach (self::BANNED as $class => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('~' . $pattern . '~i', $text, $m) === 1) {
                    $matches[] = ['class' => $class, 'trigger' => trim((string) ($m[0] ?? $pattern))];
                    break; // one hit per class is enough to fail; keep findings compact
                }
            }
        }
        return $matches;
    }
}
