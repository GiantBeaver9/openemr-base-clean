<?php

/**
 * GlobalConfig — typed access to the module's configuration surface.
 *
 * Deployment settings that are NOT clinical config (which lives versioned in
 * mod_copilot_cadence) are read here: the Vertex project/region/model pins, the
 * feature flag, and whether real generation is enabled. Values come from host globals
 * or environment; nothing here is a digest input.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final class GlobalConfig
{
    public const KEY_ENABLED = 'clinical_copilot_enabled';
    public const KEY_VERTEX_PROJECT = 'clinical_copilot_vertex_project';
    public const KEY_VERTEX_LOCATION = 'clinical_copilot_vertex_location';
    public const KEY_VERTEX_MODEL_PRO = 'clinical_copilot_model_pro';
    public const KEY_VERTEX_MODEL_FLASH = 'clinical_copilot_model_flash';

    /**
     * @param array<string, mixed> $globals the host $GLOBALS bag (injected, never read ad hoc)
     */
    public function __construct(private readonly array $globals)
    {
    }

    public function isEnabled(): bool
    {
        return !empty($this->globals[self::KEY_ENABLED]);
    }

    public function vertexProject(): string
    {
        return (string) ($this->globals[self::KEY_VERTEX_PROJECT] ?? getenv('COPILOT_VERTEX_PROJECT') ?: '');
    }

    public function vertexLocation(): string
    {
        return (string) ($this->globals[self::KEY_VERTEX_LOCATION] ?? getenv('COPILOT_VERTEX_LOCATION') ?: 'us-central1');
    }

    public function modelPro(): string
    {
        return (string) ($this->globals[self::KEY_VERTEX_MODEL_PRO] ?? getenv('COPILOT_MODEL_PRO') ?: 'gemini-2.5-pro');
    }

    public function modelFlash(): string
    {
        return (string) ($this->globals[self::KEY_VERTEX_MODEL_FLASH] ?? getenv('COPILOT_MODEL_FLASH') ?: 'gemini-2.5-flash');
    }
}
