<?php

/**
 * Isolated tests for the warm Worker (U9) — the pure warm-decision core plus a fixture-backed sweep.
 *
 * Guards, all WITHOUT a database or the OpenEMR framework:
 *  - the pure statics: warmNeeded (regenerate only on digest miss), withinBudget, consumesLlm;
 *  - idempotency: a present doc for (pid, digest) ⇒ the warm is a cache hit ⇒ NO further LLM call;
 *  - the per-tick LLM budget caps the number of warm generations; over-budget cold patients fall back;
 *  - every tick writes a heartbeat `warm` span (even an empty window) and, given inputs, an alert_eval
 *    span, logging firing alerts at error (§3.5);
 *  - I7: a cold read computes fresh facts with NO worker present and consults no warm/worker span —
 *    reads never depend on the worker, so a dead worker degrades latency, never correctness.
 *
 * A tiny Psr\Log\LoggerInterface shim is declared only when the real one is absent (this low-dep
 * runner has no Composer vendor tree); in the full stack the real interface is used unchanged.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace Psr\Log {
    if (!\interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
            public function emergency($message, $context = []): void;
            public function alert($message, $context = []): void;
            public function critical($message, $context = []): void;
            public function error($message, $context = []): void;
            public function warning($message, $context = []): void;
            public function notice($message, $context = []): void;
            public function info($message, $context = []): void;
            public function debug($message, $context = []): void;
            public function log($level, $message, $context = []): void;
        }
    }
}

namespace {

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
    use OpenEMR\Modules\ClinicalCopilot\Observability\AlertInputs;
    use OpenEMR\Modules\ClinicalCopilot\Observability\InMemoryTraceRecorder;
    use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
    use OpenEMR\Modules\ClinicalCopilot\Read\ReadOutcome;
    use OpenEMR\Modules\ClinicalCopilot\Read\ReadPath;
    use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
    use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
    use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
    use OpenEMR\Modules\ClinicalCopilot\Reduce\StubLlmClient;
    use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;
    use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
    use OpenEMR\Modules\ClinicalCopilot\Worker;

    /**
     * A capability that returns one fact for whatever pid is requested, with a pid-dependent value so
     * each patient has a distinct digest (⇒ every patient is a cold miss until warmed).
     */
    final class CcWorkerStubCapability implements CapabilityInterface
    {
        public function forPatient(int $pid): array
        {
            return [
                new Fact(
                    Capability::ControlProxy,
                    'control_proxy@1',
                    FactKind::TrendPoint,
                    $pid,
                    '2026-01-05',
                    DateSource::Collected,
                    new FactValue((string) $pid, (float) $pid, Comparator::None, '%', '%', 'conv@1'),
                    FactStatus::Final,
                    [],
                    [new Citation('procedure_result', $pid, 'result', DateSource::Collected)],
                ),
            ];
        }

        public function version(): string
        {
            return 'stub@1';
        }
    }

    /**
     * An in-memory PSR-3 logger that counts error()-level calls (the §3.5 alert-log assertion).
     */
    final class CcArrayLogger implements \Psr\Log\LoggerInterface
    {
        public int $errorCount = 0;

        /** @var list<array{level: string, message: mixed}> */
        public array $entries = [];

        public function emergency($message, $context = []): void
        {
            $this->record('emergency', $message);
        }

        public function alert($message, $context = []): void
        {
            $this->record('alert', $message);
        }

        public function critical($message, $context = []): void
        {
            $this->record('critical', $message);
        }

        public function error($message, $context = []): void
        {
            $this->errorCount++;
            $this->record('error', $message);
        }

        public function warning($message, $context = []): void
        {
            $this->record('warning', $message);
        }

        public function notice($message, $context = []): void
        {
            $this->record('notice', $message);
        }

        public function info($message, $context = []): void
        {
            $this->record('info', $message);
        }

        public function debug($message, $context = []): void
        {
            $this->record('debug', $message);
        }

        public function log($level, $message, $context = []): void
        {
            $this->record((string) $level, $message);
        }

        private function record(string $level, mixed $message): void
        {
            $this->entries[] = ['level' => $level, 'message' => $message];
        }
    }

    function cc_worker_reducer(StubLlmClient $client, InMemoryTraceRecorder $traces): Reducer
    {
        return new Reducer(
            $client,
            new PromptAssembler(),
            new EgressRedactor(),
            $traces,
            'gemini-2.5-pro',
            'endo-reduce@1',
            2,
        );
    }

    /**
     * @param array<string, mixed> $canned
     * @param list<CapabilityInterface> $caps
     */
    function cc_worker_readpath(
        CapabilityFactory $factory,
        DocStore $store,
        StubLlmClient $client,
        Verifier $verifier,
        array $caps,
        InMemoryTraceRecorder $traces,
    ): ReadPath {
        return new ReadPath(
            $factory,
            $store,
            cc_worker_reducer($client, $traces),
            $verifier,
            $traces,
            null,
            $caps,
        );
    }

    function clinical_copilot_test_WorkerTest(): void
    {
        // ---- Block 0: the pure warm-decision statics (no DB, no ReadPath) ----
        Assert::that(Worker::warmNeeded(false), 'a digest miss ⇒ warming is needed');
        Assert::that(!Worker::warmNeeded(true), 'a present doc for (pid,digest) ⇒ warming is NOT needed (idempotent)');

        Assert::that(Worker::withinBudget(0, 1), 'a fresh tick has budget for its first generation');
        Assert::that(!Worker::withinBudget(1, 1), 'budget is exhausted once used == budget');
        Assert::that(!Worker::withinBudget(0, 0), 'a non-positive budget disables warm generation');

        Assert::that(!Worker::consumesLlm(ReadOutcome::CacheHit), 'a cache hit consumes no LLM');
        Assert::that(!Worker::consumesLlm(ReadOutcome::Paused), 'a capability crash (paused) consumes no LLM');
        Assert::that(Worker::consumesLlm(ReadOutcome::Generated), 'a generated doc consumed one LLM call');
        Assert::that(Worker::consumesLlm(ReadOutcome::FactsOnly), 'a facts-only miss consumed an LLM attempt');
        Assert::that(Worker::consumesLlm(ReadOutcome::Frozen), 'a frozen (miss) outcome consumed an LLM call');

        $fixtures = __DIR__ . '/../Fixtures';
        $factory = CapabilityFactory::fixture($fixtures, [4203 => '4548-4', 4303 => '4548-4']);
        $verifier = new Verifier();
        $caps = [new CcWorkerStubCapability()];

        // A canned retrieval_status claim that passes V1–V6 over any conflict-free fact set.
        $cannedText = 'The chart was reviewed and the available records were retrieved for this visit.';
        $canned = ['claims' => [[
            'text' => $cannedText,
            'claim_type' => 'retrieval_status',
            'citation_ids' => [],
        ]]];

        // ---- Block 1: idempotency — a present doc ⇒ NO further LLM call ----
        $gateway = new InMemoryDocGateway();
        $store = new DocStore($gateway);
        $client = StubLlmClient::withCannedJson($canned);
        $readPath = cc_worker_readpath($factory, $store, $client, $verifier, $caps, new InMemoryTraceRecorder());
        $worker = new Worker($readPath, new InMemoryTraceRecorder(), new CcArrayLogger(), 25);

        $s1 = $worker->tick([7], null, '2026-07-07T08:00:00.000Z');
        Assert::equals(1, $s1['generated'], 'the first warm of a cold patient generates a doc');
        Assert::equals(1, $client->generateCalls(), 'the first warm calls the LLM exactly once');

        $s2 = $worker->tick([7], null, '2026-07-07T08:05:00.000Z');
        Assert::equals(0, $s2['generated'], 're-warming an already-warm patient generates nothing (idempotent)');
        Assert::equals(1, $s2['cache_hits'], 're-warming records a cache hit');
        Assert::equals(1, $client->generateCalls(), 'a present doc ⇒ NO further LLM call (warmNeeded=false)');

        // ---- Block 2: the per-tick budget caps generations; over-budget cold patients fall back ----
        $client2 = StubLlmClient::withCannedJson($canned);
        $readPath2 = cc_worker_readpath(
            $factory,
            new DocStore(new InMemoryDocGateway()),
            $client2,
            $verifier,
            $caps,
            new InMemoryTraceRecorder(),
        );
        $worker2 = new Worker($readPath2, new InMemoryTraceRecorder(), new CcArrayLogger(), 1); // budget = 1
        $sb = $worker2->tick([11, 12, 13], null, '2026-07-07T08:00:00.000Z');
        Assert::equals(1, $sb['generated'], 'the per-tick budget caps warm generations at 1');
        Assert::equals(2, $sb['fell_back'], 'the two over-budget cold patients fall back to read-time');
        Assert::equals(1, $client2->generateCalls(), 'a 3-patient churn storm still called the LLM exactly once (cap held)');

        // ---- Block 3: an empty-window tick still writes a heartbeat warm span (§3.5) ----
        $hbTraces = new InMemoryTraceRecorder();
        $worker3 = new Worker($readPath2, $hbTraces, new CcArrayLogger(), 25);
        $worker3->tick([], null, '2026-07-07T08:10:00.000Z');
        $warmSpans = array_filter($hbTraces->spans(), static fn($s) => $s->kind === TraceKind::Warm);
        Assert::that(count($warmSpans) >= 1, 'an empty-window tick still writes a heartbeat warm span (dead-mans-switch input)');

        // ---- Block 4: alerts fire on the tick, are logged at error, and leave an alert_eval span ----
        $alTraces = new InMemoryTraceRecorder();
        $alLogger = new CcArrayLogger();
        $worker4 = new Worker($readPath2, $alTraces, $alLogger, 25);
        // wrong-patient trip ⇒ Sev-1 fires; heartbeat age 5s vs tick 5s ⇒ fresh (no heartbeat alert).
        $inputs = new AlertInputs(1, 1000, 0.0, 0.0, 0.0, 1.0, 5.0, 10.0, 100.0, 5, 5);
        $sa = $worker4->tick([], $inputs, '2026-07-07T08:15:00.000Z');
        Assert::that($sa['alerts_fired'] >= 1, 'a wrong-patient trip fires at least one alert on the tick (§3.5)');
        Assert::that($alLogger->errorCount >= 1, 'a firing alert logs at error severity (§3.5)');
        $alKinds = array_map(static fn($s) => $s->kind, $alTraces->spans());
        Assert::that(in_array(TraceKind::AlertEval, $alKinds, true), 'the tick writes an alert_eval span');

        // No inputs ⇒ alert evaluation is skipped entirely.
        $sn = (new Worker($readPath2, new InMemoryTraceRecorder(), new CcArrayLogger(), 25))
            ->tick([], null, '2026-07-07T08:20:00.000Z');
        Assert::equals(0, $sn['alerts_fired'], 'absent alert inputs ⇒ alert evaluation is skipped');

        // ---- Block 5: I7 — a cold read yields fresh facts with NO worker, consulting no warm span ----
        $deadTraces = new InMemoryTraceRecorder();
        $coldReadPath = cc_worker_readpath(
            $factory,
            new DocStore(new InMemoryDocGateway()),
            StubLlmClient::withCannedJson($canned),
            $verifier,
            $caps,
            $deadTraces,
        );
        $cold = $coldReadPath->synthesisFor(99); // no Worker constructed anywhere
        Assert::that($cold->facts->count() > 0, 'a cold read computes fresh facts with no worker present (I7)');
        $readKinds = array_map(static fn($s) => $s->kind, $deadTraces->spans());
        Assert::that(!in_array(TraceKind::Warm, $readKinds, true), 'the read path consults NO warm/worker span — reads never depend on the worker (I7)');
    }
}
