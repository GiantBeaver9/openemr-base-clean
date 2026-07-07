<?php

/**
 * ToolValidation — the result of schema-validating a model-proposed tool call (§1.2, R3).
 *
 * Either valid with a SANITIZED argument map (known properties only — any forged extra key,
 * a `pid` above all, is dropped here so it can never reach the capability) or invalid with a
 * single human/model-readable reason. The sanitized args deliberately never include a patient
 * identifier: pinning is injected server-side by the executor, never taken from the model.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final readonly class ToolValidation
{
    /**
     * @param array<string, mixed> $sanitizedArgs known properties only; never a patient id
     */
    private function __construct(
        public bool $valid,
        public array $sanitizedArgs,
        public ?string $error,
    ) {
    }

    /**
     * @param array<string, mixed> $sanitizedArgs
     */
    public static function ok(array $sanitizedArgs): self
    {
        return new self(true, $sanitizedArgs, null);
    }

    public static function invalid(string $error): self
    {
        return new self(false, [], $error);
    }
}
