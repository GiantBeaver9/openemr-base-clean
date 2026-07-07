<?php

/**
 * EgressRedactor — swaps direct identifiers for stable per-session pseudonym tokens before
 * anything leaves the process, and re-hydrates them in the rendered answer after
 * verification (ARCHITECTURE.md §4 boundary 3).
 *
 * The model reasons over clinical values, never over identity: a leaked prompt or completion
 * exposes tokens, not a person. Honest scope — this is *minimization, not de-identification*:
 * quasi-identifiers (dates, rare lab values) deliberately remain, so the fact bytes stay
 * intact and the digest is unaffected. Tokens are derived from a per-session seed so they are
 * unguessable across sessions yet deterministic within one, and shaped so they never collide
 * with clinical text.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final class EgressRedactor
{
    /**
     * Build the per-session token map for a patient's direct identifiers. The session seed
     * (e.g. the chat session id or correlation id) makes tokens stable within a session and
     * distinct across sessions, without leaking the identifier into the token.
     */
    public function buildMap(PatientContext $context, string $sessionSeed): RedactionMap
    {
        $entries = [];
        foreach ($context->directIdentifiers() as $kind => $value) {
            $entries[] = [
                'kind' => $kind,
                'original' => $value,
                'token' => $this->mintToken($kind, $value, $sessionSeed),
            ];
        }
        return new RedactionMap($entries);
    }

    /**
     * Replace every direct identifier in a text with its pseudonym token. Longest identifiers
     * are substituted first so a shorter identifier that is a substring of a longer one never
     * corrupts the longer replacement.
     */
    public function redactText(string $text, RedactionMap $map): string
    {
        foreach ($map->originalsLongestFirst() as $original) {
            $token = $map->tokenFor($original);
            if ($token !== null) {
                $text = str_replace($original, $token, $text);
            }
        }
        return $text;
    }

    /**
     * Redact an outbound request: both the system prompt and the user content are rewritten
     * through the map before the request is handed to the LlmClient. Returns a new request;
     * the assembled prompt is never mutated.
     */
    public function redactRequest(LlmRequest $request, RedactionMap $map): LlmRequest
    {
        return $request
            ->withSystemPrompt($this->redactText($request->systemPrompt, $map))
            ->withUserContent($this->redactText($request->userContent, $map));
    }

    /**
     * Restore original identifiers in a rendered answer AFTER verification. Lossless: every
     * token maps back to exactly the string it replaced.
     */
    public function rehydrate(string $text, RedactionMap $map): string
    {
        foreach ($map->tokens() as $token) {
            $original = $map->originalFor($token);
            if ($original !== null) {
                $text = str_replace($token, $original, $text);
            }
        }
        return $text;
    }

    /**
     * A token shaped so it cannot occur in clinical prose and cannot be confused with a
     * value: an upper-cased kind plus a short session-scoped digest, wrapped in sentinels.
     */
    private function mintToken(string $kind, string $value, string $sessionSeed): string
    {
        $digest = substr(hash('sha256', $sessionSeed . '|' . $kind . '|' . $value), 0, 10);
        return '[[PT_' . strtoupper($kind) . '_' . $digest . ']]';
    }
}
