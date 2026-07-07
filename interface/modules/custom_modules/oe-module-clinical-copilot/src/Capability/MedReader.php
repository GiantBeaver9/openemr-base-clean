<?php

/**
 * MedReader — MedResponse's data access: the T4 medication union.
 *
 * Medications live in two host tables (`prescriptions` for in-house scripts, `lists` with
 * type=medication for outside/reconciled meds); dropping either silently drops meds an
 * endocrinologist depends on (T4). Behind this interface sit a Fixture impl (reads both
 * JSON fixtures) and a Db impl (wraps the host `PrescriptionService`, whose own query already
 * unions the two), so the pure reconciliation/pairing logic is isolated-testable.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

interface MedReader
{
    /**
     * Active medications for one patient, unioned across `prescriptions` and `lists`.
     *
     * @return list<MedRecord>
     */
    public function readMeds(int $pid): array;
}
