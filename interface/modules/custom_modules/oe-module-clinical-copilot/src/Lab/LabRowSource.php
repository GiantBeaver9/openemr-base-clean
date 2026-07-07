<?php

/**
 * LabRowSource — the small interface behind which the 3-table lab join lives, so the
 * pure lab-slice logic (LabSlice) is isolated-testable with no database (build-protocol
 * "put DB access behind a small interface with a Fixture impl and a Db impl").
 *
 * Implementations MUST perform the join `procedure_order (patient_id, activity=1)` →
 * `procedure_report (procedure_order_id)` → `procedure_result (procedure_report_id)`
 * and return one LabRow per procedure_result row that has a matching order+report.
 * Orders with no result rows are NOT returned here (those are PendingResults' concern).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

interface LabRowSource
{
    /**
     * All joined lab rows for one patient, `activity = 1` only.
     *
     * @return list<LabRow>
     */
    public function fetchForPatient(int $pid): array;
}
