<?php

/**
 * ChatPromptAssembler — builds the pinned chat turn's LlmRequest (§1.1, §1.3, §2.1).
 *
 * Two hard contracts, mirroring the synthesis PromptAssembler:
 *  1. The FACT bytes are EXACTLY CanonicalSerializer->serialize(facts) — the same bytes that feed
 *     the digest and the seed — so citation resolution (V2) is unambiguous across the session (T19).
 *  2. The system prompt states the role and the hard refusals verbatim from USERS.md §1 / §1.4 —
 *     no causation, no recommendations, no diagnoses, no general medical Q&A, and off-patient
 *     questions are structurally impossible AND refused in prose. The verifier enforces these
 *     deterministically; stating them reduces attempts.
 *
 * Direct identifiers never appear here — the EgressRedactor rewrites them out of the assembled
 * request before egress and rehydrates them only after verification (§4).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmRequest;

final class ChatPromptAssembler
{
    public const FACTS_OPEN = '<facts canonical="1">';
    public const FACTS_CLOSE = '</facts>';

    private const ROLE =
        "You are a clinical pre-visit chat assistant embedded in an EMR, pinned to ONE patient. You "
        . "answer the treating physician's follow-up questions using ONLY the facts you are given or "
        . "that you retrieve through the provided tools. You describe what the facts state, with a "
        . "citation (fact_id) for every clinical claim. You request tools; you never query anything "
        . "yourself and you never see another patient's data.";

    /** @var list<string> hard refusals — mirrors USERS.md §1 and §1.4 */
    private const REFUSALS = [
        'Do NOT assert causation between any medication and any lab or vital (no "because", "due to", "caused", "led to").',
        'Do NOT make treatment recommendations (no "should start/increase/stop", no dosing advice).',
        'Do NOT state diagnoses or interpret findings beyond the literal facts.',
        'Do NOT answer general medical questions ("what is the target A1c for her age?"); point the physician to their own guidelines instead.',
        'This session is pinned to one patient; you cannot and must not answer about any other patient — refuse off-patient questions.',
        'Every clinical claim MUST cite the fact_id(s) it rests on. Emit ONLY the provided JSON claim schema. To retrieve more, emit a tool call — no tool accepts a patient id.',
    ];

    public function __construct(private readonly CanonicalSerializer $serializer = new CanonicalSerializer())
    {
    }

    /**
     * Assemble the first request for a turn: system prompt + tools, and a user content block that
     * carries the seed narrative, the canonical fact set, prior conversation, and the new message.
     *
     * @param list<ChatTurn>             $history        prior turns (verbatim, oldest-first)
     * @param list<array<string, mixed>> $toolDeclarations native function declarations
     */
    public function assemble(
        FactSet $facts,
        string $narrative,
        array $history,
        string $userMessage,
        string $model,
        array $toolDeclarations,
        ?int $maxOutputTokens = null,
    ): LlmRequest {
        return new LlmRequest(
            $this->buildSystemPrompt($narrative),
            $this->buildUserContent($facts, $history, $userMessage),
            $this->chatResponseSchema(),
            $model,
            $maxOutputTokens,
            $toolDeclarations,
        );
    }

    /**
     * Append rendered tool results to the request's user content for the next agent round. The
     * assembled prompt is never mutated — a new request is returned (LlmRequest is immutable).
     *
     * @param list<string> $renderedToolResults each a delimited "<tool_result …>" block
     */
    public function withToolResults(LlmRequest $request, array $renderedToolResults): LlmRequest
    {
        $appended = $request->userContent . "\n\n" . implode("\n", $renderedToolResults);
        return $request->withUserContent($appended);
    }

    /**
     * Append the verifier's findings for the single permitted regeneration (§2.3).
     *
     * @param list<string> $findings
     */
    public function withVerifierFindings(LlmRequest $request, array $findings): LlmRequest
    {
        $lines = ['<verifier_findings>', 'Your previous answer failed verification. Fix ONLY these and re-answer:'];
        foreach ($findings as $finding) {
            $lines[] = '- ' . $finding;
        }
        $lines[] = '</verifier_findings>';
        $appended = $request->userContent . "\n\n" . implode("\n", $lines);
        return $request->withUserContent($appended);
    }

    private function buildSystemPrompt(string $narrative): string
    {
        $lines = [self::ROLE, '', 'Hard refusals (enforced deterministically downstream):'];
        foreach (self::REFUSALS as $refusal) {
            $lines[] = '- ' . $refusal;
        }
        if (trim($narrative) !== '') {
            $lines[] = '';
            $lines[] = 'The pre-visit synthesis the physician is reading (context only — re-cite facts, do not restate as new claims):';
            $lines[] = '<narrative>';
            $lines[] = $narrative;
            $lines[] = '</narrative>';
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<ChatTurn> $history
     */
    private function buildUserContent(FactSet $facts, array $history, string $userMessage): string
    {
        $factBytes = $this->serializer->serialize($facts->facts);

        $parts = [
            'The facts below are the current pinned fact set for this patient (pid ' . $facts->pid . '). Answer only from these or tool results.',
            self::FACTS_OPEN,
            $factBytes,
            self::FACTS_CLOSE,
        ];

        if ($history !== []) {
            $parts[] = '';
            $parts[] = '<conversation>';
            foreach ($history as $turn) {
                $parts[] = $turn->role->value . ': ' . $turn->content;
            }
            $parts[] = '</conversation>';
        }

        $parts[] = '';
        $parts[] = '<user_message>';
        $parts[] = $userMessage;
        $parts[] = '</user_message>';

        return implode("\n", $parts);
    }

    /**
     * The provider-enforced chat response schema: the §2.1 claim list, with the full ClaimType
     * enum the chat may legally emit (a superset of the synthesis types). U10 owns the semantic
     * checks; this only constrains shape.
     *
     * @return array<string, mixed>
     */
    public function chatResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'claims' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'claim_type' => [
                                'type' => 'string',
                                'enum' => [
                                    'greeting',
                                    'refusal',
                                    'retrieval_status',
                                    'uncertainty_statement',
                                    'result',
                                    'trend',
                                    'med_event',
                                    'overdue',
                                    'pending',
                                    'comparison',
                                    'summary',
                                    'observation',
                                    'conflict',
                                    'exclusion_note',
                                ],
                            ],
                            'citation_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'numeric_values' => ['type' => 'array', 'items' => ['type' => 'number']],
                            'flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['text', 'claim_type', 'citation_ids'],
                    ],
                ],
                'ordering' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['claims'],
        ];
    }
}
