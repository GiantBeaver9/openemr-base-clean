<?php

/**
 * EventAuditAdapter — the runtime AuditLogger backed by the host EventAuditLogger.
 *
 * Emits a 'patient-record' / 'view' audit entry whose description carries the correlation id, so
 * every doc view is auditable and joins to its trace (R2, §3.2). Session identity (authUser /
 * authProvider) is read once at construction from the injected session values, never from a
 * superglobal deep in the call stack. A logging failure is swallowed (logged to the system log)
 * rather than propagated — auditing must not cost the physician the page.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;

final class EventAuditAdapter implements AuditLogger
{
    public function __construct(
        private readonly string $authUser,
        private readonly string $authProvider,
        private readonly SystemLogger $logger = new SystemLogger(),
    ) {
    }

    public function logView(int $pid, string $correlationId): void
    {
        try {
            EventAuditLogger::getInstance()->newEvent(
                'patient-record',
                $this->authUser,
                $this->authProvider,
                1,
                'view: clinical co-pilot synthesis; correlation_id=' . $correlationId,
                $pid,
            );
        } catch (\Throwable $e) {
            // Never fail the page for an audit-write hiccup; record the failure to the system log.
            $this->logger->error('Clinical Co-Pilot doc-view audit failed', [
                'pid' => $pid,
                'correlation_id' => $correlationId,
                'exception' => $e,
            ]);
        }
    }
}
