<?php

/**
 * VertexClient — Gemini on Google Vertex AI over the REST API (ARCHITECTURE.md LLM platform,
 * T18).
 *
 * Vertex is the HIPAA-eligible surface under the GCP BAA (never the consumer AI-Studio API).
 * Auth is ADC via the official google/auth library — no API keys in code or config. Structured
 * output is provider-enforced: `responseMimeType: application/json` + the request's
 * `responseSchema`. Model version strings are pinned (they fold into prompt_version, a digest
 * input) and project/location come from GlobalConfig.
 *
 * Deliberate safety choices: google/auth and Guzzle are resolved via late binding + class_exists
 * so this file lints and loads without vendor present (it is never exercised without creds in
 * this build); TLS certificate verification is forced on; PHI rides only in the POST body, never
 * in the URL or query string; a missing project or credential fails with a clear \RuntimeException
 * whose message carries no PHI.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Modules\ClinicalCopilot\GlobalConfig;

final class VertexClient implements LlmClient
{
    private const CLOUD_PLATFORM_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    private const HTTP_TIMEOUT_SECONDS = 20;

    /** @var \Closure(): string|null injectable access-token provider (tests); null ⇒ ADC */
    private readonly ?\Closure $accessTokenProvider;

    /**
     * @param object|null $httpClient a Guzzle-compatible client exposing request(); null ⇒ built lazily
     */
    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ?object $httpClient = null,
        ?\Closure $accessTokenProvider = null,
    ) {
        $this->accessTokenProvider = $accessTokenProvider;
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        $body = $this->buildGenerateBody($request);
        $startMicro = microtime(true);
        $decoded = $this->post($this->endpoint($request->model, 'generateContent'), $body);
        $latencyMs = (int) round((microtime(true) - $startMicro) * 1000.0);

        return $this->parseGenerateResponse($decoded, $request->model, $latencyMs);
    }

    public function countTokens(LlmRequest $request): int
    {
        $body = ['contents' => $this->buildContents($request)];
        $decoded = $this->post($this->endpoint($request->model, 'countTokens'), $body);

        $total = $decoded['totalTokens'] ?? null;
        if (!is_int($total)) {
            throw new LlmUnavailableException('Vertex countTokens returned no totalTokens');
        }
        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGenerateBody(LlmRequest $request): array
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $request->systemPrompt]]],
            'contents' => $this->buildContents($request),
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $request->responseSchema,
            ],
        ];
        if ($request->maxOutputTokens !== null) {
            $body['generationConfig']['maxOutputTokens'] = $request->maxOutputTokens;
        }
        if ($request->tools !== []) {
            $body['tools'] = [['functionDeclarations' => $request->tools]];
        }
        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildContents(LlmRequest $request): array
    {
        return [
            ['role' => 'user', 'parts' => [['text' => $request->userContent]]],
        ];
    }

    /**
     * Build the regional Vertex REST endpoint. PHI never appears here — only project, location,
     * pinned model, and the method verb.
     */
    private function endpoint(string $model, string $method): string
    {
        $project = $this->config->vertexProject();
        if ($project === '') {
            throw new \RuntimeException('Vertex project is not configured (COPILOT_VERTEX_PROJECT).');
        }
        if ($model === '') {
            throw new \RuntimeException('Vertex model is not pinned on the request.');
        }
        $location = $this->config->vertexLocation();

        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:%s',
            rawurlencode($location),
            rawurlencode($project),
            rawurlencode($location),
            rawurlencode($model),
            $method,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $url, array $body): array
    {
        $client = $this->resolveHttpClient();
        $token = $this->accessToken();

        $response = $client->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            // TLS certificate verification is mandatory — never disabled (§4 transmission).
            'verify' => true,
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => 5,
        ]);

        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LlmUnavailableException('Vertex response was not valid JSON');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function parseGenerateResponse(array $decoded, string $model, int $latencyMs): LlmResponse
    {
        $candidates = $decoded['candidates'] ?? [];
        $parts = [];
        if (is_array($candidates) && isset($candidates[0]['content']['parts']) && is_array($candidates[0]['content']['parts'])) {
            $parts = $candidates[0]['content']['parts'];
        }

        $json = [];
        $toolCalls = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $name = $part['functionCall']['name'] ?? '';
                $args = $part['functionCall']['args'] ?? [];
                $toolCalls[] = [
                    'name' => is_string($name) ? $name : '',
                    'args' => is_array($args) ? $args : [],
                ];
                continue;
            }
            if (isset($part['text']) && is_string($part['text']) && $json === []) {
                $parsed = json_decode($part['text'], true);
                if (is_array($parsed)) {
                    /** @var array<string, mixed> $parsed */
                    $json = $parsed;
                }
            }
        }

        $usage = $decoded['usageMetadata'] ?? [];
        $tokensIn = is_array($usage) && isset($usage['promptTokenCount']) && is_int($usage['promptTokenCount'])
            ? $usage['promptTokenCount'] : 0;
        $tokensOut = is_array($usage) && isset($usage['candidatesTokenCount']) && is_int($usage['candidatesTokenCount'])
            ? $usage['candidatesTokenCount'] : 0;

        return new LlmResponse($json, $tokensIn, $tokensOut, $model, $latencyMs, $toolCalls);
    }

    private function resolveHttpClient(): object
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }
        if (!class_exists('GuzzleHttp\\Client')) {
            throw new \RuntimeException('Guzzle HTTP client is unavailable; cannot reach Vertex AI.');
        }
        $class = 'GuzzleHttp\\Client';
        /** @var object $client */
        $client = new $class();
        return $client;
    }

    private function accessToken(): string
    {
        if ($this->accessTokenProvider !== null) {
            return ($this->accessTokenProvider)();
        }

        // ADC via google/auth — resolved by late binding so this file loads without the library.
        $adcClass = 'Google\\Auth\\ApplicationDefaultCredentials';
        if (!class_exists($adcClass)) {
            throw new \RuntimeException('google/auth (ADC) is unavailable; Vertex credentials cannot be resolved.');
        }

        try {
            /** @var callable $factory */
            $factory = [$adcClass, 'getCredentials'];
            $credentials = $factory(self::CLOUD_PLATFORM_SCOPE);
            if (!is_object($credentials) || !method_exists($credentials, 'fetchAuthToken')) {
                throw new \RuntimeException('ADC credentials object is not usable.');
            }
            $token = $credentials->fetchAuthToken();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to obtain Vertex access token via ADC.', 0, $e);
        }

        if (!is_array($token) || !isset($token['access_token']) || !is_string($token['access_token'])) {
            throw new \RuntimeException('ADC did not return an access token.');
        }
        return $token['access_token'];
    }
}
