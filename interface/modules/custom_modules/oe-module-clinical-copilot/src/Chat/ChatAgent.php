<?php

/**
 * ChatAgent — the pinned, tool-invoking agent loop for one chat turn (§1, §2, §6.2).
 *
 * The loop is deterministic module code; the model only proposes (I13). Per turn:
 *   1. assemble the pinned request (system refusals + narrative + canonical facts + history + msg
 *      + tool declarations), redacted at egress (§4);
 *   2. LlmClient ⇄ tools within a ≤5-call / ≤3-round budget — each tool call injects the pinned
 *      pid server-side and asserts every returned fact carries it (a mismatch freezes, SEV-1);
 *   3. on a final answer, run the Verifier over the WHOLE response BEFORE any prose is shown, and
 *      act on recommendedAction: Pass ⇒ rehydrate + show; Regenerate ⇒ one retry with findings;
 *      Discard ⇒ facts-only; Freeze (V3) ⇒ freeze the session and stop.
 *
 * Every failure ends on a working reference surface: the accumulated facts render beside the chat
 * regardless (recovery asymmetry, §6). An LLM outage degrades to a facts browser (I6/I11); a tool
 * failure is surfaced to the model AND the user, never silently absorbed.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;
use OpenEMR\Modules\ClinicalCopilot\Verify\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchemaException;
use OpenEMR\Modules\ClinicalCopilot\Verify\FailureAction;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationVerdict;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

final class ChatAgent
{
    private const HARD_ITERATION_CAP = ToolBudget::MAX_ROUNDS + 3;

    public function __construct(
        private readonly LlmClient $client,
        private readonly ToolDispatcher $executor,
        private readonly ToolRegistry $registry,
        private readonly Verifier $verifier,
        private readonly EgressRedactor $redactor,
        private readonly ChatPromptAssembler $assembler,
        private readonly SessionStore $store,
        private readonly TraceRecorder $traces,
        private readonly string $model,
        private readonly CanonicalSerializer $serializer = new CanonicalSerializer(),
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    /**
     * Run one turn. `$seed` is the preloaded fact set + narrative + digest; `$history` is the prior
     * turns; `$context` carries the direct identifiers to redact. Returns an AgentResult the
     * controller renders and persists append-only.
     *
     * @param list<ChatTurn> $history
     */
    public function runTurn(
        ChatSession $session,
        SessionSeed $seed,
        array $history,
        string $userMessage,
        PatientContext $context,
        string $correlationId,
        ?int $userId = null,
    ): AgentResult {
        $sessionPid = $session->pid;
        $root = $this->traces->start($correlationId, TraceKind::ChatTurn, $this->stamp(), null, $sessionPid, $userId);
        $rootSpanId = $root->spanId;
        $startMicro = microtime(true);

        $map = $this->redactor->buildMap($context, (string) $session->id);
        $facts = $seed->facts;
        $budget = new ToolBudget();
        $regenUsed = false;
        $notes = [];
        $toolCallLog = [];
        $retrieved = [];
        $tokensIn = 0;
        $tokensOut = 0;
        $lastModel = $this->model;

        $working = $this->assembler->assemble(
            $facts,
            $seed->narrative,
            $history,
            $userMessage,
            $this->model,
            $this->registry->declarations(),
        );

        for ($iteration = 0; $iteration < self::HARD_ITERATION_CAP; $iteration++) {
            $response = $this->generate($working, $map, $correlationId, $rootSpanId, $sessionPid, $userId);
            if ($response === null) {
                // LLM unavailable after the client's own handling — degrade to facts (I6/I11).
                $notes[] = 'The assistant is temporarily unavailable — showing the facts I have.';
                return $this->factsOnly($root, $startMicro, $facts, $notes, $toolCallLog, $lastModel, $tokensIn, $tokensOut);
            }
            $tokensIn += $response->tokensIn;
            $tokensOut += $response->tokensOut;
            $lastModel = $response->model !== '' ? $response->model : $lastModel;

            if ($response->hasToolCalls()) {
                if (!$budget->hasRoundBudget() || !$budget->hasCallBudget()) {
                    $notes[] = $this->budgetNote($retrieved, $response);
                    return $this->factsOnly($root, $startMicro, $facts, $notes, $toolCallLog, $lastModel, $tokensIn, $tokensOut);
                }
                $budget->consumeRound();

                $rendered = [];
                foreach ($response->toolCalls as $call) {
                    if (!$budget->hasCallBudget()) {
                        $notes[] = 'Tool budget reached — some requests were not run. Ask again to continue.';
                        break;
                    }
                    $budget->consumeCall();

                    $result = $this->disposeToolCall($call, $sessionPid, $correlationId, $rootSpanId, $userId);
                    if ($result->pinViolation) {
                        // SEV-1: freeze the session and stop (§2.3). Do not continue the conversation.
                        $this->store->freeze($session->id);
                        return $this->frozen($root, $startMicro, $facts, $toolCallLog, $lastModel, $tokensIn, $tokensOut, null);
                    }
                    $toolCallLog[] = $this->logEntry($result, $call);
                    if ($result->ok) {
                        $facts = $facts->withFacts($result->facts);
                        $retrieved[$result->tool->value] = true;
                        $rendered[] = $this->renderToolResult($result->tool, $result->facts);
                    } else {
                        $notes[] = $result->error ?? ($result->tool->value . ' lookup failed.');
                        $rendered[] = $this->renderToolFailure($result->tool->value, $result->error ?? 'tool failure');
                    }
                }

                $working = $this->assembler->withToolResults($working, $rendered);
                continue;
            }

            // Final answer: verify the WHOLE response before any prose is shown (§1.3, §2).
            $verdict = $this->verify($response, $facts, $sessionPid, $correlationId, $rootSpanId, $userId);
            $action = $verdict->recommendedAction($regenUsed);

            if ($action === FailureAction::Freeze) {
                $this->store->freeze($session->id);
                return $this->frozen($root, $startMicro, $facts, $toolCallLog, $lastModel, $tokensIn, $tokensOut, $verdict);
            }
            if ($action === FailureAction::Pass) {
                return $this->answered($root, $startMicro, $response, $facts, $verdict, $map, $notes, $toolCallLog, $lastModel, $tokensIn, $tokensOut);
            }
            if ($action === FailureAction::Regenerate) {
                $regenUsed = true;
                $working = $this->assembler->withVerifierFindings($working, $verdict->findings());
                continue;
            }
            // Discard: verification failed twice — facts are the answer (§6.2).
            $notes[] = "I couldn't produce a verifiable answer — here are the facts I retrieved.";
            return $this->factsOnly($root, $startMicro, $facts, $notes, $toolCallLog, $lastModel, $tokensIn, $tokensOut, $verdict);
        }

        // Safety net: the loop exhausted its hard cap without terminating.
        $notes[] = 'The assistant could not complete a verifiable answer within the turn budget.';
        return $this->factsOnly($root, $startMicro, $facts, $notes, $toolCallLog, $lastModel, $tokensIn, $tokensOut);
    }

    /**
     * One generation pass inside a child span. Returns null on any provider/transport failure so
     * the loop degrades (I6); the client itself may already retry internally.
     */
    private function generate(
        LlmRequest $working,
        RedactionMap $map,
        string $correlationId,
        string $rootSpanId,
        int $pid,
        ?int $userId,
    ): ?LlmResponse {
        $outbound = $this->redactor->redactRequest($working, $map);
        $span = $this->traces->start($correlationId, TraceKind::LlmReduce, $this->stamp(), $rootSpanId, $pid, $userId);
        $span->model = $this->model;
        $micro = microtime(true);
        try {
            $response = $this->client->generate($outbound);
        } catch (\Throwable $e) {
            $span->failWith($e, $this->elapsed($micro));
            $this->traces->record($span);
            return null;
        }
        $span->tokensIn = $response->tokensIn;
        $span->tokensOut = $response->tokensOut;
        $span->model = $response->model !== '' ? $response->model : $this->model;
        $span->close(SpanStatus::Ok, $this->elapsed($micro));
        $this->traces->record($span);
        return $response;
    }

    /**
     * @param array{name?: string, args?: array<string, mixed>} $call
     */
    private function disposeToolCall(array $call, int $pid, string $correlationId, string $rootSpanId, ?int $userId): ToolCallOutcome
    {
        $name = is_string($call['name'] ?? null) ? $call['name'] : '';
        $tool = $this->registry->resolve($name);
        if ($tool === null) {
            // A hallucinated tool: surface as a failure without touching a capability.
            return ToolCallOutcome::failure(ToolName::GetPending, "Requested unknown tool '" . $name . "'.");
        }
        $args = is_array($call['args'] ?? null) ? $call['args'] : [];
        /** @var array<string, mixed> $args */
        return $this->executor->execute($tool, $args, $pid, $correlationId, $rootSpanId, $userId);
    }

    private function verify(
        LlmResponse $response,
        FactSet $facts,
        int $pid,
        string $correlationId,
        string $rootSpanId,
        ?int $userId,
    ): VerificationVerdict {
        $span = $this->traces->start($correlationId, TraceKind::Verify, $this->stamp(), $rootSpanId, $pid, $userId);
        $micro = microtime(true);
        $verdict = $this->verifier->verifyResponse($response, $facts, $pid, false);
        $span->close($verdict->passed ? SpanStatus::Ok : SpanStatus::Degraded, $this->elapsed($micro));
        $this->traces->record($span);
        return $verdict;
    }

    /**
     * @param list<array<string, mixed>> $toolCallLog
     */
    private function answered(
        \OpenEMR\Modules\ClinicalCopilot\Observability\Span $root,
        float $startMicro,
        LlmResponse $response,
        FactSet $facts,
        VerificationVerdict $verdict,
        RedactionMap $map,
        array $notes,
        array $toolCallLog,
        string $model,
        int $tokensIn,
        int $tokensOut,
    ): AgentResult {
        try {
            $claims = Claim::listFromPayload($response->json);
        } catch (ClaimSchemaException) {
            // V1 passed already, but be defensive: fall back to facts-only rather than crash.
            $notes[] = "I couldn't produce a verifiable answer — here are the facts I retrieved.";
            return $this->factsOnly($root, $startMicro, $facts, $notes, $toolCallLog, $model, $tokensIn, $tokensOut, $verdict);
        }

        // Rehydrate identifiers AFTER verification (§4): tokens → originals in the display text only.
        $texts = array_map(static fn(Claim $c): string => $c->text, $claims);
        $answer = $this->redactor->rehydrate(implode("\n", $texts), $map);

        $this->closeRoot($root, $startMicro, SpanStatus::Ok, $model, $tokensIn, $tokensOut);
        return new AgentResult(
            AgentOutcome::Answered,
            $answer,
            $claims,
            $verdict,
            $facts,
            $notes,
            $toolCallLog,
            $model,
            $tokensIn,
            $tokensOut,
        );
    }

    /**
     * @param list<string>               $notes
     * @param list<array<string, mixed>> $toolCallLog
     */
    private function factsOnly(
        \OpenEMR\Modules\ClinicalCopilot\Observability\Span $root,
        float $startMicro,
        FactSet $facts,
        array $notes,
        array $toolCallLog,
        string $model,
        int $tokensIn,
        int $tokensOut,
        ?VerificationVerdict $verdict = null,
    ): AgentResult {
        $this->closeRoot($root, $startMicro, SpanStatus::Degraded, $model, $tokensIn, $tokensOut);
        $answer = $notes === []
            ? 'Showing the facts I retrieved.'
            : implode(' ', $notes);
        return new AgentResult(
            AgentOutcome::FactsOnly,
            $answer,
            [],
            $verdict,
            $facts,
            $notes,
            $toolCallLog,
            $model,
            $tokensIn,
            $tokensOut,
        );
    }

    /**
     * @param list<array<string, mixed>> $toolCallLog
     */
    private function frozen(
        \OpenEMR\Modules\ClinicalCopilot\Observability\Span $root,
        float $startMicro,
        FactSet $facts,
        array $toolCallLog,
        string $model,
        int $tokensIn,
        int $tokensOut,
        ?VerificationVerdict $verdict,
    ): AgentResult {
        $this->closeRoot($root, $startMicro, SpanStatus::Error, $model, $tokensIn, $tokensOut);
        return new AgentResult(
            AgentOutcome::Frozen,
            'This conversation has been locked for patient-safety review and cannot continue. The summary and facts remain available.',
            [],
            $verdict,
            $facts,
            ['Patient-identity guard tripped (SEV-1): the session is frozen and preserved as evidence.'],
            $toolCallLog,
            $model,
            $tokensIn,
            $tokensOut,
        );
    }

    private function closeRoot(
        \OpenEMR\Modules\ClinicalCopilot\Observability\Span $root,
        float $startMicro,
        SpanStatus $status,
        string $model,
        int $tokensIn,
        int $tokensOut,
    ): void {
        $root->model = $model;
        $root->tokensIn = $tokensIn;
        $root->tokensOut = $tokensOut;
        $root->close($status, $this->elapsed($startMicro));
        $this->traces->record($root);
    }

    /**
     * @param list<Fact> $facts
     */
    private function renderToolResult(ToolName $tool, array $facts): string
    {
        $bytes = $this->serializer->serialize($facts);
        return '<tool_result name="' . $tool->value . '" facts="' . count($facts) . '">' . $bytes . '</tool_result>';
    }

    private function renderToolFailure(string $tool, string $error): string
    {
        return '<tool_result name="' . $tool . '" status="failed">' . $error . '</tool_result>';
    }

    /**
     * @param array{name?: string, args?: array<string, mixed>} $call
     *
     * @return array<string, mixed>
     */
    private function logEntry(ToolCallOutcome $result, array $call): array
    {
        $factIds = array_map(static fn(Fact $f): string => $f->factId, $result->facts);
        return [
            'name' => $result->tool->value,
            'requested' => $call['args'] ?? [],
            'ok' => $result->ok,
            'error' => $result->error,
            'fact_ids' => $factIds,
        ];
    }

    /**
     * @param array<string, true> $retrieved
     */
    private function budgetNote(array $retrieved, LlmResponse $response): string
    {
        $got = array_keys($retrieved);
        $wanted = [];
        foreach ($response->toolCalls as $call) {
            if (is_string($call['name'] ?? null)) {
                $wanted[$call['name']] = true;
            }
        }
        $notRun = array_values(array_diff(array_keys($wanted), $got));
        $gotStr = $got === [] ? 'nothing yet' : implode(', ', $got);
        $notStr = $notRun === [] ? 'further requests' : implode(', ', $notRun);
        return 'I retrieved ' . $gotStr . '; I did not retrieve ' . $notStr . ' (tool budget reached) — ask again to continue.';
    }

    private function stamp(): string
    {
        return ($this->now ?? new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
    }

    private function elapsed(float $micro): int
    {
        return (int) round((microtime(true) - $micro) * 1000.0);
    }
}
