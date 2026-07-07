<?php

/**
 * ReadPath — the synthesis read path (ARCHITECTURE_COMPLETE.md "Compute model" READ PATH).
 *
 * One entry point, synthesisFor(pid), implementing the whole compute model in order: mint a
 * correlation id, extract every capability's facts fresh (I2), digest them, look the digest up in
 * the doc store, and on a miss reduce → verify → act. The load-bearing rules are enforced here,
 * not assumed:
 *
 *  - CAPABILITY-CRASH (§6.1): if ANY capability throws during extraction, there is NO digest and
 *    NO ledger write — a synthesis is never computed over a partial fact set. Surviving facts
 *    render under a named banner and the extract span is recorded as an error carrying the
 *    correlation id. A domain silently missing all its facts reads as "nothing notable there"
 *    (the exact I5 failure); refusing to synthesize is the correct move.
 *  - DEGRADATION (I6): LLM unavailable after the reducer's retries ⇒ facts-only, "narrative
 *    unavailable". The physician never loses the facts to an LLM outage.
 *  - FAIL-CLOSED (I11): the verifier's recommendedAction is obeyed exactly — Pass renders,
 *    Regenerate retries once then Discards, Discard falls back to facts-only, Freeze is the SEV-1
 *    patient-guard trip (facts-only + sev-1 signal).
 *  - OBSERVABILITY (I12): every path leaves spans — extract, digest, cache_lookup on every read,
 *    plus verify on a miss and the reducer's own llm_reduce span; a cache hit and a degraded read
 *    each still leave a trace.
 *
 * Digests are addressed, not checked (T5): staleness is structurally unreachable because different
 * facts or versions produce a different digest and therefore a different cache slot.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityInterface;
use OpenEMR\Modules\ClinicalCopilot\Doc\CopilotDoc;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\Digest;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Fact\VersionBundle;
use OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Observability\Span;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceResult;
use OpenEMR\Modules\ClinicalCopilot\SynthesisVersions;
use OpenEMR\Modules\ClinicalCopilot\Verify\FailureAction;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationVerdict;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

final class ReadPath
{
    private const REGENERATION_LIMIT = 2; // the original attempt + exactly one permitted retry

    private readonly AuditLogger $audit;
    private readonly EgressRedactor $redactor;
    private readonly Digest $digest;
    private readonly CanonicalSerializer $serializer;

    /** @var list<CapabilityInterface>|null test seam: overrides the factory's capability list */
    private readonly ?array $capabilitiesOverride;

    /**
     * @param list<CapabilityInterface>|null $capabilitiesOverride test-only: inject an explicit
     *                                                             capability list (e.g. a throwing
     *                                                             capability for the §6.1 crash
     *                                                             path, or stubs with controlled
     *                                                             facts for digest evals)
     */
    public function __construct(
        private readonly CapabilityFactory $factory,
        private readonly DocStore $docStore,
        private readonly \OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer $reducer,
        private readonly Verifier $verifier,
        private readonly TraceRecorder $traces,
        ?AuditLogger $audit = null,
        ?array $capabilitiesOverride = null,
        ?EgressRedactor $redactor = null,
        ?Digest $digest = null,
        ?CanonicalSerializer $serializer = null,
    ) {
        $this->audit = $audit ?? new NullAuditLogger();
        $this->capabilitiesOverride = $capabilitiesOverride;
        $this->redactor = $redactor ?? new EgressRedactor();
        $this->digest = $digest ?? new Digest();
        $this->serializer = $serializer ?? new CanonicalSerializer();
    }

    /**
     * Compute (or serve) the synthesis document for one patient, and audit the view (§3.2). Never
     * throws for a capability failure or an LLM outage — those degrade to a facts-only result with
     * a banner (§6.1/I6). The audit fires on EVERY served path, degraded or not: a view is a view.
     */
    public function synthesisFor(int $pid, ?PatientContext $context = null, ?int $userId = null): SynthesisResult
    {
        $result = $this->compute($pid, $context, $userId);
        $this->audit->logView($pid, $result->correlationId);

        return $result;
    }

    private function compute(int $pid, ?PatientContext $context, ?int $userId): SynthesisResult
    {
        $context ??= new PatientContext($pid);
        $correlationId = CorrelationId::mint();

        // 1. Extract every capability fresh (I2). A single crash pauses synthesis (§6.1).
        $extractSpan = $this->openSpan($correlationId, TraceKind::Extract, $pid, $userId);
        $started = microtime(true);
        $facts = [];
        foreach ($this->capabilitiesOverride ?? $this->factory->all() as $capability) {
            try {
                foreach ($capability->forPatient($pid) as $fact) {
                    $facts[] = $fact;
                }
            } catch (\Throwable $e) {
                // NO digest, NO ledger write — the surviving facts render under a named banner.
                $extractSpan->failWith($e, $this->elapsedMs($started));
                $this->traces->record($extractSpan);

                return SynthesisResult::paused(
                    new FactSet($pid, $facts),
                    $correlationId,
                    $this->capabilityName($capability),
                );
            }
        }
        $extractSpan->close(SpanStatus::Ok, $this->elapsedMs($started));
        $this->traces->record($extractSpan);

        $factSet = new FactSet($pid, $facts);

        // 2. Digest the complete fact set against the pinned version bundle.
        $bundle = SynthesisVersions::bundle($this->factory);
        $digestSpan = $this->openSpan($correlationId, TraceKind::Digest, $pid, $userId);
        $digestStarted = microtime(true);
        $digest = $this->digest->compute($factSet->facts, $bundle);
        $digestSpan->close(SpanStatus::Ok, $this->elapsedMs($digestStarted));
        $this->traces->record($digestSpan);

        // 3. Cache lookup by content address (pid, digest).
        $lookupSpan = $this->openSpan($correlationId, TraceKind::CacheLookup, $pid, $userId);
        $lookupStarted = microtime(true);
        $stored = $this->docStore->findByPidAndDigest($pid, $digest);
        $lookupSpan->close(SpanStatus::Ok, $this->elapsedMs($lookupStarted));
        $this->traces->record($lookupSpan);

        if ($stored !== null) {
            return $this->serveStored($factSet, $stored, $correlationId);
        }

        // 4. Miss → reduce, verify, act.
        return $this->generate($factSet, $context, $bundle, $digest, $correlationId, $userId);
    }

    /**
     * The append-only history of every doc served for a patient, oldest first (ORDER BY
     * computed_at). The doc page's history view renders these with citation click-through.
     *
     * @return list<CopilotDoc>
     */
    public function history(int $pid): array
    {
        return $this->docStore->history($pid);
    }

    /**
     * Serve a stored doc on a cache hit: fresh facts (I2) + the stored, already-re-hydrated
     * narrative and its recorded verdict badge.
     */
    private function serveStored(FactSet $factSet, CopilotDoc $stored, string $correlationId): SynthesisResult
    {
        $content = DocContent::fromJson($stored->doc);

        return SynthesisResult::cacheHit(
            $factSet,
            $correlationId,
            $content->narrative,
            CheckSummary::listFromArray($content->verdict),
            $stored->computedAt,
        );
    }

    /**
     * Reduce and verify, obeying the verifier's fail-closed action (I11). At most one regeneration.
     */
    private function generate(
        FactSet $factSet,
        PatientContext $context,
        VersionBundle $bundle,
        string $digest,
        string $correlationId,
        ?int $userId,
    ): SynthesisResult {
        $regenerationUsed = false;
        $retried = false;
        $lastVerdict = null;

        for ($attempt = 0; $attempt < self::REGENERATION_LIMIT; $attempt++) {
            $reduce = $this->reducer->reduce($factSet, $context, $correlationId, $correlationId, $userId);

            if ($reduce->isDegraded() || $reduce->rawOutput === null) {
                // LLM unavailable after the reducer's own retries (I6).
                return SynthesisResult::factsOnly(
                    $factSet,
                    $correlationId,
                    ReduceResult::NARRATIVE_UNAVAILABLE,
                    $retried,
                    true,
                    $lastVerdict,
                );
            }

            $verifySpan = $this->openSpan($correlationId, TraceKind::Verify, $factSet->pid, $userId);
            $verifyStarted = microtime(true);
            $verdict = $this->verifier->verifyResponse($reduce->rawOutput, $factSet, $factSet->pid, true);
            $lastVerdict = $verdict;
            $verifySpan->close($verdict->passed ? SpanStatus::Ok : SpanStatus::Error, $this->elapsedMs($verifyStarted));
            $this->traces->record($verifySpan);

            $action = $verdict->recommendedAction($regenerationUsed);
            switch ($action) {
                case FailureAction::Pass:
                    return $this->store($factSet, $reduce, $verdict, $bundle, $digest, $correlationId, $retried);
                case FailureAction::Regenerate:
                    $regenerationUsed = true;
                    $retried = true;
                    continue 2; // exactly one more attempt
                case FailureAction::Discard:
                    return SynthesisResult::factsOnly(
                        $factSet,
                        $correlationId,
                        ReduceResult::NARRATIVE_UNAVAILABLE,
                        $retried,
                        false,
                        $verdict,
                    );
                case FailureAction::Freeze:
                    return SynthesisResult::frozen($factSet, $correlationId, $verdict, $retried);
            }
        }

        // Regeneration exhausted without a pass ⇒ facts-only (I11).
        return SynthesisResult::factsOnly(
            $factSet,
            $correlationId,
            ReduceResult::NARRATIVE_UNAVAILABLE,
            $retried,
            false,
            $lastVerdict,
        );
    }

    /**
     * Build the CopilotDoc for a passed narrative, re-hydrate its identifiers (§4), append it to
     * the store, and return the served result.
     */
    private function store(
        FactSet $factSet,
        ReduceResult $reduce,
        VerificationVerdict $verdict,
        VersionBundle $bundle,
        string $digest,
        string $correlationId,
        bool $retried,
    ): SynthesisResult {
        $raw = $reduce->rawOutput;
        // rawOutput is guaranteed non-null here (checked by the caller), asserted for the analyzer.
        if ($raw === null) {
            return SynthesisResult::factsOnly($factSet, $correlationId, ReduceResult::NARRATIVE_UNAVAILABLE, $retried, false, $verdict);
        }

        $claims = $this->claimList($raw->json);
        $narrativeTokenized = $this->composeNarrative($claims);
        $narrative = $this->redactor->rehydrate($narrativeTokenized, $reduce->redactionMap);

        $content = new DocContent(
            $narrative,
            $claims,
            $this->serializer->canonicalize($factSet->facts),
            $verdict->toArray(),
        );

        $computedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $doc = new CopilotDoc(
            pid: $factSet->pid,
            factDigest: $digest,
            docType: $bundle->docType,
            apptId: null,
            doc: $content->toJson(),
            capabilityVersions: (string) json_encode($bundle->capabilityVersions, JSON_UNESCAPED_SLASHES),
            promptVersion: $bundle->promptVersion,
            computedAt: $computedAt,
            correlationId: $correlationId,
            llmLatencyMs: $raw->latencyMs,
            tokensIn: $raw->tokensIn,
            tokensOut: $raw->tokensOut,
            costUsd: null,
            excludedCounts: (string) json_encode($this->exclusionCounts($factSet->facts), JSON_UNESCAPED_SLASHES),
        );

        $this->docStore->store($doc);

        return SynthesisResult::generated($factSet, $correlationId, $narrative, $verdict, $doc, $retried);
    }

    /**
     * The model's claim objects, defensively narrowed from the raw payload.
     *
     * @param array<string, mixed> $json
     *
     * @return list<array<string, mixed>>
     */
    private function claimList(array $json): array
    {
        $claims = $json['claims'] ?? null;
        if (!is_array($claims)) {
            return [];
        }
        $out = [];
        foreach ($claims as $claim) {
            if (is_array($claim)) {
                $out[] = $claim;
            }
        }
        return $out;
    }

    /**
     * Compose the served narrative from the claim texts, in the order the model emitted them. The
     * verifier has already gated every one; this is pure presentation.
     *
     * @param list<array<string, mixed>> $claims
     */
    private function composeNarrative(array $claims): string
    {
        $parts = [];
        foreach ($claims as $claim) {
            $text = $claim['text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Per-reason exclusion counts, persisted on the doc row for auditability.
     *
     * @param list<Fact> $facts
     *
     * @return array<string, int>
     */
    private function exclusionCounts(array $facts): array
    {
        $counts = [];
        foreach ($facts as $fact) {
            if (!$fact->isExclusion()) {
                continue;
            }
            $reason = 'unspecified';
            foreach ($fact->flags as $flag) {
                if (str_starts_with($flag, 'excluded_reason:')) {
                    $reason = substr($flag, strlen('excluded_reason:'));
                    break;
                }
            }
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        return $counts;
    }

    private function openSpan(string $correlationId, TraceKind $kind, int $pid, ?int $userId): Span
    {
        $startedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        return $this->traces->start($correlationId, $kind, $startedAt, null, $pid, $userId);
    }

    private function elapsedMs(float $startMicro): int
    {
        return (int) round((microtime(true) - $startMicro) * 1000.0);
    }

    /**
     * The display name for a crashed capability's banner ("VitalsTrend unavailable — synthesis
     * paused"): the concrete capability's short class name, which matches the spec's banner names.
     */
    private function capabilityName(object $capability): string
    {
        $class = $capability::class;
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
