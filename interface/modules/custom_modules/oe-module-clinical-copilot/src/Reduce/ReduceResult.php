<?php

/**
 * ReduceResult — what the Reducer hands back: RAW, unverified model output plus the facts and
 * the redaction map, for the verifier (U10) to gate later.
 *
 * U7 does NOT gate. On success this carries the model's raw LlmResponse (still tokenized —
 * rehydration happens after verification, §4). On degradation it carries no narrative, the
 * facts-only marker "narrative unavailable" (I6), and the same FactSet — the read path always
 * has facts to render regardless of the LLM.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;

final readonly class ReduceResult
{
    public const NARRATIVE_UNAVAILABLE = 'narrative unavailable';

    public function __construct(
        public ReduceStatus $status,
        public FactSet $facts,
        public RedactionMap $redactionMap,
        public string $correlationId,
        public ?LlmResponse $rawOutput = null,
        public ?string $degradedReason = null,
        public int $attempts = 0,
    ) {
    }

    public static function ok(
        FactSet $facts,
        RedactionMap $redactionMap,
        string $correlationId,
        LlmResponse $rawOutput,
        int $attempts,
    ): self {
        return new self(ReduceStatus::Ok, $facts, $redactionMap, $correlationId, $rawOutput, null, $attempts);
    }

    public static function degraded(
        FactSet $facts,
        RedactionMap $redactionMap,
        string $correlationId,
        int $attempts,
    ): self {
        return new self(
            ReduceStatus::Degraded,
            $facts,
            $redactionMap,
            $correlationId,
            null,
            self::NARRATIVE_UNAVAILABLE,
            $attempts,
        );
    }

    public function isDegraded(): bool
    {
        return $this->status === ReduceStatus::Degraded;
    }

    public function isNarrativeAvailable(): bool
    {
        return $this->status === ReduceStatus::Ok && $this->rawOutput !== null;
    }
}
