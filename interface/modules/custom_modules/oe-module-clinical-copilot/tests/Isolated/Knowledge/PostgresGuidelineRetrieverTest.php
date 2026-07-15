<?php

/**
 * PostgresGuidelineRetriever — maps knowledge rows to cited evidence, PHI-safely.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryRunner;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeQueryScrubber;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\PostgresGuidelineRetriever;
use PHPUnit\Framework\TestCase;

/**
 * Bound to a fake {@see KnowledgeQueryRunner} so the whole retriever runs with no
 * database. Verifies: (1) it never queries when unavailable or when the query
 * scrubs empty, (2) rows become cited {@see \OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet}s
 * with a Guideline source type, and (3) the SQL it sends carries only the
 * scrubbed, non-PHI query text.
 */
final class PostgresGuidelineRetrieverTest extends TestCase
{
    public function testReturnsEmptyWhenStoreUnavailable(): void
    {
        $runner = new FakeKnowledgeRunner(available: false);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        self::assertSame([], $retriever->retrieve('a1c target', ['a1c']));
        self::assertNull($runner->lastSql, 'must not query an unavailable store');
    }

    public function testDoesNotQueryWhenNothingSafeToSearch(): void
    {
        // Query is pure PHI (name + number) and no tags => scrubs to empty.
        $runner = new FakeKnowledgeRunner(available: true, rows: []);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        self::assertSame([], $retriever->retrieve('John 55', []));
        self::assertNull($runner->lastSql);
    }

    public function testMapsRowsToCitedGuidelineEvidence(): void
    {
        $runner = new FakeKnowledgeRunner(available: true, rows: [
            [
                'id' => 'ada-a1c-target',
                'title' => 'A1c goal',
                'source' => 'ADA (summary)',
                'section' => 'Glycemic Targets',
                'body' => 'For most non-pregnant adults an A1c below 7% is reasonable.',
                'tags' => '{a1c,glycemic}',
                'url' => null,
                'score' => 0.87,
            ],
        ]);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        $snippets = $retriever->retrieve('a1c target', ['a1c']);

        self::assertCount(1, $snippets);
        $snippet = $snippets[0];
        self::assertSame('ada-a1c-target', $snippet->chunk->id);
        self::assertSame(['a1c', 'glycemic'], $snippet->chunk->tags);
        self::assertEqualsWithDelta(0.87, $snippet->score, 1e-9);
        self::assertSame(SourceType::Guideline, $snippet->citation->sourceType);
        self::assertSame('ADA (summary)', $snippet->citation->sourceId);
    }

    public function testSendsOnlyScrubbedNonPhiQueryText(): void
    {
        $runner = new FakeKnowledgeRunner(available: true, rows: []);
        $retriever = new PostgresGuidelineRetriever($runner, new KnowledgeQueryScrubber());

        $retriever->retrieve("why is Jane's A1c 9.4 cholesterol", ['a1c']);

        self::assertIsString($runner->lastSql);
        self::assertStringContainsString('websearch_to_tsquery', (string)$runner->lastSql);
        $q = $runner->lastParams['q'] ?? '';
        self::assertIsString($q);
        self::assertStringContainsString('cholesterol', $q);
        self::assertStringNotContainsString('Jane', $q);
        self::assertStringNotContainsString('9.4', $q);
    }

    public function testRejectsAnUnsafeTableName(): void
    {
        $this->expectException(\DomainException::class);
        new PostgresGuidelineRetriever(new FakeKnowledgeRunner(true), new KnowledgeQueryScrubber(), 'chunks; DROP TABLE x');
    }
}

/**
 * A canned {@see KnowledgeQueryRunner} that records the SQL/params it was asked
 * to run and returns preset rows — no PDO, no Postgres.
 */
final class FakeKnowledgeRunner implements KnowledgeQueryRunner
{
    public ?string $lastSql = null;

    /** @var array<string, scalar|null> */
    public array $lastParams = [];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly bool $available,
        private readonly array $rows = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function select(string $sql, array $params = []): array
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;

        return $this->rows;
    }
}
