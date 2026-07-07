<?php

/**
 * FactKind enum — the closed set of fact kinds a capability may emit.
 *
 * derived_* kinds are computed deterministically by capabilities (never by the
 * LLM, never by the verifier) and cite the raw facts they derive from — this is
 * what lets verifier V4 stay strict while prose says "rose 0.6 over three draws".
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

enum FactKind: string
{
    case Result = 'result';
    case TrendPoint = 'trend_point';
    case MedEvent = 'med_event';
    case Vital = 'vital';
    case OverdueItem = 'overdue_item';
    case PendingOrder = 'pending_order';
    case PreliminaryResult = 'preliminary_result';
    case Exclusion = 'exclusion';
    case Conflict = 'conflict';
    case DerivedDelta = 'derived_delta';
    case DerivedCount = 'derived_count';
    case DerivedSpan = 'derived_span';
    case ExpectedResultDate = 'expected_result_date';

    /**
     * Kinds that carry a numeric value block subject to C3/C4 parsing rules.
     */
    public function hasValue(): bool
    {
        return match ($this) {
            self::Result, self::TrendPoint, self::Vital => true,
            default => false,
        };
    }

    /**
     * Derived kinds must cite the raw facts they were computed from (V4).
     */
    public function isDerived(): bool
    {
        return match ($this) {
            self::DerivedDelta, self::DerivedCount, self::DerivedSpan, self::ExpectedResultDate => true,
            default => false,
        };
    }
}
