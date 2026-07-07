<?php

/**
 * RedactionMap — the per-session, bidirectional identifier↔pseudonym table (§4).
 *
 * Built once per chat/reduce session from the patient's direct identifiers. Redaction walks
 * original→token; rehydration walks token→original. The mapping is lossless for tokens: a
 * token round-trips back to exactly the string it replaced. Tokens are stable within a
 * session (same identifier ⇒ same token) so a leaked prompt exposes only opaque tokens, and
 * the rendered answer can be re-hydrated deterministically after verification.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final readonly class RedactionMap
{
    /** @var array<string, string> original identifier string => pseudonym token */
    private array $toToken;

    /** @var array<string, string> pseudonym token => original identifier string */
    private array $toOriginal;

    /**
     * @param list<array{kind: string, original: string, token: string}> $entries
     */
    public function __construct(public array $entries)
    {
        $toToken = [];
        $toOriginal = [];
        foreach ($entries as $entry) {
            $toToken[$entry['original']] = $entry['token'];
            $toOriginal[$entry['token']] = $entry['original'];
        }
        $this->toToken = $toToken;
        $this->toOriginal = $toOriginal;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Original strings, ordered longest-first so redaction never leaves a substring of a
     * longer identifier behind (e.g. a first name that is a prefix of the full name).
     *
     * @return list<string>
     */
    public function originalsLongestFirst(): array
    {
        $originals = array_keys($this->toToken);
        usort($originals, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return array_values($originals);
    }

    public function tokenFor(string $original): ?string
    {
        return $this->toToken[$original] ?? null;
    }

    public function originalFor(string $token): ?string
    {
        return $this->toOriginal[$token] ?? null;
    }

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        return array_values($this->toToken);
    }
}
