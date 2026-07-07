<?php

/**
 * Lab contract C2: `result_status` semantics.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;

/**
 * Pure, DB-independent. `result_status` is free-text varchar, default ''.
 *
 * Ambiguity resolved here (documented per the U4 report): C2's table lists
 * "cannot be done, incomplete, error, pending, canceled" and "unrecognized"
 * in two separate rows, both "excluded (flagged)". They are given two
 * distinct {@see ExclusionReason} values -- UnresultedStatus for the five
 * named unperformed-test statuses, UnrecognizedStatus for anything the
 * contract has never seen -- so downstream consumers (and the physician
 * reading the exclusion note) can tell "the test wasn't actually done" from
 * "this is a status string we don't understand yet." Both behave
 * identically for supersession/clock purposes; only the flag differs.
 */
final class ResultStatusClassifier
{
    private const UNRESULTED_STATUSES = ['cannot be done', 'incomplete', 'error', 'pending', 'canceled'];

    private function __construct()
    {
        // static-only
    }

    public static function classify(string $resultStatus): StatusClassification
    {
        return match (true) {
            $resultStatus === 'final' => StatusClassification::presented(FactStatus::Final, true, false, 2),
            $resultStatus === 'corrected' => StatusClassification::presented(FactStatus::Corrected, true, false, 3),
            $resultStatus === '' => StatusClassification::presented(FactStatus::Unstated, true, false, 1),
            $resultStatus === 'preliminary' => StatusClassification::presented(FactStatus::Preliminary, false, true, 0),
            in_array($resultStatus, self::UNRESULTED_STATUSES, true) => StatusClassification::excluded(ExclusionReason::UnresultedStatus),
            default => StatusClassification::excluded(ExclusionReason::UnrecognizedStatus),
        };
    }
}
