<?php

/**
 * Isolated tests for the observability spine (CorrelationId, Span, trace recorder).
 *
 * Guards: UUIDv7 format, span nesting + append-only recording, trace reconstruction
 * by correlation id (I12: every path leaves a trace).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Observability\InMemoryTraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

function clinical_copilot_test_ObservabilityCoreTest(): void
{
    // UUIDv7 format + validity.
    $cid = CorrelationId::mint(1_770_000_000_000);
    Assert::that(CorrelationId::isValid($cid), 'minted correlation id is a valid UUIDv7');
    Assert::that($cid !== CorrelationId::mint(1_770_000_000_000), 'two mints differ (random component)');

    // Time-ordered prefix: an earlier timestamp sorts before a later one.
    $early = CorrelationId::mint(1_000_000_000_000);
    $late = CorrelationId::mint(2_000_000_000_000);
    Assert::that($early < $late, 'UUIDv7 timestamp prefix preserves ordering');

    // Span open/close + append-only recording.
    $rec = new InMemoryTraceRecorder();
    $root = $rec->start($cid, TraceKind::ChatTurn, '2026-02-01T09:00:00.000Z', null, 42, 7);
    $tool = $rec->start($cid, TraceKind::ToolCall, '2026-02-01T09:00:00.100Z', $root->spanId, 42, 7);
    $rec->record($tool->close(SpanStatus::Ok, 120));
    $verify = $rec->start($cid, TraceKind::Verify, '2026-02-01T09:00:00.300Z', $root->spanId, 42, 7);
    $rec->record($verify->close(SpanStatus::Ok, 40));
    $rec->record($root->close(SpanStatus::Ok, 500));

    Assert::equals(3, count($rec->byCorrelation($cid)), 'all spans for the correlation id are reconstructable (I12)');

    // Nesting: tool + verify parent the root turn.
    Assert::equals($root->spanId, $tool->parentSpanId, 'tool span nests under the chat turn');
    Assert::equals($root->spanId, $verify->parentSpanId, 'verify span nests under the chat turn');

    // Error spans keep PHI out of the inline detail.
    $span = $rec->start($cid, TraceKind::Extract, '2026-02-01T09:00:00.000Z');
    $span->failWith(new \RuntimeException('patient MRN 12345 blew up'));
    Assert::that(!str_contains((string) $span->errorDetail, '12345'), 'error_detail never carries PHI inline (behind payload_ref)');
    Assert::equals('RuntimeException', $span->errorClass, 'error_class captures the throwable class');
}
