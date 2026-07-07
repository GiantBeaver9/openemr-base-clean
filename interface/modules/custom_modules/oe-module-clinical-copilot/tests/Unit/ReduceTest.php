<?php

/**
 * Isolated tests for the LLM reduce layer (U7).
 *
 * Guards: (a) degradation — LLM stub in "down" mode ⇒ Reducer returns facts-only marked
 * "narrative unavailable" (I6) and writes a degraded llm_reduce span; (b) prompt assembly —
 * the fact bytes embedded in the prompt equal CanonicalSerializer->serialize(facts), the same
 * input as the digest; (c) egress redaction round-trip — no direct identifier string appears in
 * the outbound payload, and rehydrate restores the identifiers exactly (§4).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Observability\InMemoryTraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceStatus;
use OpenEMR\Modules\ClinicalCopilot\Reduce\StubLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

/**
 * @return list<Fact>
 */
function cc_reduce_facts(int $pid): array
{
    return [
        new Fact(
            Capability::ControlProxy,
            'control_proxy@1',
            FactKind::TrendPoint,
            $pid,
            '2026-01-05',
            DateSource::Collected,
            new FactValue('7.2', 7.2, Comparator::None, '%', '%', 'conv@1'),
            FactStatus::Final,
            [],
            [new Citation('procedure_result', 11, 'result', DateSource::Collected)],
        ),
        new Fact(
            Capability::ControlProxy,
            'control_proxy@1',
            FactKind::TrendPoint,
            $pid,
            '2026-03-05',
            DateSource::Collected,
            new FactValue('8.4', 8.4, Comparator::None, '%', '%', 'conv@1'),
            FactStatus::Final,
            [],
            [new Citation('procedure_result', 22, 'result', DateSource::Collected)],
        ),
    ];
}

function clinical_copilot_test_ReduceTest(): void
{
    $pid = 42;
    $facts = cc_reduce_facts($pid);
    $factSet = new FactSet($pid, $facts);
    $serializer = new CanonicalSerializer();
    $assembler = new PromptAssembler($serializer);
    $redactor = new EgressRedactor();
    $context = new PatientContext(
        $pid,
        'Jane Alice Doe',
        'MRN-00042',
        '1975-06-15',
        '742 Evergreen Terrace, Springfield',
    );

    // ---- (b) prompt assembly: fact bytes in the prompt == canonical serialization ----
    $expectedFactBytes = $serializer->serialize($facts);
    $request = $assembler->assemble($factSet, $context, 'gemini-2.5-pro', 'prompt@1');

    Assert::equals(
        $expectedFactBytes,
        $assembler->serializeFacts($facts),
        'assembler exposes canonical fact bytes identical to the serializer'
    );
    Assert::that(
        str_contains($request->userContent, $expectedFactBytes),
        'assembled prompt embeds the exact canonical fact bytes (same input as the digest)'
    );
    // Extract the delimited fact block and assert byte-equality with the serializer output.
    $open = PromptAssembler::FACTS_OPEN;
    $close = PromptAssembler::FACTS_CLOSE;
    $start = strpos($request->userContent, $open);
    $end = strpos($request->userContent, $close);
    $block = '';
    if ($start !== false && $end !== false) {
        $from = $start + strlen($open);
        $block = trim(substr($request->userContent, $from, $end - $from));
    }
    Assert::equals($expectedFactBytes, $block, 'the delimited fact block equals CanonicalSerializer->serialize(facts)');
    // The system prompt states the hard refusals (USERS.md §1).
    Assert::that(str_contains($request->systemPrompt, 'Do NOT assert causation'), 'system prompt carries the causation refusal');
    Assert::that(str_contains($request->systemPrompt, 'Do NOT make treatment recommendations'), 'system prompt carries the recommendation refusal');

    // ---- (c) redaction round-trip ----
    $map = $redactor->buildMap($context, 'session-seed-xyz');
    $outbound = $redactor->redactRequest($request, $map);

    foreach (['Jane Alice Doe', 'MRN-00042', '1975-06-15', '742 Evergreen Terrace, Springfield'] as $identifier) {
        Assert::that(
            !str_contains($outbound->userContent, $identifier),
            'outbound payload contains no direct identifier: ' . $identifier
        );
        Assert::that(
            !str_contains($outbound->systemPrompt, $identifier),
            'outbound system prompt contains no direct identifier: ' . $identifier
        );
    }
    // Tokens are present where identifiers were.
    Assert::that($map->tokenFor('Jane Alice Doe') !== null, 'a token exists for the patient name');
    Assert::that(
        str_contains($outbound->userContent, (string) $map->tokenFor('Jane Alice Doe')),
        'the name token appears in the redacted payload'
    );
    // The fact bytes survive redaction untouched (quasi-identifiers remain; minimization not de-id).
    Assert::that(
        str_contains($outbound->userContent, $expectedFactBytes),
        'redaction leaves the canonical fact bytes intact'
    );
    // Rehydration restores every identifier exactly, in a rendered answer that used tokens.
    $rendered = 'Summary for ' . $map->tokenFor('Jane Alice Doe') . ' (' . $map->tokenFor('MRN-00042') . ').';
    $rehydrated = $redactor->rehydrate($rendered, $map);
    Assert::equals(
        'Summary for Jane Alice Doe (MRN-00042).',
        $rehydrated,
        'rehydrate restores direct identifiers exactly (lossless round-trip)'
    );
    // Redact→rehydrate of arbitrary identifier-bearing text is lossless.
    $before = 'Patient Jane Alice Doe, MRN-00042, born 1975-06-15.';
    Assert::equals(
        $before,
        $redactor->rehydrate($redactor->redactText($before, $map), $map),
        'redact then rehydrate is the identity on identifier-bearing text'
    );

    // ---- (a) degradation: stub "down" ⇒ facts-only + degraded span (I6) ----
    $traces = new InMemoryTraceRecorder();
    $downClient = StubLlmClient::down();
    $reducer = new Reducer($downClient, $assembler, $redactor, $traces, 'gemini-2.5-pro', 'prompt@1', 3);
    $cid = CorrelationId::mint();
    $result = $reducer->reduce($factSet, $context, $cid, 'session-seed-xyz');

    Assert::equals(ReduceStatus::Degraded, $result->status, 'down LLM degrades the reduce (I6)');
    Assert::that($result->isDegraded(), 'result reports degraded');
    Assert::that(!$result->isNarrativeAvailable(), 'no narrative is available when degraded');
    Assert::equals('narrative unavailable', $result->degradedReason, 'degraded result is marked "narrative unavailable"');
    Assert::equals($pid, $result->facts->pid, 'facts-only result still carries the pinned patient facts');
    Assert::equals(2, $result->facts->count(), 'all facts survive degradation');
    Assert::equals(3, $result->attempts, 'the reducer retried up to the configured max before degrading');
    Assert::equals(3, $downClient->generateCalls(), 'the LLM client was actually called on each attempt');

    $spans = $traces->byCorrelation($cid);
    Assert::equals(1, count($spans), 'exactly one llm_reduce span is written');
    Assert::equals(TraceKind::LlmReduce, $spans[0]->kind, 'the span is an llm_reduce span');
    Assert::equals(SpanStatus::Degraded, $spans[0]->status, 'the degraded reduce writes a degraded span (I12)');
    Assert::equals($pid, $spans[0]->pid, 'the span carries the pinned pid');

    // ---- happy path: stub returns a canned structured payload ⇒ raw output passed through ----
    $tracesOk = new InMemoryTraceRecorder();
    $okClient = StubLlmClient::withCannedJson(['claims' => [['text' => 'A1c 7.2%', 'claim_type' => 'observation', 'citation_ids' => ['x']]]]);
    $okReducer = new Reducer($okClient, $assembler, $redactor, $tracesOk, 'gemini-2.5-pro', 'prompt@1', 3);
    $cidOk = CorrelationId::mint();
    $okResult = $okReducer->reduce($factSet, $context, $cidOk, 'session-seed-xyz');

    Assert::equals(ReduceStatus::Ok, $okResult->status, 'a healthy LLM yields an Ok reduce');
    Assert::that($okResult->isNarrativeAvailable(), 'raw model output is available on success');
    Assert::that($okResult->rawOutput !== null && isset($okResult->rawOutput->json['claims']), 'raw structured output is passed through un-gated');
    Assert::equals(1, $okClient->generateCalls(), 'success on first attempt makes exactly one LLM call');
    $okSpans = $tracesOk->byCorrelation($cidOk);
    Assert::equals(SpanStatus::Ok, $okSpans[0]->status, 'a first-attempt success writes an ok span');

    // ---- outbound payload the stub actually received carries no direct identifiers ----
    $lastReq = $okClient->lastRequest();
    Assert::that($lastReq !== null, 'the stub captured the outbound request');
    if ($lastReq !== null) {
        Assert::that(!str_contains($lastReq->userContent, 'Jane Alice Doe'), 'the request the LLM received holds no patient name');
        Assert::that(!str_contains($lastReq->userContent, 'MRN-00042'), 'the request the LLM received holds no MRN');
    }
}
