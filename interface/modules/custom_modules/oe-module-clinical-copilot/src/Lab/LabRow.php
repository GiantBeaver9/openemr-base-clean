<?php

/**
 * LabRow — one row of the 3-table lab join (procedure_order → procedure_report →
 * procedure_result), in the exact column shape the host tables return.
 *
 * This is the raw input to the lab-slice contract (ARCHITECTURE_COMPLETE.md C1–C4).
 * `procedure_result` carries no pid — patient scope is reachable only through the
 * join, so every LabRow already knows its owning patient (`patientId`) via the order.
 * It carries the four dates the C1 precedence walks (report/order date_collected,
 * result date, report date_report) plus the value/unit/status/flag columns C2–C4 read.
 *
 * A plain, immutable carrier: no logic lives here — DateResolver, StatusResolver,
 * ValueParser and UnitConverter interpret it.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final readonly class LabRow
{
    public function __construct(
        public int $procedureOrderId,
        public int $procedureReportId,
        public int $procedureResultId,
        public int $patientId,
        public int $activity,
        public string $resultCode,        // LOINC
        public string $resultText,        // human label, e.g. "Hemoglobin A1c"
        public string $result,            // raw value text, varchar(255)
        public string $units,             // '' allowed → "no unit, no math" (C4)
        public string $resultDataType,    // N, S, F, E, L (C3)
        public string $resultStatus,      // free-text, default '' (C2)
        public string $abnormal,          // lab flag: yes/high/low/no/'' (C3 proof b)
        public string $range,             // reported reference range (C3 proof b)
        public ?string $reportDateCollected, // C1 precedence #1 (authoritative)
        public ?string $orderDateCollected,  // C1 precedence #2 (authoritative)
        public ?string $resultDate,          // C1 precedence #3 (fallback)
        public ?string $reportDateReport,    // C1 precedence #4 (fallback)
    ) {
    }
}
