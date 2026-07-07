<?php

/**
 * VerificationVerdict — the immutable result of running V1–V6 over a claim list.
 *
 * Carries a complete per-check ledger, the overall pass bool, and the SEV-1 patientGuardTripped
 * flag (a V3 failure — treated as an incident, not a retry, ARCHITECTURE.md §2.3). It knows how
 * to recommend the fail-closed action (Pass / Regenerate / Discard / Freeze) and how to serialize
 * itself for persistence on the turn/doc row and the verify span (§3). The verifier decides; the
 * read/chat path (I11) acts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

final readonly class VerificationVerdict implements \JsonSerializable
{
    /** @var array<string, CheckResult> keyed by CheckId->value, one entry per check, always all six */
    public array $checks;

    public bool $passed;

    /**
     * @param list<CheckResult> $checkResults exactly one per CheckId, in V1..V6 order
     */
    public function __construct(
        array $checkResults,
        public bool $patientGuardTripped,
        public string $lexiconVersion,
    ) {
        $map = [];
        $allPassed = true;
        foreach ($checkResults as $result) {
            $map[$result->check->value] = $result;
            $allPassed = $allPassed && $result->passed;
        }
        $this->checks = $map;
        $this->passed = $allPassed;
    }

    public function check(CheckId $id): ?CheckResult
    {
        return $this->checks[$id->value] ?? null;
    }

    public function checkPassed(CheckId $id): bool
    {
        return $this->checks[$id->value]?->passed ?? false;
    }

    /**
     * All findings across every failed check, ready to append to a regeneration prompt.
     *
     * @return list<string>
     */
    public function findings(): array
    {
        $out = [];
        foreach ($this->checks as $result) {
            foreach ($result->findings as $finding) {
                $out[] = $finding;
            }
        }
        return $out;
    }

    /**
     * The fail-closed action the caller must take (ARCHITECTURE.md §2.3). The verifier owns this
     * decision; the caller supplies only whether the single permitted regeneration has been used.
     *
     * Precedence: a tripped patient guard is ALWAYS Freeze, even on the first attempt — V3 is not
     * a retry. Otherwise a passing verdict is Pass; a failing verdict is Regenerate once, then
     * Discard.
     */
    public function recommendedAction(bool $regenerationAlreadyUsed): FailureAction
    {
        if ($this->patientGuardTripped) {
            return FailureAction::Freeze;
        }
        if ($this->passed) {
            return FailureAction::Pass;
        }
        return $regenerationAlreadyUsed ? FailureAction::Discard : FailureAction::Regenerate;
    }

    /**
     * JSON-serializable form for the turn/doc row and the verify span. Deterministic key order.
     *
     * @return array{passed: bool, patient_guard_tripped: bool, lexicon_version: string, checks: list<array{check: string, label: string, passed: bool, findings: list<string>}>}
     */
    public function toArray(): array
    {
        $checks = [];
        foreach (CheckId::cases() as $id) {
            $result = $this->checks[$id->value] ?? null;
            if ($result !== null) {
                $checks[] = $result->toArray();
            }
        }

        return [
            'passed' => $this->passed,
            'patient_guard_tripped' => $this->patientGuardTripped,
            'lexicon_version' => $this->lexiconVersion,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
