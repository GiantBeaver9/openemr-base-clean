<?php

/**
 * AuditLogger — the tiny seam over the host EventAuditLogger for the doc page (§3.2, §4).
 *
 * Viewing a synthesis doc is PHI access and MUST be audited. The controller depends on this
 * interface, not on the host singleton directly, so the audit call is a spyable collaborator in
 * tests (assert "a view writes exactly one audit entry carrying the correlation id") without the
 * OpenEMR framework. Runtime uses EventAuditAdapter; tests use an in-memory spy.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

interface AuditLogger
{
    /**
     * Record a synthesis-doc view against a patient. The correlation id threads the audit entry
     * back to the read path's trace (R2). Implementations must not throw for a logging failure to
     * cost the physician the page — log-and-continue is acceptable HERE, and only here.
     */
    public function logView(int $pid, string $correlationId): void;
}
