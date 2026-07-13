<?php

/**
 * Topic-grouped guideline evidence: right guidance per topic, cited, closed-vocabulary.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Rag;

use OpenEMR\Modules\ClinicalCopilot\Ingest\SourceType;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\PatientEvidenceService;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: the augmentation surfacing the wrong topic's guidance,
 * an uncited snippet, or accepting a free-text topic (the closed-vocabulary rule
 * that mirrors the chat catalog's design).
 */
final class PatientEvidenceServiceTest extends TestCase
{
    private function service(): PatientEvidenceService
    {
        return new PatientEvidenceService(
            new SparseRetriever(new GuidelineCorpus(dirname(__DIR__, 3) . '/src/Rag/corpus')),
        );
    }

    public function testKnownTopicsAreRecognizedAndUnknownRejected(): void
    {
        self::assertTrue(PatientEvidenceService::isTopic('a1c'));
        self::assertTrue(PatientEvidenceService::isTopic('kidney'));
        self::assertFalse(PatientEvidenceService::isTopic('drop table patients'));
        self::assertFalse(PatientEvidenceService::isTopic(''));
    }

    public function testEachTopicRetrievesRelevantCitedEvidence(): void
    {
        $groups = $this->service()->forTopics(['a1c', 'kidney']);

        self::assertCount(2, $groups);
        foreach ($groups as $group) {
            self::assertNotSame([], $group['snippets'], "topic {$group['key']} returned no evidence");
            foreach ($group['snippets'] as $snippet) {
                self::assertSame(SourceType::Guideline, $snippet->citation->sourceType);
                self::assertNotSame('', $snippet->citation->quoteOrValue);
            }
        }

        $a1c = $groups[0];
        self::assertSame('a1c', $a1c['key']);
        $ids = array_map(static fn ($s) => $s->chunk->id, $a1c['snippets']);
        self::assertContains('ada-a1c-target', $ids);
    }

    public function testUnknownTopicsAreSkipped(): void
    {
        $groups = $this->service()->forTopics(['a1c', 'nonsense-topic']);
        self::assertCount(1, $groups);
        self::assertSame('a1c', $groups[0]['key']);
    }

    public function testAvailableTopicsAreClosedVocabulary(): void
    {
        $keys = array_map(static fn (array $t): string => $t['key'], PatientEvidenceService::availableTopics());
        self::assertContains('a1c', $keys);
        self::assertContains('lipids', $keys);
        self::assertNotContains('', $keys);
    }
}
