<?php

/**
 * PendingOrderSource — PendingResults' own order-side data access.
 *
 * The lab result slice (LabRowSource) INNER-joins order→report→result and therefore cannot
 * see an order that has no result rows — exactly the in-flight orders PendingResults exists
 * to surface. So this capability reads the order side itself: `procedure_order` (activity=1)
 * with status `pending`/`routed`, OR any order with no final/corrected result row. Behind
 * this small interface sit a Fixture impl (isolated tests) and a Db impl (QueryUtils), so
 * the pure PendingResults logic runs with no database.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

interface PendingOrderSource
{
    /**
     * All in-flight (pending/routed or result-absent) active orders for one patient.
     *
     * @return list<PendingOrder>
     */
    public function pendingOrders(int $pid): array;
}
