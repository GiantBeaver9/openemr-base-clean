<?php

/**
 * PromptAssembler — builds the reduce prompt from a pinned FactSet.
 *
 * Two hard contracts:
 *  1. The FACT bytes embedded in the prompt are EXACTLY CanonicalSerializer->serialize(facts)
 *     — the same bytes that feed the digest (ARCHITECTURE_COMPLETE.md compute model). If the
 *     prompt and the digest ever disagreed, a content-addressed doc could be served over facts
 *     the model never actually saw.
 *  2. The system prompt states the role and the hard refusals verbatim from USERS.md §1 — no
 *     causation, no recommendations, no diagnoses, no general medical Q&A. The verifier (U10)
 *     enforces these deterministically; stating them in the prompt reduces attempts.
 *
 * Direct identifiers ride in a patient-context header (redacted at egress by EgressRedactor),
 * never in the fact block — so the fact bytes stay byte-identical to the digest input.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;

final class PromptAssembler
{
    public const FACTS_OPEN = '<facts canonical="1">';
    public const FACTS_CLOSE = '</facts>';

    private const ROLE =
        "You are a clinical pre-visit synthesis assistant embedded in an EMR. You summarize a "
        . "single pinned patient's structured facts for the treating physician. You describe only "
        . "what the provided facts state, with a citation for every clinical claim.";

    /** @var list<string> hard refusals — mirrors USERS.md §1 */
    private const REFUSALS = [
        'Do NOT assert causation between any medication and any lab or vital (no "because", "due to", "caused", "led to").',
        'Do NOT make treatment recommendations (no "should start/increase/stop", no dosing advice).',
        'Do NOT state diagnoses or interpret findings beyond the literal facts.',
        'Do NOT answer general medical questions; point the physician to their own guidelines instead.',
        'Every clinical claim MUST cite the fact_id(s) it rests on. Emit only the provided JSON claim schema.',
    ];

    public function __construct(private readonly CanonicalSerializer $serializer = new CanonicalSerializer())
    {
    }

    /**
     * The canonical fact bytes for a fact list — identical to the digest input by construction.
     *
     * @param list<\OpenEMR\Modules\ClinicalCopilot\Fact\Fact> $facts
     */
    public function serializeFacts(array $facts): string
    {
        return $this->serializer->serialize($facts);
    }

    /**
     * Assemble the reduce request for a pinned patient. `promptVersion` is a digest input
     * upstream; it is echoed into the system prompt so a prompt change is a visible, versioned
     * event (E5 discipline).
     */
    public function assemble(
        FactSet $facts,
        PatientContext $context,
        string $model,
        string $promptVersion = 'prompt@1',
        ?int $maxOutputTokens = null,
    ): LlmRequest {
        $systemPrompt = $this->buildSystemPrompt($promptVersion);
        $userContent = $this->buildUserContent($facts, $context);

        return new LlmRequest(
            $systemPrompt,
            $userContent,
            $this->claimResponseSchema(),
            $model,
            $maxOutputTokens,
        );
    }

    private function buildSystemPrompt(string $promptVersion): string
    {
        $lines = [self::ROLE, '', 'Hard refusals (enforced deterministically downstream):'];
        foreach (self::REFUSALS as $refusal) {
            $lines[] = '- ' . $refusal;
        }
        $lines[] = '';
        $lines[] = 'prompt_version: ' . $promptVersion;
        return implode("\n", $lines);
    }

    private function buildUserContent(FactSet $facts, PatientContext $context): string
    {
        $factBytes = $this->serializeFacts($facts->facts);

        $header = [
            '<patient_context>',
            'pid: ' . $context->pid,
        ];
        foreach ($context->directIdentifiers() as $kind => $value) {
            $header[] = $kind . ': ' . $value;
        }
        $header[] = '</patient_context>';

        return implode("\n", [
            implode("\n", $header),
            '',
            'The facts below are the complete, canonical fact set for this patient. Summarize only these.',
            self::FACTS_OPEN,
            $factBytes,
            self::FACTS_CLOSE,
        ]);
    }

    /**
     * The provider-enforced response schema (Vertex responseSchema): a list of §2.1 claim
     * objects plus ordering metadata. U10 owns the semantic checks; this only constrains shape.
     *
     * @return array<string, mixed>
     */
    public function claimResponseSchema(): array
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
                                    'observation',
                                    'trend',
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
