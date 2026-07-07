<?php

/**
 * ChatTurn — one immutable, append-only turn row (mod_copilot_chat_turn, T7).
 *
 * The turn ledger is the provenance record: byte-for-byte what the physician saw, the tool
 * requests+results behind it, the full V1–V6 verdict, and the cost/token accounting — all
 * keyed to the turn's correlation_id so it joins to its trace spans (§3). Nothing here is
 * ever mutated; a correction is a new turn, never an edit.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final readonly class ChatTurn
{
    public function __construct(
        public int $sessionId,
        public int $seq,
        public TurnRole $role,
        public string $content,
        public ?string $toolCalls,
        public ?string $verificationVerdict,
        public string $correlationId,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?float $costUsd = null,
        public ?int $id = null,
        public ?string $createdAt = null,
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
            'session_id' => $this->sessionId,
            'seq' => $this->seq,
            'role' => $this->role->value,
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
            'verification_verdict' => $this->verificationVerdict,
            'correlation_id' => $this->correlationId,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost_usd' => $this->costUsd,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['session_id'],
            (int) $row['seq'],
            TurnRole::from((string) $row['role']),
            (string) $row['content'],
            self::asNullableString($row['tool_calls'] ?? null),
            self::asNullableString($row['verification_verdict'] ?? null),
            (string) $row['correlation_id'],
            self::asNullableInt($row['tokens_in'] ?? null),
            self::asNullableInt($row['tokens_out'] ?? null),
            self::asNullableFloat($row['cost_usd'] ?? null),
            self::asNullableInt($row['id'] ?? null),
            self::asNullableString($row['created_at'] ?? null),
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
