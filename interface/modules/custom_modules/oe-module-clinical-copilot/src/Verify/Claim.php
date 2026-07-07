<?php

/**
 * Claim — one immutable, typed unit of model output (ARCHITECTURE.md §2.1).
 *
 * The model emits a list of these; the verifier gates them in order (V1–V6). A Claim is the
 * parsed, schema-valid realization of a raw claim object — `fromArray()` is the V1 boundary
 * (parse, don't validate): once a Claim exists it is structurally sound, and the semantic checks
 * V2–V6 operate on types, not raw arrays.
 *
 * `numericValues` is stored as a list of strings (the model may declare numbers or numeric
 * strings; both are coerced) but the AUTHORITATIVE numeric-grounding surface is the claim TEXT
 * (V4 extracts from text so the model cannot smuggle an ungrounded number into prose it omitted
 * from the declared list). Ordering/emphasis metadata is carried for persistence and the
 * second-pass reviewer (§2.5); the deterministic checks deliberately ignore it (§2.4 residual).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

final readonly class Claim
{
    /**
     * @param list<string> $citationIds   fact_id references the claim rests on
     * @param list<string> $numericValues numbers the model declared (advisory; text is authoritative)
     * @param list<string> $flags         claim-level flags — 'conflict' acknowledgment lives here (V6)
     */
    public function __construct(
        public string $text,
        public ClaimType $claimType,
        public array $citationIds,
        public array $numericValues = [],
        public array $flags = [],
        public ?int $orderRank = null,
        public ?string $emphasis = null,
    ) {
    }

    /**
     * V1 schema gate for a single raw claim object. Throws ClaimSchemaException on any structural
     * violation. Numbers are coerced to canonical strings; unknown keys are ignored (forward-compat).
     *
     * @param array<string, mixed> $raw
     *
     * @throws ClaimSchemaException
     */
    public static function fromArray(array $raw): self
    {
        if (!array_key_exists('text', $raw) || !is_string($raw['text']) || trim($raw['text']) === '') {
            throw new ClaimSchemaException('claim is missing non-empty "text"');
        }

        if (!array_key_exists('claim_type', $raw) || !is_string($raw['claim_type'])) {
            throw new ClaimSchemaException('claim is missing string "claim_type"');
        }
        $claimType = ClaimType::tryFrom($raw['claim_type']);
        if ($claimType === null) {
            throw new ClaimSchemaException('claim declares an unknown claim_type');
        }

        return new self(
            $raw['text'],
            $claimType,
            self::stringList($raw['citation_ids'] ?? [], 'citation_ids'),
            self::numericStringList($raw['numeric_values'] ?? []),
            self::stringList($raw['flags'] ?? [], 'flags'),
            isset($raw['order_rank']) && is_int($raw['order_rank']) ? $raw['order_rank'] : null,
            isset($raw['emphasis']) && is_string($raw['emphasis']) ? $raw['emphasis'] : null,
        );
    }

    /**
     * Parse a whole raw response payload's "claims" array into typed Claims (V1 for the list).
     * Throws if "claims" is absent or not a list, or if any member fails to parse — that is the
     * "free prose without claim structure is rejected" gate.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<self>
     *
     * @throws ClaimSchemaException
     */
    public static function listFromPayload(array $payload): array
    {
        if (!array_key_exists('claims', $payload) || !is_array($payload['claims'])) {
            throw new ClaimSchemaException('response payload has no "claims" array');
        }

        $claims = [];
        foreach (array_values($payload['claims']) as $index => $rawClaim) {
            if (!is_array($rawClaim)) {
                throw new ClaimSchemaException('claim ' . ($index + 1) . ' is not an object');
            }
            /** @var array<string, mixed> $rawClaim */
            $claims[] = self::fromArray($rawClaim);
        }

        return $claims;
    }

    public function hasCitations(): bool
    {
        return $this->citationIds !== [];
    }

    public function hasFlag(string $token): bool
    {
        return in_array($token, $this->flags, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'claim_type' => $this->claimType->value,
            'citation_ids' => $this->citationIds,
            'numeric_values' => $this->numericValues,
            'flags' => $this->flags,
            'order_rank' => $this->orderRank,
            'emphasis' => $this->emphasis,
        ];
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     *
     * @throws ClaimSchemaException
     */
    private static function stringList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new ClaimSchemaException($field . ' must be an array');
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ClaimSchemaException($field . ' entries must be strings');
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Numeric values may arrive as numbers or numeric strings; coerce to canonical strings.
     *
     * @param mixed $value
     *
     * @return list<string>
     *
     * @throws ClaimSchemaException
     */
    private static function numericStringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ClaimSchemaException('numeric_values must be an array');
        }
        $out = [];
        foreach ($value as $item) {
            if (is_int($item) || is_float($item)) {
                $out[] = (string) $item;
            } elseif (is_string($item)) {
                $out[] = $item;
            } else {
                throw new ClaimSchemaException('numeric_values entries must be numbers or strings');
            }
        }
        return $out;
    }
}
