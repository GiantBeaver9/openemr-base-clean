<?php

/**
 * StatusResolution — the outcome of interpreting a free-text `result_status` (C2).
 *
 * Either a presentable FactStatus (final/corrected/unstated/preliminary) OR an
 * exclusion carrying an ExclusionReason (unperformed/unrecognized). Also answers the
 * one downstream question OverdueTests needs: does this status reset the overdue clock?
 * (delegated to FactStatus::resetsOverdueClock() — an unperformed test can't prove the
 * test isn't overdue).
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

final readonly class StatusResolution
{
    public function __construct(
        public FactStatus $status,
        public ?ExclusionReason $exclusionReason,
    ) {
    }

    public function isExcluded(): bool
    {
        return $this->status === FactStatus::Excluded;
    }

    /**
     * Whether a result with this status resets the OverdueTests clock (C2).
     */
    public function resetsOverdueClock(): bool
    {
        return $this->status->resetsOverdueClock();
    }
}
