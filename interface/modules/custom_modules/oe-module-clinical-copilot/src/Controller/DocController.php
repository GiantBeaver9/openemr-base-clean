<?php

/**
 * DocController — the auth/PHI boundary and renderer for the synthesis doc page (U8).
 *
 * This is where "who is asking, and may they?" is answered before any patient data is touched
 * (ARCHITECTURE.md §4). In order: CSRF on any state-changing POST (a Regenerate request), the
 * feature ACL (patients/med), and a proven session identity (authUserID present). Only then does
 * it run the read path, audit the view (§3.2 — every doc view is logged, with the correlation id
 * in the description), and render the facts-first Twig page. The controller holds NO synthesis
 * logic: it delegates to ReadPath and renders whatever SynthesisResult comes back, degraded or not.
 *
 * All model/narrative text is escaped at the Twig sink (autoescape is OFF globally); the narrative
 * is never rendered raw.
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
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Read\ReadPath;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientContext;
use Twig\Environment;

final class DocController
{
    private const ACL_SECTION = 'patients';
    private const ACL_SUBSECTION = 'med';

    public function __construct(
        private readonly ReadPath $readPath,
        private readonly Environment $twig,
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    /**
     * Handle a request end-to-end: authorize, then render either the synthesis page or the history
     * view. Emits its own HTTP status + body; never throws to the entry script.
     */
    public function handle(): void
    {
        // 1. CSRF — only a state-changing POST (Regenerate) needs a token.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                CsrfUtils::checkCsrfInput(INPUT_POST);
            } catch (\Throwable $e) {
                $this->logger->error('Clinical Co-Pilot doc CSRF check failed', ['exception' => $e]);
                $this->deny(400, 'Authentication error');
                return;
            }
        }

        // 2. Feature ACL.
        if (!AclMain::aclCheckCore(self::ACL_SECTION, self::ACL_SUBSECTION)) {
            $this->deny(403, 'Access denied');
            return;
        }

        // 3. Proven session identity — no anonymous access to PHI.
        $authUserId = $this->sessionUserId();
        if ($authUserId === null) {
            $this->deny(403, 'Access denied');
            return;
        }

        $pid = $this->requestedPid();
        if ($pid <= 0) {
            $this->deny(400, 'A valid patient is required');
            return;
        }

        $view = is_string($_GET['view'] ?? null) ? (string) $_GET['view'] : 'synthesis';

        try {
            if ($view === 'history') {
                echo $this->renderHistory($pid);
                return;
            }
            echo $this->renderSynthesis($pid, $authUserId);
        } catch (\Throwable $e) {
            $this->logger->error('Clinical Co-Pilot doc render failed', [
                'pid' => $pid,
                'view' => $view,
                'exception' => $e,
            ]);
            $this->deny(500, 'The summary could not be rendered');
        }
    }

    private function renderSynthesis(int $pid, int $authUserId): string
    {
        // The read path audits the view (§3.2) via the AuditLogger it was wired with, carrying the
        // correlation id (R2) — a single choke point at the actual PHI access.
        $result = $this->readPath->synthesisFor($pid, $this->loadContext($pid), $authUserId);

        return $this->twig->render('doc.html.twig', [
            'pid' => $pid,
            'outcome' => $result->outcome->value,
            'correlation_id' => $result->correlationId,
            'has_narrative' => $result->hasNarrative(),
            'narrative' => $result->narrative,
            'narrative_unavailable_reason' => $result->narrativeUnavailableReason,
            'banner' => $result->banner,
            'is_paused' => $result->outcome === \OpenEMR\Modules\ClinicalCopilot\Read\ReadOutcome::Paused,
            'is_frozen' => $result->outcome === \OpenEMR\Modules\ClinicalCopilot\Read\ReadOutcome::Frozen,
            'retried' => $result->retried,
            'degraded' => $result->degraded,
            'sev1' => $result->sev1Signal,
            'computed_at' => $result->computedAt,
            'checks_run' => $result->checksRun,
            'facts' => $result->factRows(),
            'in_flight' => $result->inFlightRows(),
            'exclusions' => $result->exclusionNotes(),
        ]);
    }

    private function renderHistory(int $pid): string
    {
        $docs = [];
        foreach ($this->readPath->history($pid) as $doc) {
            $content = \OpenEMR\Modules\ClinicalCopilot\Read\DocContent::fromJson($doc->doc);
            $docs[] = [
                'computed_at' => $doc->computedAt,
                'correlation_id' => $doc->correlationId,
                'prompt_version' => $doc->promptVersion,
                'narrative' => $content->narrative,
                'facts' => $content->facts,
                'checks_run' => \OpenEMR\Modules\ClinicalCopilot\Read\CheckSummary::listFromArray($content->verdict),
            ];
        }

        return $this->twig->render('doc_history.html.twig', [
            'pid' => $pid,
            'docs' => $docs,
        ]);
    }

    /**
     * The four direct identifiers the reduce prompt redacts at egress (§4). Read-only over the core
     * patient_data table (I10/T6 — the module never writes core tables). A lookup miss yields a
     * bare context (pid only); redaction then has nothing to do, which is correct.
     */
    private function loadContext(int $pid): PatientContext
    {
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT fname, mname, lname, pubpid, DOB, street, city, state, postal_code '
                . 'FROM patient_data WHERE pid = ? LIMIT 1',
                [$pid],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Clinical Co-Pilot patient context load failed', [
                'pid' => $pid,
                'exception' => $e,
            ]);
            return new PatientContext($pid);
        }

        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return new PatientContext($pid);
        }

        $name = trim(implode(' ', array_filter([
            (string) ($row['fname'] ?? ''),
            (string) ($row['mname'] ?? ''),
            (string) ($row['lname'] ?? ''),
        ], static fn(string $part): bool => $part !== '')));
        $address = trim(implode(', ', array_filter([
            (string) ($row['street'] ?? ''),
            (string) ($row['city'] ?? ''),
            (string) ($row['state'] ?? ''),
            (string) ($row['postal_code'] ?? ''),
        ], static fn(string $part): bool => $part !== '')));

        return new PatientContext(
            $pid,
            $name !== '' ? $name : null,
            ($row['pubpid'] ?? '') !== '' ? (string) $row['pubpid'] : null,
            ($row['DOB'] ?? '') !== '' ? (string) $row['DOB'] : null,
            $address !== '' ? $address : null,
        );
    }

    private function requestedPid(): int
    {
        $raw = $_GET['pid'] ?? $_POST['pid'] ?? 0;
        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function sessionUserId(): ?int
    {
        $raw = $_SESSION['authUserID'] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function deny(int $status, string $message): void
    {
        http_response_code($status);
        echo xlt($message);
    }
}
