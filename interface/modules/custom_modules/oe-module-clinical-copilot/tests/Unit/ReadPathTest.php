<?php

/**
 * Isolated tests for the synthesis read path (U8).
 *
 * Guards the compute model end-to-end WITHOUT a database or the OpenEMR framework, using the
 * fixture CapabilityFactory + a StubLlmClient + InMemoryDocGateway + InMemoryTraceRecorder, with
 * capability facts injected via the read path's test seam so the digest is under exact control:
 *
 *  - E1 same facts ⇒ cache hit (reduce not re-run);
 *  - E2 a changed fact value ⇒ different digest ⇒ cache miss ⇒ a new doc;
 *  - E4 irrelevant churn (facts reordered) ⇒ same digest ⇒ cache hit;
 *  - §6.1 a capability crash ⇒ NO digest, NO ledger write, a named banner, an error span only;
 *  - I6 the LLM stub "down" ⇒ facts-only "narrative unavailable" + a degraded llm_reduce span;
 *  - §3.2 the AuditLogger spy is invoked on every view;
 *  - I12 a cache hit and a degraded read each leave spans.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityInterface;
use OpenEMR\Modules\ClinicalCopilot\Doc\InMemoryDocGateway;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Observability\InMemoryTraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Read\AuditLogger;
use OpenEMR\Modules\ClinicalCopilot\Read\ReadOutcome;
use OpenEMR\Modules\ClinicalCopilot\Read\ReadPath;
use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Reduce\StubLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

/**
 * A capability that returns a fixed fact list — the read path's fact-content control knob.
 */
final class CcStubCapability implements CapabilityInterface
{
    /** @param list<Fact> $facts */
    public function __construct(private readonly array $facts)
    {
    }

    public function forPatient(int $pid): array
    {
        return $this->facts;
    }

    public function version(): string
    {
        return 'stub@1';
    }
}

/**
 * A capability that always throws — the §6.1 crash path.
 */
final class CcThrowingCapability implements CapabilityInterface
{
    public function forPatient(int $pid): array
    {
        throw new \RuntimeException('capability data-shape surprise');
    }

    public function version(): string
    {
        return 'throwing@1';
    }
}

/**
 * A spyable AuditLogger — records every view.
 */
final class CcSpyAuditLogger implements AuditLogger
{
    /** @var list<array{pid: int, correlation_id: string}> */
    public array $calls = [];

    public function logView(int $pid, string $correlationId): void
    {
        $this->calls[] = ['pid' => $pid, 'correlation_id' => $correlationId];
    }
}

/**
 * @return list<Fact>
 */
function cc_readpath_facts(int $pid, string $rawA, float $valA, string $rawB, float $valB): array
{
    return [
        new Fact(
            Capability::ControlProxy,
            'control_proxy@1',
            FactKind::TrendPoint,
            $pid,
            '2026-01-05',
            DateSource::Collected,
            new FactValue($rawA, $valA, Comparator::None, '%', '%', 'conv@1'),
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
            new FactValue($rawB, $valB, Comparator::None, '%', '%', 'conv@1'),
            FactStatus::Final,
            [],
            [new Citation('procedure_result', 22, 'result', DateSource::Collected)],
        ),
    ];
}

function cc_readpath_reducer(StubLlmClient $client, InMemoryTraceRecorder $traces, int $maxAttempts = 2): Reducer
{
    return new Reducer(
        $client,
        new PromptAssembler(),
        new EgressRedactor(),
        $traces,
        'gemini-2.5-pro',
        'endo-reduce@1',
        $maxAttempts,
    );
}

function clinical_copilot_test_ReadPathTest(): void
{
    $pid = 42;
    $fixtures = __DIR__ . '/../Fixtures';
    $factory = CapabilityFactory::fixture($fixtures, [4203 => '4548-4', 4303 => '4548-4']);
    $verifier = new Verifier();
    $spy = new CcSpyAuditLogger();
    $views = 0;

    // A canned, non-clinical retrieval_status claim: passes V1–V6 over any conflict-free fact set.
    $cannedNarrative = 'The chart was reviewed and the available records were retrieved for this visit.';
    $canned = ['claims' => [[
        'text' => $cannedNarrative,
        'claim_type' => 'retrieval_status',
        'citation_ids' => [],
    ]]];

    $factsA = cc_readpath_facts($pid, '7.2', 7.2, '8.4', 8.4);
    $factsAReordered = array_reverse($factsA);
    $factsB = cc_readpath_facts($pid, '7.2', 7.2, '9.0', 9.0); // one value changed ⇒ new digest

    // ---- Block 1: E1 (hit), E2 (miss on change), E4 (churn is a hit) over one shared store ----
    $gateway = new InMemoryDocGateway();
    $docStore = new DocStore($gateway);
    $okClient = StubLlmClient::withCannedJson($canned);
    $reducer = cc_readpath_reducer($okClient, new InMemoryTraceRecorder());

    $readA = new ReadPath($factory, $docStore, $reducer, $verifier, new InMemoryTraceRecorder(), $spy, [new CcStubCapability($factsA)]);

    // First read over factsA: cache miss ⇒ generate + store.
    $r1 = $readA->synthesisFor($pid);
    $views++;
    Assert::equals(ReadOutcome::Generated, $r1->outcome, 'first read is a cache miss and generates a doc');
    Assert::that($r1->hasNarrative(), 'a generated read serves a narrative');
    Assert::equals($cannedNarrative, $r1->narrative, 'the served narrative is composed from the claim text');
    Assert::that($r1->checksRun !== [], 'a generated read records the verification checks that ran');
    Assert::equals(1, $okClient->generateCalls(), 'a miss calls the LLM exactly once');
    Assert::equals(1, count($gateway->historyByPid($pid)), 'the miss appended exactly one doc row');

    // Second read over the SAME facts: cache hit ⇒ served without a reduce (E1).
    $r2 = $readA->synthesisFor($pid);
    $views++;
    Assert::equals(ReadOutcome::CacheHit, $r2->outcome, 'identical facts ⇒ cache hit (E1)');
    Assert::equals($cannedNarrative, $r2->narrative, 'the cache hit serves the stored narrative');
    Assert::equals(1, $okClient->generateCalls(), 'a cache hit does NOT call the LLM again (E1)');
    Assert::equals(1, count($gateway->historyByPid($pid)), 'a cache hit writes no new row (append-only, T7)');

    // Third read over reordered facts: same digest ⇒ still a hit (E4 irrelevant churn).
    $readChurn = new ReadPath($factory, $docStore, $reducer, $verifier, new InMemoryTraceRecorder(), $spy, [new CcStubCapability($factsAReordered)]);
    $r3 = $readChurn->synthesisFor($pid);
    $views++;
    Assert::equals(ReadOutcome::CacheHit, $r3->outcome, 'reordered facts ⇒ same digest ⇒ cache hit (E4)');
    Assert::equals(1, count($gateway->historyByPid($pid)), 'irrelevant churn writes no new row (E4)');

    // Fourth read over a CHANGED fact value: new digest ⇒ miss ⇒ a second doc (E2).
    $readB = new ReadPath($factory, $docStore, $reducer, $verifier, new InMemoryTraceRecorder(), $spy, [new CcStubCapability($factsB)]);
    $r4 = $readB->synthesisFor($pid);
    $views++;
    Assert::equals(ReadOutcome::Generated, $r4->outcome, 'a changed fact value ⇒ cache miss ⇒ regenerate (E2)');
    Assert::equals(2, $okClient->generateCalls(), 'the changed-fact miss calls the LLM a second time (E2)');
    Assert::equals(2, count($gateway->historyByPid($pid)), 'the changed-fact miss appended a second doc row (E2)');

    // ---- Block 2: a cache hit leaves spans (I12) ----
    $hitTraces = new InMemoryTraceRecorder();
    $readHit = new ReadPath($factory, $docStore, $reducer, $verifier, $hitTraces, $spy, [new CcStubCapability($factsA)]);
    $rHit = $readHit->synthesisFor($pid);
    $views++;
    Assert::equals(ReadOutcome::CacheHit, $rHit->outcome, 'the span-instrumented read is a cache hit');
    $hitKinds = array_map(static fn($s) => $s->kind, $hitTraces->spans());
    Assert::that(in_array(TraceKind::Extract, $hitKinds, true), 'a cache hit still writes an extract span (I12)');
    Assert::that(in_array(TraceKind::Digest, $hitKinds, true), 'a cache hit still writes a digest span (I12)');
    Assert::that(in_array(TraceKind::CacheLookup, $hitKinds, true), 'a cache hit still writes a cache_lookup span (I12)');
    Assert::equals(3, count($hitTraces->spans()), 'a cache hit writes exactly the three read spans, no reduce/verify');

    // ---- Block 3: LLM down ⇒ facts-only "narrative unavailable" + a degraded llm_reduce span (I6) ----
    $downTraces = new InMemoryTraceRecorder();
    $downReducer = cc_readpath_reducer(StubLlmClient::down(), $downTraces);
    $downStore = new DocStore(new InMemoryDocGateway()); // fresh store ⇒ guaranteed miss
    $readDown = new ReadPath($factory, $downStore, $downReducer, $verifier, $downTraces, $spy, [new CcStubCapability($factsA)]);
    $rDown = $readDown->synthesisFor($pid);
    $views++;
    Assert::equals(ReadOutcome::FactsOnly, $rDown->outcome, 'an LLM outage on a miss degrades to facts-only (I6)');
    Assert::that($rDown->degraded, 'the facts-only result is marked degraded');
    Assert::that(!$rDown->hasNarrative(), 'no narrative is served when the LLM is down');
    Assert::equals('narrative unavailable', $rDown->narrativeUnavailableReason, 'degraded read is marked "narrative unavailable" (I6)');
    Assert::equals(2, $rDown->facts->count(), 'the facts survive the LLM outage');
    $downStatuses = [];
    foreach ($downTraces->spans() as $s) {
        if ($s->kind === TraceKind::LlmReduce) {
            $downStatuses[] = $s->status;
        }
    }
    Assert::that(in_array(SpanStatus::Degraded, $downStatuses, true), 'the degraded read leaves a degraded llm_reduce span (I12)');
    $downKinds = array_map(static fn($s) => $s->kind, $downTraces->spans());
    Assert::that(in_array(TraceKind::Extract, $downKinds, true), 'the degraded read still writes an extract span');
    Assert::that(in_array(TraceKind::CacheLookup, $downKinds, true), 'the degraded read still writes a cache_lookup span');

    // ---- Block 4: a capability crash ⇒ no digest, no ledger write, named banner, error span (§6.1) ----
    $crashTraces = new InMemoryTraceRecorder();
    $crashGateway = new InMemoryDocGateway();
    $crashCaps = [new CcStubCapability($factsA), new CcThrowingCapability()];
    $readCrash = new ReadPath(
        $factory,
        new DocStore($crashGateway),
        $reducer,
        $verifier,
        $crashTraces,
        $spy,
        $crashCaps,
    );
    $rCrash = $readCrash->synthesisFor($pid);
    $views++;
    Assert::equals(ReadOutcome::Paused, $rCrash->outcome, 'a capability crash pauses synthesis (§6.1)');
    Assert::that($rCrash->banner !== null && str_contains($rCrash->banner, 'unavailable — synthesis paused'), 'a paused read carries the named banner');
    Assert::that($rCrash->banner !== null && str_contains($rCrash->banner, 'CcThrowingCapability'), 'the banner names the crashed capability');
    Assert::equals(2, $rCrash->facts->count(), 'the surviving capability\'s facts still render');
    Assert::equals(0, count($crashGateway->historyByPid($pid)), 'a crash writes NO doc row — synthesis over a partial set is never stored (§6.1)');
    Assert::equals(1, count($crashTraces->spans()), 'a crash records exactly one span (the failed extract) — no digest/cache_lookup');
    Assert::equals(TraceKind::Extract, $crashTraces->spans()[0]->kind, 'the single crash span is the extract span');
    Assert::equals(SpanStatus::Error, $crashTraces->spans()[0]->status, 'the extract span is recorded as an error carrying the correlation id');
    Assert::that($crashTraces->spans()[0]->correlationId === $rCrash->correlationId, 'the error span carries the read\'s correlation id (I12)');

    // ---- Block 5: the AuditLogger spy fired on EVERY view (§3.2) ----
    Assert::equals($views, count($spy->calls), 'the audit logger is invoked exactly once per view');
    Assert::that($spy->calls[0]['correlation_id'] === $r1->correlationId, 'the audit entry carries the read\'s correlation id (R2)');
    Assert::that($spy->calls[0]['pid'] === $pid, 'the audit entry is scoped to the viewed patient');
}
