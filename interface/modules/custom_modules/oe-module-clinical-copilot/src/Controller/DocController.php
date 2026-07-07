<?php

/**
 * Orchestrates one doc-page request: read path + history + audit logging.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Doc\DocRow;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\DocHistoryReader;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\PatientIdentifierLookup;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadResult;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PatientIdentifiers;

/**
 * `public/doc.php` is deliberately thin (bootstrap -> CSRF -> ACL -> session
 * identity, per build-notes.md's page-bootstrap contract) and delegates
 * everything else here. This class knows nothing about HTTP superglobals or
 * Twig -- it takes a validated `pid`/`userId` and hands back plain data for
 * the caller to render, so it stays unit-testable independent of the web
 * layer.
 *
 * Every call here that reaches {@see SynthesisReadPath} is a chart-data
 * view and MUST be audit-logged (ARCHITECTURE.md §4/§1.3): both
 * {@see self::view()} and {@see self::regenerate()} call
 * {@see self::auditView()} unconditionally, including on a cache hit --
 * "cache hits ... included" is explicit in I12, and a chart-data VIEW audit
 * entry is owed regardless of whether the LLM was ever called.
 */
final class DocController
{
    public function __construct(
        private readonly SynthesisReadPath $readPath,
        private readonly PatientIdentifierLookup $identifierLookup,
        private readonly DocHistoryReader $historyReader,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(SynthesisReadPath::createDefault(), new PatientIdentifierLookup(), new DocHistoryReader());
    }

    /**
     * @return array{found: bool, result: ?SynthesisReadResult, history: list<DocRow>, patient: ?PatientIdentifiers}
     */
    public function view(int $pid, int $userId): array
    {
        return $this->handle($pid, $userId, regenerate: false);
    }

    /**
     * T22 manual Regenerate (POST, CSRF-checked by the caller BEFORE this is
     * invoked -- see `public/doc.php`).
     *
     * @return array{found: bool, result: ?SynthesisReadResult, history: list<DocRow>, patient: ?PatientIdentifiers}
     */
    public function regenerate(int $pid, int $userId): array
    {
        return $this->handle($pid, $userId, regenerate: true);
    }

    /**
     * @return array{found: bool, result: ?SynthesisReadResult, history: list<DocRow>, patient: ?PatientIdentifiers}
     */
    private function handle(int $pid, int $userId, bool $regenerate): array
    {
        if (!$this->identifierLookup->exists($pid)) {
            return ['found' => false, 'result' => null, 'history' => [], 'patient' => null];
        }

        $result = $regenerate ? $this->readPath->regenerate($pid, $userId) : $this->readPath->read($pid, $userId);

        $this->auditView($pid, $result->correlationId, $regenerate);

        return [
            'found' => true,
            'result' => $result,
            'history' => $this->historyReader->forPid($pid),
            'patient' => $this->identifierLookup->forPid($pid),
        ];
    }

    /**
     * ARCHITECTURE.md §4: "every read [is] audit-logged via EventAuditLogger."
     * PHI stays out of the comment string -- only the correlation id and a
     * fixed action label, never a name/MRN/value (§4: "never in log lines").
     */
    private function auditView(int $pid, string $correlationId, bool $regenerate): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $authUser = (string)($session->get('authUser') ?? '');
        $authProvider = (string)($session->get('authProvider') ?? '');
        $action = $regenerate ? 'regenerate' : 'view';

        EventAuditLogger::getInstance()->newEvent(
            'patient-record',
            $authUser,
            $authProvider,
            1,
            "Clinical Co-Pilot synthesis {$action}, correlation_id={$correlationId}",
            $pid,
        );
    }
}
