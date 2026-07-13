<?php

/**
 * The single sanctioned seam that writes derived facts into the core chart.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\PatientService;

/**
 * Week 2 deliberately relaxes the Week 1 read-only invariant in EXACTLY this
 * class — and the module-scoped PHPStan gate proves it: ChartWriter is the one
 * `SANCTIONED_CORE_WRITERS` entry, so a raw core write (`sqlInsert`) or a host
 * service write (`PatientService::insert`) is a rule violation ANYWHERE else in
 * `src/`. A reviewer auditing "what can this module put into a real chart" reads
 * this file and only this file.
 *
 * Everything here is idempotent and lineage-tracked: intake demographics commit
 * to `patient_data`; lab results commit down the
 * `procedure_order -> procedure_order_code -> procedure_report -> procedure_result`
 * chain (mirroring the core HL7 inbound writer in
 * interface/orders/receive_hl7_results.inc.php), with `procedure_result.document_id`
 * binding each value back to the stored source PDF. The caller records the
 * returned core-row ids onto the staging facts ({@see ExtractionStore::setFieldLineage()}),
 * and re-committing an already-committed field is a no-op — no duplicate,
 * untraceable records.
 *
 * The richer intake facts (chief concern, medications, allergies, family
 * history) are intentionally NOT auto-written to core `lists`/`history_data`
 * yet — they stay in the verified staging record with a documented Phase-B path.
 * Committing structured problem/allergy/medication lists is its own contract.
 */
final class ChartWriter
{
    /** intake field_key => patient_data column. */
    private const DEMOGRAPHIC_COLUMNS = [
        'first_name' => 'fname',
        'last_name' => 'lname',
        'date_of_birth' => 'DOB',
        'sex' => 'sex',
        'phone' => 'phone_home',
        'email' => 'email',
        'address_street' => 'street',
        'address_city' => 'city',
        'address_state' => 'state',
        'address_postal' => 'postal_code',
    ];

    public function __construct(private readonly PatientService $patientService)
    {
    }

    /**
     * Creates the patient from verified intake demographics and returns the new
     * pid. Non-demographic intake facts are ignored here (they live in staging).
     *
     * @param array<string, string|null> $demographics field_key => value
     *
     * @throws \RuntimeException when core validation rejects the insert
     */
    public function createPatientFromIntake(array $demographics): int
    {
        $data = [];
        foreach (self::DEMOGRAPHIC_COLUMNS as $fieldKey => $column) {
            if (array_key_exists($fieldKey, $demographics) && $demographics[$fieldKey] !== null && $demographics[$fieldKey] !== '') {
                $data[$column] = $demographics[$fieldKey];
            }
        }

        $result = $this->patientService->insert($data);
        if (!$result->isValid()) {
            throw new \RuntimeException('Core rejected the intake patient insert (validation)');
        }

        $payload = $result->getData();
        $first = is_array($payload) ? ($payload[0] ?? null) : null;
        $pid = is_array($first) ? ($first['pid'] ?? null) : null;
        if (!is_int($pid) && !(is_string($pid) && ctype_digit($pid))) {
            throw new \RuntimeException('Core patient insert returned no pid');
        }

        return (int)$pid;
    }

    /**
     * Applies verified demographic corrections to an existing patient. Only the
     * mapped demographic columns are touched; unknown keys are ignored.
     *
     * @param array<string, string|null> $demographics field_key => value
     */
    public function updatePatientDemographics(int $pid, array $demographics): void
    {
        $sets = [];
        $binds = [];
        foreach (self::DEMOGRAPHIC_COLUMNS as $fieldKey => $column) {
            if (array_key_exists($fieldKey, $demographics)) {
                $sets[] = "`{$column}` = ?";
                $binds[] = $demographics[$fieldKey];
            }
        }

        if ($sets === []) {
            return;
        }

        $binds[] = $pid;
        QueryUtils::sqlStatementThrowException(
            'UPDATE `patient_data` SET ' . implode(', ', $sets) . ' WHERE `pid` = ?',
            $binds,
        );
    }

    /**
     * Stores the uploaded source file in OpenEMR (real `documents` row) linked
     * back to the extraction for provenance, and returns the new document id.
     *
     * @return int|null the documents.id, or null if the core store failed
     */
    public function storeSourceDocument(
        int $pid,
        int $categoryId,
        string $filename,
        string $mimeType,
        string $bytes,
        int $extractionId,
    ): ?int {
        $doc = new \Document();
        $error = $doc->createDocument(
            $pid,
            $categoryId,
            $filename,
            $mimeType,
            $bytes,
            '',
            1,
            0,
            null,
            null,
            $extractionId,
            'mod_copilot_extraction',
        );

        if (is_string($error) && $error !== '') {
            return null;
        }

        $id = $doc->get_id();

        return is_numeric($id) ? (int)$id : null;
    }

    /**
     * Commits verified lab results down the procedure chain. Only fields with a
     * value that are NOT already committed are written; if none qualify this is
     * a no-op (idempotent re-lock). One order + report is created per commit
     * batch, `document_id` binds each result to the source PDF.
     *
     * @param list<ExtractedFieldRow> $labFields
     *
     * @return array<int, int> map of extracted_fact.id => procedure_result_id
     */
    public function commitLabResults(int $pid, ?int $documentId, int $providerId, array $labFields, ?string $collectionDate): array
    {
        $pending = array_values(array_filter(
            $labFields,
            static fn (ExtractedFieldRow $r): bool => !$r->field->isCommitted() && $r->field->value !== null && $r->field->value !== '',
        ));
        if ($pending === []) {
            return [];
        }

        $orderedDate = $collectionDate ?? date('Y-m-d');

        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order`
                (`uuid`, `patient_id`, `provider_id`, `lab_id`, `date_ordered`, `date_collected`, `order_status`, `activity`)
             VALUES (?, ?, ?, 0, ?, ?, ?, 1)',
            [
                (new UuidRegistry(['table_name' => 'procedure_order']))->createUuid(),
                $pid,
                $providerId,
                $orderedDate,
                $orderedDate,
                'completed',
            ],
        );

        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order_code`
                (`procedure_order_id`, `procedure_order_seq`, `procedure_code`, `procedure_name`, `procedure_type`)
             VALUES (?, 1, ?, ?, ?)',
            [$orderId, 'PANEL', 'Clinical Co-Pilot ingested results', 'laboratory'],
        );

        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_report`
                (`uuid`, `procedure_order_id`, `procedure_order_seq`, `date_report`, `report_status`)
             VALUES (?, ?, 1, ?, ?)',
            [
                (new UuidRegistry(['table_name' => 'procedure_report']))->createUuid(),
                $orderId,
                $orderedDate,
                'final',
            ],
        );

        $committed = [];
        foreach ($pending as $row) {
            $field = $row->field;
            $resultId = QueryUtils::sqlInsert(
                'INSERT INTO `procedure_result`
                    (`uuid`, `procedure_report_id`, `result_code`, `result_text`, `result`, `units`,
                     `range`, `abnormal`, `result_status`, `date`, `document_id`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (new UuidRegistry(['table_name' => 'procedure_result']))->createUuid(),
                    $reportId,
                    $field->fieldKey,
                    $field->fieldKey,
                    $field->value,
                    $field->unit,
                    $field->refRange,
                    $field->abnormalFlag !== null ? strtolower($field->abnormalFlag) : '',
                    'final',
                    $orderedDate,
                    $documentId,
                ],
            );
            $committed[$row->id] = $resultId;
        }

        return $committed;
    }
}
