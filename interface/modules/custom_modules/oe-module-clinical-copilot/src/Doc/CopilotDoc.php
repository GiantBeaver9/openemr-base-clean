<?php

/**
 * CopilotDoc — an immutable, content-addressed synthesis document row (T7).
 *
 * One row of mod_copilot_doc: the served document (facts + citations + narrative) plus
 * the versions and observability metadata that produced it. The (pid, fact_digest) pair
 * is the content address — the same fact set at the same versions always yields the same
 * digest, so a recurrence serves the original row rather than writing a new one. The store
 * is append-only (T7): this object is never mutated in place.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Doc;

final readonly class CopilotDoc
{
    public function __construct(
        public int $pid,
        public string $factDigest,          // sha3-256 content address (facts + versions)
        public string $docType,             // e.g. endo-previsit-v1
        public ?int $apptId,                // metadata only, never a key
        public string $doc,                 // JSON: facts + citations + narrative
        public string $capabilityVersions,  // JSON map capability => version
        public string $promptVersion,
        public string $computedAt,          // DATETIME; display only, never freshness (I1)
        public string $correlationId,
        public ?int $llmLatencyMs = null,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?float $costUsd = null,
        public ?string $excludedCounts = null, // JSON per-analyte exclusion counts
        public ?int $id = null,             // null before the row is persisted
    ) {
    }

    /**
     * Insert payload keyed by column name (id is auto-increment, so it is omitted).
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'pid' => $this->pid,
            'fact_digest' => $this->factDigest,
            'doc_type' => $this->docType,
            'appt_id' => $this->apptId,
            'doc' => $this->doc,
            'capability_versions' => $this->capabilityVersions,
            'prompt_version' => $this->promptVersion,
            'computed_at' => $this->computedAt,
            'correlation_id' => $this->correlationId,
            'llm_latency_ms' => $this->llmLatencyMs,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost_usd' => $this->costUsd,
            'excluded_counts' => $this->excludedCounts,
        ];
    }

    /**
     * Reconstruct from a stored row. DB drivers return every column as a string, so
     * numeric columns are narrowed here; NULLs stay NULL.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['pid'],
            (string) $row['fact_digest'],
            (string) $row['doc_type'],
            self::asNullableInt($row['appt_id'] ?? null),
            (string) $row['doc'],
            (string) $row['capability_versions'],
            (string) $row['prompt_version'],
            (string) $row['computed_at'],
            (string) $row['correlation_id'],
            self::asNullableInt($row['llm_latency_ms'] ?? null),
            self::asNullableInt($row['tokens_in'] ?? null),
            self::asNullableInt($row['tokens_out'] ?? null),
            self::asNullableFloat($row['cost_usd'] ?? null),
            self::asNullableString($row['excluded_counts'] ?? null),
            self::asNullableInt($row['id'] ?? null),
        );
    }

    private static function asNullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function asNullableFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private static function asNullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
