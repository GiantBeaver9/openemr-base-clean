<?php

/**
 * Verifier — the deterministic gate between model generation and rendering (ARCHITECTURE.md §2).
 *
 * Nothing the model produces reaches a physician without passing through here. The whole trust
 * model rests on this being mechanical, ordered, and side-effect free: V1→V6 each run in order,
 * each is a pure function of (claims, session fact set, pinned pid, path), and every one is
 * recorded on the verdict for audit and for building the single permitted regeneration prompt.
 *
 * The verifier does NO arithmetic (V4), NO conflict adjudication (V6 is presence-only, I8), and
 * NO interpretation — it resolves citations, matches numbers, and lints against a version-pinned
 * lexicon. Its known residuals (emphasis, paraphrase, omission beyond the closed conflict set) are
 * stated honestly in §2.4 and covered by rendering + evals, not claimed here.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;

final class Verifier
{
    /**
     * Parse a raw model response and verify it. The parse itself IS V1 — a payload with no
     * structured claim list ("free prose") fails the schema gate and short-circuits fail-closed;
     * V2–V6 are recorded as failed-not-evaluated so the verdict ledger is always complete.
     */
    public function verifyResponse(
        LlmResponse $response,
        FactSet $sessionFactSet,
        int $sessionPid,
        bool $isSynthesisPath,
    ): VerificationVerdict {
        try {
            $claims = Claim::listFromPayload($response->json);
        } catch (ClaimSchemaException $e) {
            return $this->schemaGateFailure($e->getMessage());
        }

        return $this->verify($claims, $sessionFactSet, $sessionPid, $isSynthesisPath);
    }

    /**
     * Run V1–V6 over an already-parsed claim list. Checks run in order and ALL are recorded, so the
     * verdict carries a full per-check ledger even when an early check fails (fail-closed does not
     * mean stop-checking).
     *
     * @param list<Claim> $claims
     */
    public function verify(
        array $claims,
        FactSet $sessionFactSet,
        int $sessionPid,
        bool $isSynthesisPath,
    ): VerificationVerdict {
        // Resolve every claim's citations once; V2–V6 reuse the result deterministically.
        $resolutions = $this->resolveAll($claims, $sessionFactSet);

        $v1 = $this->checkV1($claims);
        $v2 = $this->checkV2($claims, $resolutions);
        $v3 = $this->checkV3($claims, $resolutions, $sessionPid);
        $v4 = $this->checkV4($claims, $resolutions);
        $v5 = $this->checkV5($claims);
        $v6 = $this->checkV6($claims, $resolutions, $sessionFactSet, $isSynthesisPath);

        return new VerificationVerdict(
            [$v1, $v2, $v3, $v4, $v5, $v6],
            $v3->passed === false, // V3 failure is the SEV-1 patient-guard trip
            BannedLexicon::VERSION,
        );
    }

    /**
     * @param list<Claim> $claims
     *
     * @return array<int, array{resolved: list<Fact>, unresolved: list<string>}>
     */
    private function resolveAll(array $claims, FactSet $set): array
    {
        $out = [];
        foreach ($claims as $i => $claim) {
            $resolved = [];
            $unresolved = [];
            foreach ($claim->citationIds as $id) {
                $fact = $set->findById($id);
                if ($fact === null) {
                    $unresolved[] = $id;
                } else {
                    $resolved[] = $fact;
                }
            }
            $out[$i] = ['resolved' => $resolved, 'unresolved' => $unresolved];
        }
        return $out;
    }

    /**
     * V1 — schema gate. By the time claims are typed the shape is sound; the residual structural
     * failures are an empty claim list (the model must emit at least a greeting/refusal) or an
     * empty-text claim (a directly-constructed Claim that bypassed the parser).
     *
     * @param list<Claim> $claims
     */
    private function checkV1(array $claims): CheckResult
    {
        $findings = [];
        if ($claims === []) {
            $findings[] = 'V1: the response contains no claims (unstructured or empty output).';
        }
        foreach ($claims as $i => $claim) {
            if (trim($claim->text) === '') {
                $findings[] = 'V1: claim ' . ($i + 1) . ' has empty text.';
            }
        }
        return new CheckResult(CheckId::V1SchemaGate, $findings === [], $findings);
    }

    /**
     * V2 — citation resolution. Every citationId must resolve to a fact in the session set. Zero
     * citations are permitted ONLY for the four allowed claim types AND only when the text is not
     * lexically clinical — any analyte/medication/number/date/patient-attribute makes a claim
     * clinical regardless of its declared type.
     *
     * @param list<Claim>                                                            $claims
     * @param array<int, array{resolved: list<Fact>, unresolved: list<string>}>       $resolutions
     */
    private function checkV2(array $claims, array $resolutions): CheckResult
    {
        $findings = [];
        foreach ($claims as $i => $claim) {
            $n = $i + 1;

            foreach ($resolutions[$i]['unresolved'] as $id) {
                $findings[] = 'V2: claim ' . $n . ' cites ' . $id
                    . ' which does not resolve to any fact in the session fact set.';
            }

            $isClinical = !$claim->claimType->allowsZeroCitations()
                || BannedLexicon::mentionsClinical($claim->text);

            if ($isClinical && !$claim->hasCitations()) {
                $findings[] = 'V2: claim ' . $n . ' is clinical ('
                    . $this->snippet($claim->text) . ') but carries no citation.';
            }
        }
        return new CheckResult(CheckId::V2CitationResolution, $findings === [], $findings);
    }

    /**
     * V3 — patient identity guard (SEV-1). Every resolved fact's pid must equal the pinned session
     * pid. A failure is an incident, not a retry: the caller freezes the session. Foreign pids are
     * deliberately not printed (findings never carry patient identifiers) — only the fact id.
     *
     * @param list<Claim>                                                            $claims
     * @param array<int, array{resolved: list<Fact>, unresolved: list<string>}>       $resolutions
     */
    private function checkV3(array $claims, array $resolutions, int $sessionPid): CheckResult
    {
        $findings = [];
        foreach ($claims as $i => $claim) {
            foreach ($resolutions[$i]['resolved'] as $fact) {
                if ($fact->pid !== $sessionPid) {
                    $findings[] = 'V3: claim ' . ($i + 1) . ' cites fact ' . $fact->factId
                        . ' whose patient does not match the pinned session — patient identity'
                        . ' guard tripped (SEV-1, session frozen).';
                }
            }
        }
        return new CheckResult(CheckId::V3PatientIdentityGuard, $findings === [], $findings);
    }

    /**
     * V4 — numeric grounding. Every number and date in a claim's TEXT must already appear in a fact
     * that claim cites (after canonicalization). The verifier does no arithmetic; derived numbers
     * are grounded only because a derived_* fact carries them.
     *
     * @param list<Claim>                                                            $claims
     * @param array<int, array{resolved: list<Fact>, unresolved: list<string>}>       $resolutions
     */
    private function checkV4(array $claims, array $resolutions): CheckResult
    {
        $findings = [];
        foreach ($claims as $i => $claim) {
            $n = $i + 1;
            $extracted = NumericCanonicalizer::extract($claim->text);
            if ($extracted['numbers'] === [] && $extracted['dates'] === []) {
                continue;
            }

            $grounded = NumericCanonicalizer::groundedForFacts($resolutions[$i]['resolved']);

            foreach ($extracted['numbers'] as $number) {
                if (!isset($grounded['numbers'][$number])) {
                    $findings[] = 'V4: claim ' . $n . ' states the number ' . $number
                        . ' which does not appear in any fact it cites.';
                }
            }
            foreach ($extracted['dates'] as $date) {
                if (!isset($grounded['dates'][$date])) {
                    $findings[] = 'V4: claim ' . $n . ' states the date ' . $date
                        . ' which does not appear in any fact it cites.';
                }
            }
        }
        return new CheckResult(CheckId::V4NumericGrounding, $findings === [], $findings);
    }

    /**
     * V5 — banned-claim lint. Deterministic pattern classes over the version-pinned lexicon:
     * causation, treatment recommendation, diagnosis, dosage advice, drug-interaction assertion.
     * Rejects the LEXICAL class only; paraphrase without trigger words is a stated residual (§2.4).
     *
     * @param list<Claim> $claims
     */
    private function checkV5(array $claims): CheckResult
    {
        $findings = [];
        foreach ($claims as $i => $claim) {
            foreach (BannedLexicon::bannedMatches($claim->text) as $match) {
                $findings[] = 'V5: claim ' . ($i + 1) . ' contains a banned ' . $match['class']
                    . " pattern ('" . $match['trigger'] . "').";
            }
        }
        return new CheckResult(CheckId::V5BannedClaimLint, $findings === [], $findings);
    }

    /**
     * V6 — conflict passthrough (closed-set presence checks). (i) any claim citing a conflict-
     * flagged fact must carry 'conflict' in its flags (chat and synthesis); (ii) synthesis path
     * only — every conflict-flagged fact in the input must be cited by >=1 claim.
     *
     * @param list<Claim>                                                            $claims
     * @param array<int, array{resolved: list<Fact>, unresolved: list<string>}>       $resolutions
     */
    private function checkV6(
        array $claims,
        array $resolutions,
        FactSet $set,
        bool $isSynthesisPath,
    ): CheckResult {
        $findings = [];
        $citedIds = [];

        // (i) conflict acknowledgment on every claim that cites a conflict fact.
        foreach ($claims as $i => $claim) {
            foreach ($resolutions[$i]['resolved'] as $fact) {
                $citedIds[$fact->factId] = true;
                if ($fact->isConflict() && !$claim->hasFlag(Flag::CONFLICT)) {
                    $findings[] = 'V6(i): claim ' . ($i + 1) . ' cites conflict-flagged fact '
                        . $fact->factId . " but does not carry the 'conflict' flag.";
                }
            }
        }

        // (ii) synthesis must surface every conflict in the input set.
        if ($isSynthesisPath) {
            foreach ($set->conflicts() as $conflictFact) {
                if (!isset($citedIds[$conflictFact->factId])) {
                    $findings[] = 'V6(ii): conflict-flagged fact ' . $conflictFact->factId
                        . ' is present in the fact set but is not cited by any claim'
                        . ' (synthesis must surface every conflict).';
                }
            }
        }

        return new CheckResult(CheckId::V6ConflictPassthrough, $findings === [], $findings);
    }

    /**
     * Build a fail-closed verdict when V1 rejects the payload outright: V1 fails with the parse
     * reason, V2–V6 are recorded as failed-not-evaluated so the ledger stays complete.
     */
    private function schemaGateFailure(string $reason): VerificationVerdict
    {
        $notEvaluated = static fn(CheckId $id): CheckResult => new CheckResult(
            $id,
            false,
            [$id->value . ': not evaluated — the V1 schema gate rejected the output.'],
        );

        return new VerificationVerdict(
            [
                new CheckResult(CheckId::V1SchemaGate, false, ['V1: ' . $reason . '.']),
                $notEvaluated(CheckId::V2CitationResolution),
                $notEvaluated(CheckId::V3PatientIdentityGuard),
                $notEvaluated(CheckId::V4NumericGrounding),
                $notEvaluated(CheckId::V5BannedClaimLint),
                $notEvaluated(CheckId::V6ConflictPassthrough),
            ],
            false, // a parse failure is not a patient-guard trip
            BannedLexicon::VERSION,
        );
    }

    private function snippet(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= 60) {
            return $text;
        }
        return mb_substr($text, 0, 57) . '...';
    }
}
