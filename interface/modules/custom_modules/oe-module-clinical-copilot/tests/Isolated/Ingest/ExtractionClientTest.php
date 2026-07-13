<?php

/**
 * The vision extractor: valid model output parses; bad output is rejected; no model degrades.
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
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SchemaValidationException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\StubLlmClient;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: (1) a hallucinated / malformed model response becoming
 * a persisted fact instead of being discarded; (2) the whole ingestion flow
 * dead-ending when no model is configured, rather than degrading to manual
 * entry. No live model calls, ever — the LlmClientInterface stub stands in.
 */
final class ExtractionClientTest extends TestCase
{
    public function testValidModelOutputBecomesTypedExtraction(): void
    {
        $json = json_encode(['fields' => [
            ['field_key' => 'Hemoglobin A1c', 'value' => '7.2', 'unit' => '%', 'reference_range' => '4.0-5.6', 'page' => 1, 'quote' => 'A1c 7.2 %'],
        ]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 1200, 40, 850)),
            'gemini-2.5-pro',
        );

        $outcome = $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'upload');

        self::assertSame('gemini-2.5-pro', $outcome->modelVersion);
        self::assertSame(1200, $outcome->tokensIn);
        self::assertCount(1, $outcome->extraction->fields);
        self::assertSame('7.2', $outcome->extraction->fields[0]->value);
    }

    public function testModelOutputThatFailsTheSchemaIsRejected(): void
    {
        // value present but no page/quote — fails the citation-required contract.
        $json = json_encode(['fields' => [['field_key' => 'A1c', 'value' => '7.2']]], JSON_THROW_ON_ERROR);

        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 10, 10, 10)),
            'gemini-2.5-pro',
        );

        $this->expectException(SchemaValidationException::class);
        $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'upload');
    }

    public function testNonJsonModelOutputIsRejected(): void
    {
        $client = new ExtractionClient(
            StubLlmClient::up(new LlmResponse('not json at all', 'gemini-2.5-pro', 10, 10, 10)),
            'gemini-2.5-pro',
        );

        $this->expectException(SchemaValidationException::class);
        $client->extract(DocType::LabPdf, 'PDFBYTES', 'application/pdf', 'upload');
    }

    public function testNoModelPropagatesUnavailableForTheManualFallback(): void
    {
        $client = new ExtractionClient(StubLlmClient::down(), 'gemini-2.5-pro');

        $this->expectException(LlmUnavailableException::class);
        $client->extract(DocType::IntakeForm, 'PDFBYTES', 'application/pdf', 'upload');
    }

    public function testTheDocumentIsSentAsAMultimodalPart(): void
    {
        $json = json_encode(['fields' => []], JSON_THROW_ON_ERROR);
        $stub = StubLlmClient::up(new LlmResponse($json, 'gemini-2.5-pro', 1, 1, 1));

        (new ExtractionClient($stub, 'gemini-2.5-pro'))
            ->extract(DocType::LabPdf, 'RAWPDFBYTES', 'application/pdf', 'upload');

        $request = $stub->calls()[0] ?? null;
        self::assertNotNull($request);
        self::assertCount(1, $request->parts, 'the document must ride as an inline part');
        self::assertSame('application/pdf', $request->parts[0]->mimeType);
        self::assertSame(base64_encode('RAWPDFBYTES'), $request->parts[0]->base64Data);
    }
}
