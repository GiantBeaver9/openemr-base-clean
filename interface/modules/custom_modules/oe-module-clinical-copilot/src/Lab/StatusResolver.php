<?php

/**
 * StatusResolver — the C2 status vocabulary (ARCHITECTURE_COMPLETE.md "Status semantics").
 *
 * `result_status` is a free-text varchar default ''. This maps it, case-insensitively,
 * onto the module's closed interpretation:
 *   - final / corrected / '' (unstated) / preliminary → presentable FactStatus
 *   - cannot be done / incomplete / error / pending / canceled → excluded (unperformed)
 *   - anything else (e.g. 'transcribed') → excluded (unrecognized) — never guessed
 *
 * "Unknown → exclude-and-flag, never guess" is the whole point (I5): an unrecognized
 * status becomes a VISIBLE exclusion, it does not silently vanish and does not reset the
 * overdue clock.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;

final class StatusResolver
{
    /**
     * Statuses that mean the test was not performed / not reportable (C2). An
     * unperformed test can't prove the test isn't overdue, so it is excluded and never
     * resets the clock.
     *
     * @var list<string>
     */
    private const UNPERFORMED = [
        'cannot be done',
        'cannot_be_done',
        'incomplete',
        'error',
        'pending',
        'canceled',
        'cancelled',
    ];

    public function resolve(string $rawStatus): StatusResolution
    {
        $status = strtolower(trim($rawStatus));

        return match ($status) {
            '' => new StatusResolution(FactStatus::Unstated, null),
            'final' => new StatusResolution(FactStatus::Final, null),
            'corrected' => new StatusResolution(FactStatus::Corrected, null),
            'preliminary' => new StatusResolution(FactStatus::Preliminary, null),
            default => $this->resolveNonCanonical($status),
        };
    }

    private function resolveNonCanonical(string $status): StatusResolution
    {
        if (in_array($status, self::UNPERFORMED, true)) {
            return new StatusResolution(FactStatus::Excluded, ExclusionReason::UnperformedStatus);
        }

        // Not in the known vocabulary — exclude and flag, never guess.
        return new StatusResolution(FactStatus::Excluded, ExclusionReason::UnrecognizedStatus);
    }
}
