<?php

/**
 * NullAuditLogger — the no-op AuditLogger default.
 *
 * The read path always has an AuditLogger to call so it never has to null-check the collaborator;
 * when no host-backed logger is wired (e.g. an isolated test that is not asserting on audit), this
 * one absorbs the call. Runtime always injects EventAuditAdapter, so a real deployment always
 * audits — this default exists only to keep the read path unconditional.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

final class NullAuditLogger implements AuditLogger
{
    public function logView(int $pid, string $correlationId): void
    {
        // Intentionally does nothing.
    }
}
