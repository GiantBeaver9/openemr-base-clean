<?php

/**
 * KnowledgeChunkWriter — transactional, replace-by-source upsert of chunks.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeChunkWriter;
use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeWriteRunner;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;
use PHPUnit\Framework\TestCase;

final class KnowledgeChunkWriterTest extends TestCase
{
    /**
     * @return list<GuidelineChunk>
     */
    private function chunks(): array
    {
        return [
            new GuidelineChunk('ada-000', 'A1c', 'ADA 2026', 'Targets', 'A1c below 7%.', ['a1c']),
            new GuidelineChunk('ada-001', 'A1c', 'ADA 2026', 'Targets', 'Reassess if above target.', ['a1c']),
        ];
    }

    public function testEmptyChunksWritesNothing(): void
    {
        $runner = new FakeWriteRunner(available: true);
        self::assertSame(0, (new KnowledgeChunkWriter($runner))->write([]));
        self::assertSame([], $runner->log);
    }

    public function testUnavailableStoreThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new KnowledgeChunkWriter(new FakeWriteRunner(available: false)))->write($this->chunks());
    }

    public function testReplaceThenUpsertInsideOneTransaction(): void
    {
        $runner = new FakeWriteRunner(available: true);
        $written = (new KnowledgeChunkWriter($runner))->write($this->chunks(), replaceExisting: true);

        self::assertSame(2, $written);
        self::assertSame('BEGIN', $runner->log[0]);
        self::assertSame('DELETE:ADA 2026', $runner->log[1]);
        self::assertSame('UPSERT:ada-000', $runner->log[2]);
        self::assertSame('UPSERT:ada-001', $runner->log[3]);
        self::assertSame('COMMIT', $runner->log[4]);
    }

    public function testWithoutReplaceThereIsNoDelete(): void
    {
        $runner = new FakeWriteRunner(available: true);
        (new KnowledgeChunkWriter($runner))->write($this->chunks(), replaceExisting: false);

        self::assertNotContains('DELETE:ADA 2026', $runner->log);
        self::assertContains('UPSERT:ada-000', $runner->log);
    }

    public function testAFailedWriteRollsBackAndRethrows(): void
    {
        $runner = new FakeWriteRunner(available: true, throwOnUpsert: true);
        $writer = new KnowledgeChunkWriter($runner);

        try {
            $writer->write($this->chunks());
            self::fail('expected the write to throw');
        } catch (\RuntimeException $e) {
            self::assertContains('ROLLBACK', $runner->log);
            self::assertNotContains('COMMIT', $runner->log);
        }
    }

    public function testInvalidTableNameIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        new KnowledgeChunkWriter(new FakeWriteRunner(available: true), 'chunks; DROP TABLE x');
    }
}

/**
 * A canned {@see KnowledgeWriteRunner} that records the sequence of operations.
 */
final class FakeWriteRunner implements KnowledgeWriteRunner
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(
        private readonly bool $available,
        private readonly bool $throwOnUpsert = false,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function begin(): void
    {
        $this->log[] = 'BEGIN';
    }

    public function commit(): void
    {
        $this->log[] = 'COMMIT';
    }

    public function rollback(): void
    {
        $this->log[] = 'ROLLBACK';
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_contains($sql, 'DELETE')) {
            $this->log[] = 'DELETE:' . (string)($params['source'] ?? '');

            return 1;
        }
        if ($this->throwOnUpsert) {
            throw new \RuntimeException('simulated write failure');
        }
        $this->log[] = 'UPSERT:' . (string)($params['id'] ?? '');

        return 1;
    }
}
