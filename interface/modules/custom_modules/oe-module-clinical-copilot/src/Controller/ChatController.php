<?php

/**
 * ChatController — the POST chat.php turn handler (ARCHITECTURE.md §1.3, §4).
 *
 * The outermost request boundary: superglobal reads are confined here and parsed into typed
 * values immediately. In order it enforces CSRF, ACL (`patients`/`med`), session identity (the
 * session's user_id must equal authUserID), the one-active-turn 409 guard, and the HIPAA audit
 * trail (EventAuditLogger, event patient-record, carrying the correlation id) — then runs the
 * pinned agent turn and persists it append-only. The pin is STRUCTURAL: for an existing session
 * the patient comes from the session row, never the request; no tool ever takes a patient id.
 *
 * The session is contractually read-only (this endpoint never opts into $sessionAllowWrite=true),
 * so a long-held turn cannot serialize the physician's other tabs. Every turn is one synchronous
 * request; the verifier runs on the COMPLETE response before any prose token is emitted (§2).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Chat\AgentResult;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatAgent;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatPromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSession;
use OpenEMR\Modules\ClinicalCopilot\Chat\DbSessionGateway;
use OpenEMR\Modules\ClinicalCopilot\Chat\SeedBuilder;
use OpenEMR\Modules\ClinicalCopilot\Chat\SessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\StalenessChecker;
use OpenEMR\Modules\ClinicalCopilot\Chat\StalenessResult;
use OpenEMR\Modules\ClinicalCopilot\Chat\ToolExecutor;
use OpenEMR\Modules\ClinicalCopilot\Chat\ToolRegistry;
use OpenEMR\Modules\ClinicalCopilot\Chat\TurnRole;
use OpenEMR\Modules\ClinicalCopilot\Doc\CopilotDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\DbDocGateway;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\GlobalConfig;
use OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Observability\DbTraceWriter;
use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientContext;
use OpenEMR\Modules\ClinicalCopilot\Reduce\VertexClient;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

final class ChatController
{
    private const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private readonly SessionStore $store,
        private readonly SeedBuilder $seedBuilder,
        private readonly StalenessChecker $staleness,
        private readonly DocStore $docStore,
        private readonly CapabilityFactory $factory,
        private readonly ChatAgent $agent,
        private readonly CanonicalSerializer $serializer,
        private readonly SystemLogger $logger,
    ) {
    }

    /**
     * Wire the runtime (Db) implementations from the host globals bag.
     *
     * @param array<string, mixed> $globals
     */
    public static function fromGlobals(array $globals): self
    {
        $factory = CapabilityFactory::db();
        $traces = new DbTraceWriter();
        $registry = new ToolRegistry();
        $executor = new ToolExecutor($factory, $registry, $traces);
        $config = new GlobalConfig($globals);
        $client = new VertexClient($config);
        $store = new SessionStore(new DbSessionGateway());
        $agent = new ChatAgent(
            $client,
            $executor,
            $registry,
            new Verifier(),
            new EgressRedactor(),
            new ChatPromptAssembler(),
            $store,
            $traces,
            $config->modelPro(),
        );

        return new self(
            $store,
            new SeedBuilder(),
            new StalenessChecker(),
            new DocStore(new DbDocGateway()),
            $factory,
            $agent,
            new CanonicalSerializer(),
            new SystemLogger(),
        );
    }

    /**
     * Handle one POST turn end-to-end. Emits SSE when the client asked for it, else JSON.
     */
    public function handle(): void
    {
        $correlationId = CorrelationId::mint((int) round(microtime(true) * 1000));
        $authUserId = (int) ($_SESSION['authUserID'] ?? 0);

        // 1. CSRF — reject before anything else touches the chart.
        try {
            CsrfUtils::checkCsrfInput(INPUT_POST);
        } catch (\Throwable) {
            $this->fail(400, 'Invalid or missing request token.', $correlationId);
            return;
        }

        // 2. ACL — every copilot surface requires patients/med (§4).
        if (!AclMain::aclCheckCore('patients', 'med')) {
            $this->auditDenied($authUserId, $correlationId, 'acl-denied');
            $this->fail(403, 'You are not authorized to use the Clinical Co-Pilot.', $correlationId);
            return;
        }

        // 3. Parse untrusted input into typed values (malformed → clean refusal, never a stack trace).
        $message = trim((string) ($_POST['message'] ?? ''));
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $requestedPid = (int) ($_POST['pid'] ?? 0);
        if ($message === '') {
            $this->fail(400, 'Please enter a question.', $correlationId);
            return;
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $this->fail(400, 'That message is too long — please shorten it.', $correlationId);
            return;
        }

        // 4. Resolve the pinned session. Pin comes from the session row (server-side), never input.
        try {
            $session = $this->resolveSession($sessionId, $requestedPid, $authUserId);
        } catch (\Throwable $e) {
            $this->logger->error('Clinical Co-Pilot chat session resolution failed', [
                'correlation_id' => $correlationId,
                'exception' => $e,
            ]);
            $this->fail(500, 'The chat session could not be opened.', $correlationId);
            return;
        }
        if ($session === null) {
            $this->fail(400, 'A patient must be selected to start a conversation.', $correlationId);
            return;
        }
        if ($session->userId !== $authUserId) {
            $this->auditDenied($authUserId, $correlationId, 'session-identity-mismatch');
            $this->fail(403, 'This conversation belongs to another user.', $correlationId);
            return;
        }
        if (!$session->isActive()) {
            $this->fail(409, 'This conversation is locked and cannot continue. Start a fresh summary.', $correlationId);
            return;
        }
        $pid = $session->pid;

        // 5. Audit the chart access (§4) — chat inherits the EMR's HIPAA audit trail.
        $this->auditView($authUserId, $pid, $correlationId);

        // 6. One-active-turn slot + rate limits (§3.7). A concurrent second POST → 409.
        $slotHeld = $this->store->acquireTurnSlot($session->id);
        $decision = $this->store->rateLimit($session->id, $authUserId, $slotHeld);
        if (!$decision->allowed) {
            if ($slotHeld) {
                $this->store->releaseTurnSlot($session->id);
            }
            $this->fail($decision->httpStatus(), $decision->clientHint(), $correlationId);
            return;
        }

        try {
            // 7. Build the seed (fresh facts, I2 — never cached) + the narrative from the served doc.
            $doc = $this->docFor($pid, $session);
            $seed = $this->seedBuilder->build($this->factory, $pid, $doc);

            // 8. Prior conversation (before this message) for anaphora, then persist the user turn.
            $history = $this->store->turns($session->id);
            $this->store->appendTurn($session->id, TurnRole::User, $message, $correlationId);

            // 9. Mid-conversation drift check (T19) — answer regardless, disclose on drift.
            $stale = $this->staleness->check($this->factory, $pid, $session->factDigest);

            // 10. Run the pinned agent turn (verifier runs on the complete response first, §2).
            $context = $this->patientContext($pid);
            $result = $this->agent
                ->runTurn($session, $seed, $history, $message, $context, $correlationId, $authUserId)
                ->withChartChanged($stale->stale);

            // 11. Persist the assistant turn append-only (verdict, tokens; provenance ledger, T7).
            $this->store->appendTurn(
                $session->id,
                TurnRole::Assistant,
                $result->answerText,
                $correlationId,
                $result->toolCallsJson(),
                $result->verdict,
                $result->tokensIn,
                $result->tokensOut,
                null,
            );

            // 12. Render.
            $this->respond($session, $result, $stale, $correlationId);
        } catch (\Throwable $e) {
            $this->logger->error('Clinical Co-Pilot chat turn failed', [
                'correlation_id' => $correlationId,
                'pid' => $pid,
                'exception' => $e,
            ]);
            $this->fail(500, 'The chat turn could not be completed. The summary and facts remain available.', $correlationId);
        } finally {
            if ($slotHeld) {
                $this->store->releaseTurnSlot($session->id);
            }
        }
    }

    /**
     * Resolve or lazily create the pinned session. An existing session id pins server-side; a bare
     * pid (opening the copilot page) creates one after seeding.
     */
    private function resolveSession(int $sessionId, int $requestedPid, int $authUserId): ?ChatSession
    {
        if ($sessionId > 0) {
            return $this->store->load($sessionId);
        }
        if ($requestedPid <= 0) {
            return null;
        }
        $doc = $this->latestDoc($requestedPid);
        $seed = $this->seedBuilder->build($this->factory, $requestedPid, $doc);
        return $this->store->open($requestedPid, $authUserId, $doc?->id, $seed->factDigest);
    }

    private function docFor(int $pid, ChatSession $session): ?CopilotDoc
    {
        try {
            $byDigest = $this->docStore->findByPidAndDigest($pid, $session->factDigest);
            if ($byDigest !== null) {
                return $byDigest;
            }
            return $this->latestDoc($pid);
        } catch (\Throwable $e) {
            $this->logger->error('Clinical Co-Pilot doc lookup failed', ['pid' => $pid, 'exception' => $e]);
            return null;
        }
    }

    private function latestDoc(int $pid): ?CopilotDoc
    {
        $history = $this->docStore->history($pid);
        return $history === [] ? null : $history[count($history) - 1];
    }

    /**
     * Best-effort direct identifiers for egress redaction (§4). Read-only SELECT on patient_data;
     * any failure yields a pid-only context (redaction is minimization, not a hard dependency).
     */
    private function patientContext(int $pid): PatientContext
    {
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT fname, lname, pubpid, DOB, street FROM patient_data WHERE pid = ? LIMIT 1',
                [$pid],
            );
            $row = $rows[0] ?? null;
            if (!is_array($row)) {
                return new PatientContext($pid);
            }
            $name = trim(((string) ($row['fname'] ?? '')) . ' ' . ((string) ($row['lname'] ?? '')));
            return new PatientContext(
                $pid,
                $name === '' ? null : $name,
                ($row['pubpid'] ?? '') === '' ? null : (string) $row['pubpid'],
                ($row['DOB'] ?? '') === '' ? null : (string) $row['DOB'],
                ($row['street'] ?? '') === '' ? null : (string) $row['street'],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Clinical Co-Pilot patient context read failed', ['pid' => $pid, 'exception' => $e]);
            return new PatientContext($pid);
        }
    }

    private function auditView(int $userId, int $pid, string $correlationId): void
    {
        try {
            EventAuditLogger::getInstance()->newEvent(
                'patient-record',
                (string) $userId,
                '',
                1,
                'Clinical Co-Pilot chat turn (action=view); correlation_id=' . $correlationId,
                $pid,
                'open-emr',
                'view',
            );
        } catch (\Throwable $e) {
            // Auditing must not take the turn down; record and continue.
            $this->logger->error('Clinical Co-Pilot chat audit failed', [
                'correlation_id' => $correlationId,
                'exception' => $e,
            ]);
        }
    }

    private function auditDenied(int $userId, string $correlationId, string $reason): void
    {
        try {
            EventAuditLogger::getInstance()->newEvent(
                'patient-record',
                (string) $userId,
                '',
                0,
                'Clinical Co-Pilot chat denied (' . $reason . '); correlation_id=' . $correlationId,
                null,
                'open-emr',
                'view',
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Render the finished turn. SSE when requested (staged status then the verified answer); the
     * verifier already ran on the complete response, so no unverified prose is ever streamed (§2).
     */
    private function respond(ChatSession $session, AgentResult $result, StalenessResult $stale, string $correlationId): void
    {
        $payload = $this->payload($session, $result, $stale, $correlationId);

        if ($this->wantsSse()) {
            $this->emitSse($payload);
            return;
        }

        header('Content-Type: application/json');
        echo (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ChatSession $session, AgentResult $result, StalenessResult $stale, string $correlationId): array
    {
        $citations = [];
        foreach ($result->claims as $claim) {
            $citations[] = [
                'text' => $claim->text,
                'claim_type' => $claim->claimType->value,
                'citation_ids' => $claim->citationIds,
                'flags' => $claim->flags,
            ];
        }

        return [
            'correlation_id' => $correlationId,
            'session_id' => $session->id,
            'outcome' => $result->outcome->value,
            'answer' => $result->answerText,
            'claims' => $citations,
            'notes' => $result->notes,
            'chart_changed' => $result->chartChanged,
            'staleness_banner' => $result->chartChanged ? StalenessResult::BANNER : null,
            'frozen' => $result->isFrozen(),
            'verdict' => $result->verdict?->toArray(),
            'facts' => $this->serializer->canonicalize($result->facts->facts),
            'tokens_in' => $result->tokensIn,
            'tokens_out' => $result->tokensOut,
        ];
    }

    private function wantsSse(): bool
    {
        if ((string) ($_POST['transport'] ?? '') === 'sse') {
            return true;
        }
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emitSse(array $payload): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        // Staged status frames (the turn already completed server-side; the verifier ran first).
        $this->sseFrame('status', ['stage' => 'retrieving', 'message' => 'retrieving labs…']);
        $this->sseFrame('status', ['stage' => 'verifying', 'message' => 'verifying…']);
        $this->sseFrame('answer', $payload);
        $this->sseFrame('done', ['correlation_id' => $payload['correlation_id'] ?? null]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sseFrame(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    private function fail(int $status, string $clientMessage, string $correlationId): void
    {
        http_response_code($status);
        if ($this->wantsSse()) {
            $this->sseFrame('error', ['message' => $clientMessage, 'correlation_id' => $correlationId]);
            return;
        }
        header('Content-Type: application/json');
        echo (string) json_encode([
            'error' => $clientMessage,
            'correlation_id' => $correlationId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
