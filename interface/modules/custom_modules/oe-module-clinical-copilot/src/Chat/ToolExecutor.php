<?php

/**
 * ToolExecutor — disposes a model-proposed tool call deterministically (I13, §1.2).
 *
 * The model executes NOTHING. It emits a structured request; this class:
 *   1. schema-validates + sanitizes the args (ToolRegistry) — a forged patient id has nowhere to
 *      go because no schema declares one, and unknown keys are dropped;
 *   2. INJECTS the session's pinned pid server-side — the only patient the capability ever sees;
 *   3. runs the wrapped capability via the shared CapabilityFactory (identical wiring to the read
 *      path — chat introduces no new data access);
 *   4. ASSERTS every returned fact carries the pinned pid (PatientPinGuard) BEFORE it enters the
 *      session fact set — a mismatch is a SEV-1 pin violation, never a filtered-out row;
 *   5. writes a `tool_call` span and sets Span->model to the tool name (U12's Metrics reads the
 *      invoked-tool name from the span model column).
 *
 * A schema-invalid call or a capability crash becomes a tool FAILURE surfaced to the model AND the
 * user (§6.2), never silently absorbed. The executor holds no patient state between calls: the pid
 * is passed in per call from the session, so there is no way to leak one patient's pin to another.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityInterface;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;

final class ToolExecutor implements ToolDispatcher
{
    public function __construct(
        private readonly CapabilityFactory $capabilities,
        private readonly ToolRegistry $registry,
        private readonly TraceRecorder $traces,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    /**
     * Execute one proposed tool call for the pinned session patient. `$rawArgs` is untrusted model
     * output; `$sessionPid` is the server-side pin (never taken from the args).
     */
    public function execute(
        ToolName $tool,
        array $rawArgs,
        int $sessionPid,
        string $correlationId,
        ?string $parentSpanId = null,
        ?int $userId = null,
    ): ToolCallOutcome {
        $span = $this->traces->start(
            $correlationId,
            TraceKind::ToolCall,
            $this->stamp(),
            $parentSpanId,
            $sessionPid,
            $userId,
        );
        // U12's Metrics reads the invoked-tool name from the span model column.
        $span->model = $tool->value;
        $startMicro = microtime(true);

        // 1. Validate + sanitize (drops any forged pid / unknown key).
        /** @var array<string, mixed> $rawArgs */
        $validation = $this->registry->validate($tool, $rawArgs);
        if (!$validation->valid) {
            $span->errorClass = 'ToolValidationError';
            $span->errorDetail = 'see trace payload';
            $span->close(SpanStatus::Error, $this->elapsedMs($startMicro));
            $this->traces->record($span);
            return ToolCallOutcome::failure($tool, (string) $validation->error);
        }

        // 2–3. Inject the pinned pid and run the capability fresh (facts are never cached, I2).
        try {
            $facts = $this->capabilityFor($tool)->forPatient($sessionPid);
        } catch (\Throwable $e) {
            $span->failWith($e, $this->elapsedMs($startMicro));
            $this->traces->record($span);
            return ToolCallOutcome::failure(
                $tool,
                $tool->value . ' lookup failed — the capability could not be read.',
            );
        }

        $facts = ToolResultFilter::apply($tool, $validation->sanitizedArgs, $facts, $this->now ?? new \DateTimeImmutable());

        // 4. Assert the pin BEFORE any fact enters the session set (I10, defense-in-depth with V3).
        try {
            PatientPinGuard::assertAllPinned($facts, $sessionPid);
        } catch (PatientPinViolationException $e) {
            $span->errorClass = PatientPinViolationException::class;
            $span->errorDetail = 'see trace payload';
            $span->close(SpanStatus::Error, $this->elapsedMs($startMicro));
            $this->traces->record($span);
            return ToolCallOutcome::pinViolation($tool, $e->getMessage());
        }

        $span->close(SpanStatus::Ok, $this->elapsedMs($startMicro));
        $this->traces->record($span);

        return ToolCallOutcome::ok($tool, $facts);
    }

    private function capabilityFor(ToolName $tool): CapabilityInterface
    {
        return match ($tool) {
            ToolName::GetControlTrend => $this->capabilities->controlProxy,
            ToolName::GetMedHistory => $this->capabilities->medResponse,
            ToolName::GetVitalsTrend => $this->capabilities->vitalsTrend,
            ToolName::GetOverdue => $this->capabilities->overdueTests,
            ToolName::GetPending => $this->capabilities->pendingResults,
        };
    }

    private function stamp(): string
    {
        return ($this->now ?? new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
    }

    private function elapsedMs(float $startMicro): int
    {
        return (int) round((microtime(true) - $startMicro) * 1000.0);
    }
}
