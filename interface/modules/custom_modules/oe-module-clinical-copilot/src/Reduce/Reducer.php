<?php

/**
 * Reducer — orchestrates one reduce pass and owns the degradation rule (I6).
 *
 * Flow: build the per-session redaction map → assemble the prompt from the canonical facts →
 * redact the outbound request → open an llm_reduce span → call the LlmClient with
 * breaker-aware retries (up to a configured max) → on success return the RAW model output for
 * the verifier (U10) to gate; on failure/unavailability after retries return facts-only marked
 * "narrative unavailable" and close the span degraded.
 *
 * It does NOT verify and does NOT rehydrate — rehydration happens after verification (§4), and
 * gating is U10's job. This unit's contract is: never let an LLM outage cost the physician the
 * facts, and always leave a span behind (I12).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;

final class Reducer
{
    public const DEFAULT_MAX_ATTEMPTS = 3;

    private readonly BreakerGate $breaker;
    private readonly int $maxAttempts;

    public function __construct(
        private readonly LlmClient $client,
        private readonly PromptAssembler $assembler,
        private readonly EgressRedactor $redactor,
        private readonly TraceRecorder $traces,
        private readonly string $model,
        private readonly string $promptVersion = 'prompt@1',
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        ?BreakerGate $breaker = null,
    ) {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->breaker = $breaker ?? new NullBreakerGate();
    }

    /**
     * Run the reduce. `sessionSeed` scopes the redaction tokens (e.g. the chat session id or
     * correlation id); `correlationId` threads the span. `pid`/`userId`/`parentSpanId` are
     * carried onto the span for the dashboard's waterfall.
     */
    public function reduce(
        FactSet $facts,
        PatientContext $context,
        string $correlationId,
        string $sessionSeed,
        ?int $userId = null,
        ?string $parentSpanId = null,
    ): ReduceResult {
        $map = $this->redactor->buildMap($context, $sessionSeed);
        $request = $this->assembler->assemble($facts, $context, $this->model, $this->promptVersion);
        $outbound = $this->redactor->redactRequest($request, $map);

        $startedAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
        $span = $this->traces->start(
            $correlationId,
            TraceKind::LlmReduce,
            $startedAt,
            $parentSpanId,
            $facts->pid,
            $userId,
        );
        $span->model = $this->model;

        $startMicro = microtime(true);
        $attempts = 0;
        $lastError = null;

        while ($attempts < $this->maxAttempts) {
            if ($this->breaker->isOpen()) {
                break;
            }
            $attempts++;
            try {
                $response = $this->client->generate($outbound);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }

            $span->tokensIn = $response->tokensIn;
            $span->tokensOut = $response->tokensOut;
            $span->model = $response->model;
            $span->close(
                $attempts > 1 ? SpanStatus::Retried : SpanStatus::Ok,
                $this->elapsedMs($startMicro),
            );
            $this->traces->record($span);

            return ReduceResult::ok($facts, $map, $correlationId, $response, $attempts);
        }

        // Degradation (I6): breaker open, or all attempts failed. Facts-only, span degraded.
        if ($lastError !== null) {
            $span->errorClass = $lastError::class;
        }
        $span->errorDetail = 'see trace payload';
        $span->close(SpanStatus::Degraded, $this->elapsedMs($startMicro));
        $this->traces->record($span);

        return ReduceResult::degraded($facts, $map, $correlationId, $attempts);
    }

    private function elapsedMs(float $startMicro): int
    {
        return (int) round((microtime(true) - $startMicro) * 1000.0);
    }
}
