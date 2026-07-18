<?php

/**
 * The schema-is-source-of-truth gate: valid payloads parse, invalid ones are rejected.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Ingest;

use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionSchema;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: raw VLM output bypassing the strict contract and
 * becoming a persisted, uncited, or out-of-vocabulary fact. Each assertion maps
 * to the `schema_valid` / `citation_present` eval rubric categories.
 */
final class ExtractionSchemaTest extends TestCase
{
    public function testValidIntakePayloadHasNoErrors(): void
    {
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada', 'page' => 1, 'quote' => 'Name: Ada', 'confidence' => 0.9, 'bbox' => [10, 20, 30, 40]],
            ['field_key' => 'chief_concern', 'value' => null, 'page' => 1, 'quote' => 'Reason for visit:'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));
    }

    public function testBlankFieldsWithoutACitationDoNotRejectTheExtraction(): void
    {
        // A comprehensive intake form has many blank fields; the model returns
        // them as value:null with an empty quote (nothing to cite). These must NOT
        // reject the whole extraction (which would blank the review form) — a
        // citation is only meaningful for a field that actually has a value.
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada', 'page' => 1, 'quote' => 'Name: Ada'],
            ['field_key' => 'middle_name', 'value' => null, 'page' => 0, 'quote' => ''],
            ['field_key' => 'ssn', 'value' => null, 'quote' => ''],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));
    }

    public function testIntakeDoesNotRequireCitationsSoAValuedFieldWithoutOneValidates(): void
    {
        // Intake prefills a form beside the PDF — it has no click-to-source
        // overlay — so citations are not required. A valued field with no
        // page/quote must NOT reject the extraction (that blanked the form).
        $payload = ['fields' => [
            ['field_key' => 'first_name', 'value' => 'Ada'],
            ['field_key' => 'last_name', 'value' => 'Lovelace'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::IntakeForm, $payload));
    }

    public function testNonIntegerPageOnAValuedFieldIsRejected(): void
    {
        // Failure mode guarded (eval case ref-07): the VLM cites page "one"
        // for a field that has a value. A page that is not a positive 1-based
        // integer — a string (even a numeric "3": JSON-decoded integers stay
        // int, so a quoted page is always a model error), a float like 2.0,
        // a bool, zero, or a negative — can never address a real page in the
        // source document, so the extraction must be refused rather than
        // persisting an uncheckable citation. Blank fields keep their page:0
        // tolerance (see testBlankFieldsWithoutACitationDoNotRejectTheExtraction).
        foreach (['one', '3', 2.0, true, 0, -1] as $badPage) {
            $payload = ['fields' => [
                ['field_key' => 'first_name', 'value' => 'Ada', 'page' => $badPage, 'quote' => 'q'],
            ]];
            $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

            self::assertNotSame([], $errors, 'page ' . var_export($badPage, true) . ' must reject the extraction');
            self::assertStringContainsString('page', implode(' ', $errors));
        }
    }

    public function testLabMissingPageIsRejectedBecauseCitationIsRequired(): void
    {
        // Labs drive the bbox overlay, so a valued result MUST cite its page.
        $payload = ['fields' => [['field_key' => 'A1c', 'value' => '7.2', 'quote' => 'A1c 7.2']]];
        $errors = ExtractionSchema::validate(DocType::LabPdf, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('page', implode(' ', $errors));
    }

    public function testLabMissingQuoteIsRejectedBecauseCitationIsRequired(): void
    {
        $payload = ['fields' => [['field_key' => 'A1c', 'value' => '7.2', 'page' => 1]]];
        $errors = ExtractionSchema::validate(DocType::LabPdf, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('quote', implode(' ', $errors));
    }

    public function testFieldKeyOutsideIntakeEnumIsRejected(): void
    {
        $payload = ['fields' => [['field_key' => 'social_security_number', 'value' => '1', 'page' => 1, 'quote' => 'x']]];
        $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('enum', implode(' ', $errors));
    }

    public function testValueMustBePresentEvenWhenNull(): void
    {
        // value key entirely absent (not merely null) is a contract violation.
        $payload = ['fields' => [['field_key' => 'first_name', 'page' => 1, 'quote' => 'x']]];
        $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('value', implode(' ', $errors));
    }

    public function testOutOfRangeConfidenceIsRejected(): void
    {
        $payload = ['fields' => [['field_key' => 'first_name', 'value' => 'Ada', 'page' => 1, 'quote' => 'x', 'confidence' => 1.7]]];
        $errors = ExtractionSchema::validate(DocType::IntakeForm, $payload);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('confidence', implode(' ', $errors));
    }

    public function testLabTestNamesAreOpenVocabulary(): void
    {
        $payload = ['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'reference_range' => '4.0-5.6', 'page' => 1, 'quote' => 'A1c 7.2 %'],
        ]];

        self::assertSame([], ExtractionSchema::validate(DocType::LabPdf, $payload));
    }

    public function testParseBuildsCitationsFromValidatedPayload(): void
    {
        $payload = ['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'page' => 1, 'quote' => 'A1c 7.2 %', 'bbox' => [10, 20, 30, 40]],
        ]];

        $parsed = ExtractionSchema::parse(DocType::LabPdf, $payload, 'extraction:7');
        self::assertCount(1, $parsed->fields);

        $field = $parsed->fields[0];
        self::assertSame('Hemoglobin A1c', $field->fieldKey);
        self::assertSame('7.2', $field->vlmValue);
        self::assertSame('7.2', $field->value, 'value starts equal to the model value (unedited)');
        self::assertNotNull($field->citation);
        self::assertSame(1, $field->citation->pageOrSection);
        self::assertSame('A1c 7.2 %', $field->citation->quoteOrValue);
        self::assertNotNull($field->citation->bbox);
    }

    public function testBlankIntakeExtractionSeedsEveryEnumKey(): void
    {
        $blank = ExtractionSchema::blankExtraction(DocType::IntakeForm);

        self::assertNotSame([], $blank->fields);
        foreach ($blank->fields as $field) {
            self::assertNull($field->vlmValue, 'blank fields never count toward accuracy');
            self::assertNull($field->value);
        }
        self::assertNull($blank->fieldAccuracy(), 'no model claims => no accuracy to measure');
    }

    public function testResponseSchemaLoadsForBothDocTypes(): void
    {
        self::assertArrayHasKey('properties', ExtractionSchema::responseSchema(DocType::IntakeForm));
        self::assertArrayHasKey('properties', ExtractionSchema::responseSchema(DocType::LabPdf));
    }
}
