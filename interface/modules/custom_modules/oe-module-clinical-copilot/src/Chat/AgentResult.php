<?php

/**
 * AgentResult — the immutable outcome of one chat turn (§1, §2, §6.2).
 *
 * Carries everything the controller needs to render the turn and persist it append-only: the
 * terminal outcome, the display text (verified + rehydrated prose, or the facts-only/refusal
 * message), the verified claims, the full V1–V6 verdict, the ACCUMULATED session fact set (the
 * facts panel is always shown beside the chat — the recovery-asymmetry failsafe, §6), a
 * tool-call log for provenance, cost/token accounting, and the disclosed staleness banner (T19).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Verify\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationVerdict;

final readonly class AgentResult
{
    /**
     * @param list<Claim>                   $claims       verified claims (Answered only), else []
     * @param list<string>                  $notes        tool-failure / budget-exhaustion disclosures
     * @param list<array<string, mixed>>    $toolCallLog  {name, args, ok, error, fact_ids} per call
     */
    public function __construct(
        public AgentOutcome $outcome,
        public string $answerText,
        public array $claims,
        public ?VerificationVerdict $verdict,
        public FactSet $facts,
        public array $notes,
        public array $toolCallLog,
        public string $model,
        public int $tokensIn,
        public int $tokensOut,
        public bool $chartChanged = false,
    ) {
    }

    public function withChartChanged(bool $chartChanged): self
    {
        return new self(
            $this->outcome,
            $this->answerText,
            $this->claims,
            $this->verdict,
            $this->facts,
            $this->notes,
            $this->toolCallLog,
            $this->model,
            $this->tokensIn,
            $this->tokensOut,
            $chartChanged,
        );
    }

    public function isFrozen(): bool
    {
        return $this->outcome === AgentOutcome::Frozen;
    }

    /**
     * The JSON tool-call log for the turn row's tool_calls column (append-only provenance).
     */
    public function toolCallsJson(): ?string
    {
        if ($this->toolCallLog === []) {
            return null;
        }
        return (string) json_encode($this->toolCallLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
