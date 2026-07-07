<?php

/**
 * PendingOrder — an in-flight lab order: drawn/routed but without a final or corrected
 * result yet (the "absence" UC5 makes visible so it is not re-ordered).
 *
 * An unresulted order is invisible to the LabSlice by construction (INNER joins drop
 * resultless orders — that is PendingResults' whole reason to exist), so this carries the
 * order-side columns PendingResults needs directly: the ordered LOINC (from
 * `procedure_order_code` in production; supplied for isolated tests), the order status, the
 * best collection date (report/order `date_collected`, for the expected_result_date
 * derivation), and whether any final/corrected result exists (false ⇒ genuinely pending).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

final readonly class PendingOrder
{
    public function __construct(
        public int $procedureOrderId,
        public ?string $loinc,
        public string $orderStatus,
        public ?string $collectionDate,
        public bool $hasFinalOrCorrectedResult,
    ) {
    }
}
