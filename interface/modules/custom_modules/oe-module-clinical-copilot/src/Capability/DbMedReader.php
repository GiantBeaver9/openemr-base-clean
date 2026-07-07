<?php

/**
 * DbMedReader — the production MedReader: the T4 medication union over the host tables.
 *
 * The host `OpenEMR\Services\PrescriptionService` is the reference for this union — its base
 * query already UNIONs `prescriptions` and `lists` (type=medication) — but its public
 * surface returns FHIR-shaped records keyed by UUID and does not expose the numeric row
 * primary keys the module's citation model needs (Citation.pk is an int). So this reader
 * issues the same two-table union directly through QueryUtils (parameterized, read-only), to
 * obtain each row's numeric id, drug, dosage, start date and active flag. Module is READ-ONLY
 * to both tables.
 *
 * Framework-coupled: needs the OpenEMR DB stack, so it is exercised in-stack, not by the
 * isolated runner; it is `php -l`-clean. FixtureMedReader is its isolated twin.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Common\Database\QueryUtils;

final class DbMedReader implements MedReader
{
    private const PRESCRIPTIONS_SQL = <<<'SQL'
        SELECT id, drug, dosage, start_date, active
        FROM prescriptions
        WHERE patient_id = ? AND active = 1
        SQL;

    private const LISTS_SQL = <<<'SQL'
        SELECT id, title, begdate, activity
        FROM lists
        WHERE pid = ? AND type = 'medication' AND activity = 1
        SQL;

    public function readMeds(int $pid): array
    {
        $meds = [];

        /** @var list<array<string, mixed>> $prescriptions */
        $prescriptions = QueryUtils::fetchRecords(self::PRESCRIPTIONS_SQL, [$pid]);
        foreach ($prescriptions as $row) {
            $meds[] = new MedRecord(
                'prescriptions',
                (int) ($row['id'] ?? 0),
                $this->str($row['drug'] ?? ''),
                $this->str($row['dosage'] ?? ''),
                $this->nullableStr($row['start_date'] ?? null),
                true,
            );
        }

        /** @var list<array<string, mixed>> $lists */
        $lists = QueryUtils::fetchRecords(self::LISTS_SQL, [$pid]);
        foreach ($lists as $row) {
            $meds[] = new MedRecord(
                'lists',
                (int) ($row['id'] ?? 0),
                $this->str($row['title'] ?? ''),
                '',
                $this->nullableStr($row['begdate'] ?? null),
                true,
            );
        }

        return $meds;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function nullableStr(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return $this->str($value);
    }
}
