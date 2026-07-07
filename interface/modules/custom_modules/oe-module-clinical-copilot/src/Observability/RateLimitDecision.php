<?php

/**
 * RateLimitDecision — the outcome of a rate-limit check (§3.7).
 *
 * Either allowed (reason null) or denied with a typed reason that already knows its HTTP
 * status and client-safe hint. Immutable; produced only by RateLimiter::decide().
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final readonly class RateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public ?RateLimitReason $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(RateLimitReason $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * HTTP status to return: 200 when allowed, else the reason's status.
     */
    public function httpStatus(): int
    {
        return $this->reason?->httpStatus() ?? 200;
    }

    /**
     * Client-safe hint, or empty string when allowed.
     */
    public function clientHint(): string
    {
        return $this->reason?->clientHint() ?? '';
    }
}
