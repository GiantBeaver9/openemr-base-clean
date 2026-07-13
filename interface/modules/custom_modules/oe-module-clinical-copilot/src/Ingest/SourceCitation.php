<?php

/**
 * The Week 2 machine-readable citation contract for a document-sourced fact.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * The spec's minimum citation shape — {source_type, source_id, page_or_section,
 * field_or_chunk_id, quote_or_value} — plus the page bounding box that powers
 * the required visual overlay. This is deliberately a SEPARATE value object
 * from the Week 1 {@see \OpenEMR\Modules\ClinicalCopilot\Fact\Citation} (which
 * points at a live core-table row and whose `table`/`pk > 0` invariants a
 * document source cannot satisfy). Once a locked lab commits to
 * `procedure_result`, the Week 1 lab reader re-grounds it in a normal core-table
 * Citation on read — so the two citation models never have to merge.
 */
final readonly class SourceCitation
{
    public function __construct(
        public SourceType $sourceType,
        public string $sourceId,
        public ?int $pageOrSection,
        public ?string $fieldOrChunkId,
        public string $quoteOrValue,
        public ?BoundingBox $bbox = null,
    ) {
        if ($sourceId === '') {
            throw new \DomainException('SourceCitation.sourceId must not be empty');
        }

        if ($quoteOrValue === '') {
            throw new \DomainException('SourceCitation.quoteOrValue must not be empty');
        }
    }

    /**
     * @return array{source_type: string, source_id: string, page_or_section: int|null, field_or_chunk_id: string|null, quote_or_value: string, bbox: list<int>|null}
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType->value,
            'source_id' => $this->sourceId,
            'page_or_section' => $this->pageOrSection,
            'field_or_chunk_id' => $this->fieldOrChunkId,
            'quote_or_value' => $this->quoteOrValue,
            'bbox' => $this->bbox?->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sourceTypeRaw = $data['source_type'] ?? null;
        if (!is_string($sourceTypeRaw) || SourceType::tryFrom($sourceTypeRaw) === null) {
            throw new \InvalidArgumentException('SourceCitation.source_type is missing or unrecognized');
        }

        $sourceId = $data['source_id'] ?? null;
        if (!is_string($sourceId)) {
            throw new \InvalidArgumentException('SourceCitation.source_id must be a string');
        }

        $quote = $data['quote_or_value'] ?? null;
        if (!is_string($quote)) {
            throw new \InvalidArgumentException('SourceCitation.quote_or_value must be a string');
        }

        $page = $data['page_or_section'] ?? null;
        if ($page !== null && !is_int($page)) {
            throw new \InvalidArgumentException('SourceCitation.page_or_section must be an int or null');
        }

        $fieldOrChunk = $data['field_or_chunk_id'] ?? null;
        if ($fieldOrChunk !== null && !is_string($fieldOrChunk)) {
            throw new \InvalidArgumentException('SourceCitation.field_or_chunk_id must be a string or null');
        }

        $bbox = null;
        if (isset($data['bbox']) && is_array($data['bbox']) && count($data['bbox']) === 4) {
            $c = array_values($data['bbox']);
            $bbox = new BoundingBox((int)$c[0], (int)$c[1], (int)$c[2], (int)$c[3]);
        }

        return new self(
            SourceType::from($sourceTypeRaw),
            $sourceId,
            $page,
            $fieldOrChunk,
            $quote,
            $bbox,
        );
    }
}
