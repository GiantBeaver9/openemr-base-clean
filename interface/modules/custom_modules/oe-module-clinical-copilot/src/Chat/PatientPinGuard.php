<?php

/**
 * PatientPinGuard — the server-side assertion that every tool-produced fact belongs to the
 * pinned patient (I10, §1.2).
 *
 * Pure and standalone so the highest-stakes invariant in the module is directly unit-testable:
 * inject the session pid, run a capability, and assert every returned fact's pid equals it —
 * BEFORE any fact enters the session fact set. A mismatch is a hard error (the caller freezes
 * the session, SEV-1), not a filter: we never silently drop a foreign fact and continue, because
 * a foreign fact reaching this point means something is wrong upstream of the LLM.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

final class PatientPinGuard
{
    /**
     * Assert every fact is pinned to $sessionPid. Throws on the first foreign fact.
     *
     * @param list<Fact> $facts
     *
     * @throws PatientPinViolationException
     */
    public static function assertAllPinned(array $facts, int $sessionPid): void
    {
        foreach ($facts as $fact) {
            if ($fact->pid !== $sessionPid) {
                throw new PatientPinViolationException(
                    'Patient pin violation: fact ' . $fact->factId
                    . ' does not belong to the pinned session patient (SEV-1).',
                );
            }
        }
    }
}
