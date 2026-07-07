<?php

/**
 * Comparator enum — censored-value direction for lab results (lab contract C3).
 *
 * A comparator other than None marks a censored value ("<7.0"): the fact supports
 * only claims its direction proves, is plotted with a marker, and is never treated
 * as an exact number.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

enum Comparator: string
{
    case None = 'none';
    case Lt = 'lt';
    case Lte = 'lte';
    case Gt = 'gt';
    case Gte = 'gte';

    public function isCensored(): bool
    {
        return $this !== self::None;
    }

    /**
     * Map a raw comparator token ("<", "<=", ">", ">=") to the enum.
     */
    public static function fromToken(string $token): self
    {
        return match (trim($token)) {
            '<' => self::Lt,
            '<=', '=<' => self::Lte,
            '>' => self::Gt,
            '>=', '=>' => self::Gte,
            default => self::None,
        };
    }
}
