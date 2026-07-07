<?php

/**
 * The compute-model READ PATH orchestrator (ARCHITECTURE_COMPLETE.md "Compute model").
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityInterface;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\DbLabTurnaroundConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\LabTurnaroundConfigProviderInterface;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Doc\DocRow;
use OpenEMR\Modules\ClinicalCopilot\Doc\NewDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\RegenReason;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\Digest;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\DbLabContractConfigProvider;
use OpenEMR\Modules\ClinicalCopilot\Lab\Config\LabContractConfigProviderInterface;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\RedactionMap;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Redactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\ReduceRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Services\PrescriptionService;
use OpenEMR\Modules\ClinicalCopilot\Verify\SessionFactSet;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationContext;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPath;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGeneration;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerifiedGenerationRequest;
use Ramsey\Uuid\Uuid;

/**
 * Implements ARCHITECTURE_COMPLETE.md's READ PATH exactly:
 *
 *   mint correlation_id (I12)
 *   extract facts fresh (I2) -- capability-crash rule: any capability
 *     throwing means NO digest, NO ledger write (ARCHITECTURE.md §6.1)
 *   canonicalize -> digest (I1)
 *   lookup (pid, digest): hit -> serve stored doc, NO LLM call
 *                          miss -> VerifiedGeneration (U10) -> DocStore::insert() -> serve
 *
 * This is the ONE entry point U9's worker warm and U11's chat preload reuse
 * for triggering/reading a synthesis: {@see self::read()} for a normal
 * view/warm (digest miss -> a fresh, unforced attempt), {@see self::regenerate()}
 * for the physician's manual Regenerate button (T22 -- always a fresh
 * attempt, `regen_reason='manual'`, never a cache-hit shortcut, best-of-N
 * re-selected afterward). U9's worker warm should call {@see self::read()}
 * exactly as this controller does (a warm IS a read, just triggered by a
 * schedule instead of a browser); U11's chat preload reads the SAME
 * `mod_copilot_doc` row this class serves (via {@see DocStore::findBest()})
 * to seed its session fact set + narrative, without needing to run this
 * class itself mid-chat (ARCHITECTURE_COMPLETE.md's CHAT PATH re-extracts
 * per tool call instead, per capability, not via this whole orchestrator).
 *
 * Never calls {@see Reducer} or {@see Verifier} directly -- only
 * {@see VerifiedGeneration} (ARCHITECTURE_COMPLETE.md's U8 row).
 */
final class SynthesisReadPath
{
    private const DOC_TYPE = 'endo-previsit-v1';

    /**
     * Versions the LOINC/analyte code-set mapping itself
     * ({@see \OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets}) --
     * a digest input distinct from any single capability's own version.
     * U5 does not currently expose a formal version constant for that
     * mapping; this is the caller-supplied value {@see Digest::compute()}'s
     * `$codeSetVersion` parameter requires until one exists there to defer
     * to instead.
     */
    private const CODE_SET_VERSION = '1';

    private const PROMPT_VERSION = 'reduce-v1';
    private const MODEL = 'gemini-2.5-pro';

    /**
     * @param list<CapabilityInterface> $capabilities all five (ARCHITECTURE_COMPLETE.md "Capabilities"), run fresh on every read (I2)
     */
    public function __construct(
        private readonly array $capabilities,
        private readonly LabContractConfigProviderInterface $labContractConfigProvider,
        private readonly LabTurnaroundConfigProviderInterface $labTurnaroundConfigProvider,
        private readonly DocStore $docStore,
        private readonly VerifiedGeneration $verifiedGeneration,
        private readonly PatientIdentifierLookup $identifierLookup,
        private readonly Redactor $redactor,
        private readonly TraceRecorderInterface $tracer = new NullTraceRecorder(),
        private readonly AlertSinkInterface $alertSink = new NullAlertSink(),
    ) {
    }

    /**
     * Wires the real, DB/Vertex-backed collaborators. `public/doc.php`, and
     * eventually U9's worker entry point and U11's chat preload, all build
     * their read path this way -- one composition root, reused everywhere
     * this orchestration is needed.
     */
    public static function createDefault(
        TraceRecorderInterface $tracer = new NullTraceRecorder(),
        AlertSinkInterface $alertSink = new LoggingAlertSink(),
    ): self {
        $labContractConfigProvider = new DbLabContractConfigProvider();
        $labSliceReader = new LabSliceReader($labContractConfigProvider);
        $turnaroundConfigProvider = new DbLabTurnaroundConfigProvider();

        $capabilities = [
            new ControlProxy($labSliceReader),
            new MedResponse(new PrescriptionService(), $labSliceReader),
            new VitalsTrend(),
            new OverdueTests($labSliceReader, $labContractConfigProvider, ServiceContainer::getClock()),
            new PendingResults($labSliceReader, $turnaroundConfigProvider),
        ];

        $reducer = new Reducer(LlmClientFactory::create(), new PromptAssembler(), new Redactor());
        $verifiedGeneration = new VerifiedGeneration($reducer, new Verifier());

        return new self(
            $capabilities,
            $labContractConfigProvider,
            $turnaroundConfigProvider,
            new DocStore(),
            $verifiedGeneration,
            new PatientIdentifierLookup(),
            new Redactor(),
            $tracer,
            $alertSink,
        );
    }

    /**
     * Normal view/warm: a digest hit serves the stored doc with NO LLM
     * call; a miss runs one unforced reduce+verify attempt
     * (`regen_reason='none'` or `'verify_retry'`, per U10's own retry).
     */
    public function read(int $pid, ?int $userId): SynthesisReadResult
    {
        return $this->run($pid, $userId, self::mintCorrelationId(), forceRegenerate: false);
    }

    /**
     * T22 manual Regenerate: always a fresh reduce+verify attempt over the
     * CURRENT fresh facts (`regen_reason='manual'`), even when a passed doc
     * already exists for this exact digest -- append-only (a new row, never
     * a mutation), best-of-N re-selected via {@see DocStore::findBest()}
     * immediately after the insert.
     */
    public function regenerate(int $pid, ?int $userId): SynthesisReadResult
    {
        return $this->run($pid, $userId, self::mintCorrelationId(), forceRegenerate: true);
    }

    private function run(int $pid, ?int $userId, string $correlationId, bool $forceRegenerate): SynthesisReadResult
    {
        $extraction = $this->extractAll($pid, $correlationId, $userId);

        if ($extraction->crashed) {
            $this->recordSpan($correlationId, 'render', microtime(true), 'degraded', $pid, $userId);

            return SynthesisReadResult::capabilityCrash($correlationId, $pid, $extraction->survivingFacts, $extraction->crashBanner());
        }

        $digestT0 = microtime(true);
        $labConfig = $this->labContractConfigProvider->load();
        $turnaroundConfig = $this->labTurnaroundConfigProvider->load();
        $configVersions = ConfigVersionSnapshot::build($labConfig, $turnaroundConfig);

        $digest = Digest::compute(
            $extraction->survivingFacts,
            $extraction->capabilityVersions,
            $configVersions,
            self::CODE_SET_VERSION,
            self::DOC_TYPE,
            self::digestPromptVersion(),
        );
        $this->recordSpan($correlationId, 'digest', $digestT0, 'ok', $pid, $userId);

        if (!$forceRegenerate) {
            $cacheT0 = microtime(true);
            $existing = $this->docStore->findBest($pid, $digest);
            $this->recordSpan($correlationId, 'cache_lookup', $cacheT0, 'ok', $pid, $userId);

            if ($existing !== null) {
                $this->recordSpan($correlationId, 'render', microtime(true), $existing->verifyStatus === VerifyStatus::Passed ? 'ok' : 'degraded', $pid, $userId);

                return $this->toServedResult($correlationId, $pid, $extraction->survivingFacts, $existing, servedFromCache: true);
            }
        }

        $best = $this->generateAndInsert($pid, $userId, $correlationId, $digest, $extraction, $forceRegenerate);

        $this->recordSpan($correlationId, 'render', microtime(true), $best->verifyStatus === VerifyStatus::Passed ? 'ok' : 'degraded', $pid, $userId);

        return $this->toServedResult($correlationId, $pid, $extraction->survivingFacts, $best, servedFromCache: false);
    }

    private function generateAndInsert(
        int $pid,
        ?int $userId,
        string $correlationId,
        string $digest,
        ExtractionOutcome $extraction,
        bool $forceRegenerate,
    ): DocRow {
        $identifiers = $this->identifierLookup->forPid($pid) ?? new PatientIdentifiers('', '', '', '');

        $reduceRequest = new ReduceRequest(
            "doc:{$pid}",
            $correlationId,
            $extraction->survivingFacts,
            $identifiers,
            new PromptContext(self::DOC_TYPE, self::digestPromptVersion(), self::MODEL),
        );
        $verificationContext = new VerificationContext(
            new SessionFactSet($pid, $extraction->survivingFacts),
            VerificationPath::Synthesis,
        );

        $llmT0 = microtime(true);
        $result = $this->verifiedGeneration->generate(new VerifiedGenerationRequest($reduceRequest, $verificationContext));

        $this->recordSpan(
            $correlationId,
            'llm_reduce',
            $llmT0,
            match (true) {
                $result->verifyStatus === VerifyStatus::Degraded => 'degraded',
                $result->attempts > 1 => 'retried',
                default => 'ok',
            },
            $pid,
            $userId,
            model: $result->usage->modelVersion,
            tokensIn: $result->usage->tokensIn,
            tokensOut: $result->usage->tokensOut,
        );
        $this->recordSpan(
            $correlationId,
            'verify',
            $llmT0,
            $result->verifyStatus === VerifyStatus::Passed ? 'ok' : 'degraded',
            $pid,
            $userId,
        );

        if ($result->frozen && $result->sev1Signal !== null) {
            $this->alertSink->sev1PatientIdentity($result->sev1Signal);
        }

        $rehydratedClaims = $this->rehydrateClaims($result->claims, $result->redactionMap);

        $docPayload = SynthesisDocPayload::build(
            $extraction->survivingFacts,
            $rehydratedClaims,
            $result->verifyStatus,
            $result->degradedReason,
            $result->degradedMessage,
            $result->verdicts,
            $result->attempts,
        );

        $newDoc = new NewDoc(
            $pid,
            $digest,
            self::DOC_TYPE,
            null,
            $docPayload,
            $extraction->capabilityVersions,
            self::digestPromptVersion(),
            $correlationId,
            $result->verifyStatus,
            $forceRegenerate ? RegenReason::Manual : $result->regenReason,
            $result->usage->latencyMs,
            $result->usage->tokensIn,
            $result->usage->tokensOut,
            null,
            $extraction->excludedCounts,
        );

        $this->docStore->insert($newDoc);

        $best = $this->docStore->findBest($pid, $digest);
        if ($best === null) {
            // Unreachable in practice: a row for this exact (pid, digest)
            // was just inserted above. Fail loudly rather than silently
            // serve a phantom/empty result if that invariant is ever wrong.
            throw new \LogicException(
                'DocStore::findBest() returned nothing immediately after DocStore::insert() for the same (pid, digest)'
            );
        }

        return $best;
    }

    /**
     * Runs {@see self::$capabilities} -- always all five, never a
     * caller-supplied subset (the capability-crash rule applies to a run of
     * ALL five, ARCHITECTURE.md §6.1).
     */
    private function extractAll(int $pid, string $correlationId, ?int $userId): ExtractionOutcome
    {
        $allFacts = [];
        $capabilityVersions = [];
        $excludedCounts = [];
        $failures = [];

        foreach ($this->capabilities as $capability) {
            $t0 = microtime(true);
            $label = $capability->capability()->value;

            try {
                $result = $capability->extract($pid);
                $capabilityVersions[$label] = $capability->capabilityVersion();
                $allFacts = [...$allFacts, ...$result->allFacts()];
                $excludedCounts["{$label}_excluded"] = count($result->exclusions);
                $excludedCounts["{$label}_unaccounted"] = $result->unaccountedCount();

                $unaccounted = $result->unaccountedCount();
                $this->recordSpan(
                    $correlationId,
                    'extract',
                    $t0,
                    $unaccounted > 0 ? 'error' : 'ok',
                    $pid,
                    $userId,
                    errorClass: $unaccounted > 0 ? 'UnaccountedRows' : null,
                    errorDetail: $unaccounted > 0
                        ? "{$label}: raw={$result->rawInputCount} accounted={$result->accountedCount} unaccounted={$unaccounted}"
                        : null,
                );
            } catch (\Throwable $e) {
                $failures[] = CapabilityExtractionFailure::fromThrowable($capability->capability(), $e);
                $this->recordSpan($correlationId, 'extract', $t0, 'error', $pid, $userId, errorClass: $e::class, errorDetail: $e->getMessage());
            }
        }

        if ($failures !== []) {
            return ExtractionOutcome::crashed($allFacts, $failures);
        }

        return ExtractionOutcome::success($allFacts, $capabilityVersions, $excludedCounts);
    }

    private function toServedResult(
        string $correlationId,
        int $pid,
        array $freshFacts,
        DocRow $docRow,
        bool $servedFromCache,
    ): SynthesisReadResult {
        $payload = SynthesisDocPayload::fromDocArray($docRow->doc);

        return SynthesisReadResult::served(
            $correlationId,
            $pid,
            $freshFacts,
            $docRow->factDigest,
            $docRow->verifyStatus,
            $docRow->regenReason,
            $payload->claims,
            $payload->degradedReason,
            $payload->degradedMessage,
            $payload->verdicts,
            $payload->attempts,
            $servedFromCache,
            $docRow->computedAt,
            $docRow->qaStatus,
            $docRow->qaScore,
            $docRow->id,
        );
    }

    /**
     * @param list<Claim>|null $claims
     * @return list<Claim>|null
     */
    private function rehydrateClaims(?array $claims, ?RedactionMap $map): ?array
    {
        if ($claims === null || $map === null) {
            return $claims;
        }

        return array_map(
            fn (Claim $claim): Claim => new Claim(
                $this->redactor->rehydrate($claim->text, $map),
                $claim->claimType,
                $claim->citationIds,
                $claim->numericValues,
                $claim->flags,
                $claim->order,
                $claim->emphasis !== null ? $this->redactor->rehydrate($claim->emphasis, $map) : null,
            ),
            $claims,
        );
    }

    private function recordSpan(
        string $correlationId,
        string $kind,
        float $t0,
        string $status,
        int $pid,
        ?int $userId,
        ?string $errorClass = null,
        ?string $errorDetail = null,
        ?string $model = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
    ): void {
        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        $startedAt = \DateTimeImmutable::createFromFormat('U.u', number_format($t0, 6, '.', ''));
        if ($startedAt === false) {
            $startedAt = new \DateTimeImmutable();
        }

        $this->tracer->record(new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            null,
            $kind,
            $startedAt,
            $durationMs,
            $status,
            $pid,
            $userId,
            $errorClass,
            $errorDetail,
            $model,
            $tokensIn,
            $tokensOut,
        ));
    }

    private static function mintCorrelationId(): string
    {
        return Uuid::uuid7()->toString();
    }

    /**
     * ARCHITECTURE.md "LLM platform": "Version strings are pinned and
     * folded into prompt_version (a digest input)."
     */
    private static function digestPromptVersion(): string
    {
        return self::PROMPT_VERSION . '+' . self::MODEL;
    }
}
