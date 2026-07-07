<?php

/**
 * Gemini-via-Vertex-AI implementation of ChatLlmClientInterface (native function calling).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\FetchAuthTokenInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;

/**
 * T18 (ARCHITECTURE.md "LLM platform"): the SAME Vertex REST contract
 * {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient} uses for the
 * synthesis pass (ADC via `google/auth`, Guzzle with certificate
 * verification ON, no API keys) -- this class adds exactly one thing:
 * `tools: [{functionDeclarations: [...]}]` in the request body, and parses
 * the response for `functionCall` parts (tool-call requests, I13 -- the
 * model requests, this class never executes one) versus a plain `text` part
 * (the final claims JSON, when the model has no more functionCall parts to
 * emit). Deliberately the only other class in the module that imports
 * `Google\Auth`/`GuzzleHttp` transport classes, mirroring `VertexLlmClient`'s
 * own "one file owns the whole REST contract" discipline (T18).
 *
 * Degradation (I6): throws {@see LlmUnavailableException} -- never a
 * partial/empty {@see ChatLlmResponse} -- on missing ADC or an unreachable
 * endpoint, exactly {@see \OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient}'s
 * contract.
 */
final class VertexChatLlmClient implements ChatLlmClientInterface
{
    private const API_VERSION = 'v1';
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    private const TIMEOUT_SECONDS = 20.0;

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly string $projectId,
        private readonly string $location,
        ?ClientInterface $httpClient = null,
    ) {
        if ($this->projectId === '') {
            throw new \DomainException('VertexChatLlmClient.projectId must not be empty');
        }

        if ($this->location === '') {
            throw new \DomainException('VertexChatLlmClient.location must not be empty');
        }

        $this->httpClient = $httpClient ?? new Client(['verify' => true]);
    }

    public function converse(ChatLlmRequest $req): ChatLlmResponse
    {
        $accessToken = $this->fetchAccessToken();
        $url = $this->endpointUrl($req->prompt->model);

        $generationConfig = [
            'temperature' => $req->prompt->temperature,
            'maxOutputTokens' => $req->prompt->maxOutputTokens,
        ];

        $body = [
            'systemInstruction' => ['parts' => [['text' => $req->prompt->systemInstructions]]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $req->prompt->userContent]]],
            ],
            'generationConfig' => $generationConfig,
        ];

        if ($req->tools !== []) {
            $body['tools'] = [[
                'functionDeclarations' => array_map(
                    static fn ($tool): array => $tool->toDeclaration(),
                    $req->tools,
                ),
            ]];
        } else {
            // No tools offered this round (the post-retry final-answer
            // round, ChatLlmRequest's own docblock) -- constrain the reply
            // to the claim-list schema exactly like the reduce path does.
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $req->prompt->responseSchema;
            $body['generationConfig'] = $generationConfig;
        }

        $startedAt = microtime(true);

        try {
            $httpResponse = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
        } catch (GuzzleException $e) {
            throw LlmUnavailableException::unreachable($e);
        }

        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

        try {
            $decoded = json_decode((string)$httpResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw LlmUnavailableException::providerError($e);
        }

        if (!is_array($decoded)) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Vertex response was not a JSON object'));
        }

        $tokensIn = self::extractTokenCount($decoded, 'promptTokenCount');
        $tokensOut = self::extractTokenCount($decoded, 'candidatesTokenCount');
        $parts = self::extractParts($decoded);

        $toolCalls = self::extractToolCalls($parts);
        if ($toolCalls !== []) {
            return ChatLlmResponse::toolCalls($toolCalls, $req->prompt->model, $tokensIn, $tokensOut, $latencyMs);
        }

        $text = self::extractText($parts);

        return ChatLlmResponse::finalAnswer($text, $req->prompt->model, $tokensIn, $tokensOut, $latencyMs);
    }

    private function fetchAccessToken(): string
    {
        $credentials = $this->resolveCredentials();

        try {
            $token = $credentials->fetchAuthToken();
        } catch (\Throwable $e) {
            throw LlmUnavailableException::noCredentials($e);
        }

        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw LlmUnavailableException::noCredentials(new \RuntimeException('ADC did not return an access_token'));
        }

        return $accessToken;
    }

    private function resolveCredentials(): FetchAuthTokenInterface
    {
        try {
            /** @var FetchAuthTokenInterface $credentials */
            $credentials = ApplicationDefaultCredentials::getCredentials(self::OAUTH_SCOPE);

            return $credentials;
        } catch (\Throwable $e) {
            throw LlmUnavailableException::noCredentials($e);
        }
    }

    private function endpointUrl(string $model): string
    {
        return sprintf(
            'https://%s-aiplatform.googleapis.com/%s/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $this->location,
            self::API_VERSION,
            $this->projectId,
            $this->location,
            $model,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private static function extractParts(array $decoded): array
    {
        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Vertex response contained no candidates'));
        }

        $firstCandidate = $candidates[0];
        $parts = is_array($firstCandidate) ? ($firstCandidate['content']['parts'] ?? null) : null;
        if (!is_array($parts) || $parts === []) {
            throw LlmUnavailableException::providerError(new \RuntimeException('Vertex candidate contained no content parts'));
        }

        /** @var list<array<string, mixed>> $parts */
        return $parts;
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return list<ToolCallRequest>
     */
    private static function extractToolCalls(array $parts): array
    {
        $calls = [];
        foreach ($parts as $part) {
            $functionCall = $part['functionCall'] ?? null;
            if (!is_array($functionCall)) {
                continue;
            }
            $name = is_string($functionCall['name'] ?? null) ? $functionCall['name'] : '';
            $args = is_array($functionCall['args'] ?? null) ? $functionCall['args'] : [];
            if ($name === '') {
                continue;
            }
            /** @var array<string, mixed> $args */
            $calls[] = new ToolCallRequest($name, $args);
        }

        return $calls;
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    private static function extractText(array $parts): string
    {
        foreach ($parts as $part) {
            $text = $part['text'] ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        throw LlmUnavailableException::providerError(new \RuntimeException('Vertex candidate contained neither a functionCall nor a text part'));
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function extractTokenCount(array $decoded, string $field): int
    {
        $usage = $decoded['usageMetadata'] ?? null;
        $count = is_array($usage) ? ($usage[$field] ?? null) : null;

        return is_int($count) ? $count : 0;
    }
}
