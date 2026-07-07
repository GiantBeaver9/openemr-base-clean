<?php

/**
 * ToolRegistry — the five tools' STRICT input schemas + native function declarations (§1.2).
 *
 * The schemas here are the contract (R3): exactly the five §1.2 tools, exactly their argument
 * shapes, and — the load-bearing invariant — NO tool accepts a patient identifier. Validation
 * is deliberately allow-list only: it copies through the known, in-range properties and drops
 * everything else, so a forged `pid` (or any other injected key) is structurally discarded
 * before a capability ever runs. Server-side pid injection then wins by construction (I10).
 *
 * `declarations()` emits the native function-calling declarations the LlmRequest carries; the
 * provider proposes calls, the ToolExecutor disposes them (I13 — the model executes nothing).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final class ToolRegistry
{
    /** Window bounds: get_control_trend is 1..60 per §1.2; the others are a positive month count. */
    private const WINDOW_MIN = 1;
    private const CONTROL_WINDOW_MAX = 60;
    private const WINDOW_MAX_DEFAULT = 600;

    private const ANALYTE_ENUM = ['a1c', 'glucose', 'lipids'];
    private const METRIC_ENUM = ['weight', 'bp', 'bmi'];

    /**
     * Resolve a proposed tool name to the typed enum, or null if the model named a tool that
     * does not exist (a hallucinated tool — surfaced as a tool failure, never executed).
     */
    public function resolve(string $name): ?ToolName
    {
        return ToolName::tryFrom($name);
    }

    /**
     * Schema-validate + SANITIZE a proposed tool call's arguments. The returned args carry only
     * the known, in-range properties; a forged patient id (or any unknown key) is dropped, never
     * echoed. A structural violation yields ToolValidation::invalid with a precise reason.
     *
     * @param array<string, mixed> $args raw, model-supplied arguments (untrusted)
     */
    public function validate(ToolName $tool, array $args): ToolValidation
    {
        return match ($tool) {
            ToolName::GetControlTrend => $this->validateControlTrend($args),
            ToolName::GetMedHistory => $this->validateMedHistory($args),
            ToolName::GetVitalsTrend => $this->validateVitalsTrend($args),
            ToolName::GetOverdue, ToolName::GetPending => ToolValidation::ok([]),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function validateControlTrend(array $args): ToolValidation
    {
        $analyte = $this->requireEnum($args, 'analyte', self::ANALYTE_ENUM);
        if ($analyte === null) {
            return ToolValidation::invalid("get_control_trend requires 'analyte' to be one of: " . implode(', ', self::ANALYTE_ENUM) . '.');
        }
        $window = $this->requireInt($args, 'window_months', self::WINDOW_MIN, self::CONTROL_WINDOW_MAX);
        if ($window === null) {
            return ToolValidation::invalid("get_control_trend requires integer 'window_months' between 1 and 60.");
        }
        return ToolValidation::ok(['analyte' => $analyte, 'window_months' => $window]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function validateMedHistory(array $args): ToolValidation
    {
        $window = $this->requireInt($args, 'window_months', self::WINDOW_MIN, self::WINDOW_MAX_DEFAULT);
        if ($window === null) {
            return ToolValidation::invalid("get_med_history requires integer 'window_months' >= 1.");
        }
        $sanitized = ['window_months' => $window];

        // drug_filter is optional; only a non-empty string is honored, others dropped.
        if (array_key_exists('drug_filter', $args) && is_string($args['drug_filter']) && trim($args['drug_filter']) !== '') {
            $filter = trim($args['drug_filter']);
            if (mb_strlen($filter) > 128) {
                return ToolValidation::invalid("get_med_history 'drug_filter' is too long.");
            }
            $sanitized['drug_filter'] = $filter;
        }

        return ToolValidation::ok($sanitized);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function validateVitalsTrend(array $args): ToolValidation
    {
        $metric = $this->requireEnum($args, 'metric', self::METRIC_ENUM);
        if ($metric === null) {
            return ToolValidation::invalid("get_vitals_trend requires 'metric' to be one of: " . implode(', ', self::METRIC_ENUM) . '.');
        }
        $window = $this->requireInt($args, 'window_months', self::WINDOW_MIN, self::WINDOW_MAX_DEFAULT);
        if ($window === null) {
            return ToolValidation::invalid("get_vitals_trend requires integer 'window_months' >= 1.");
        }
        return ToolValidation::ok(['metric' => $metric, 'window_months' => $window]);
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string>         $enum
     */
    private function requireEnum(array $args, string $key, array $enum): ?string
    {
        if (!array_key_exists($key, $args) || !is_string($args[$key])) {
            return null;
        }
        $value = $args[$key];
        return in_array($value, $enum, true) ? $value : null;
    }

    /**
     * Accept an int or an integer-valued numeric string (function-calling providers often emit
     * numbers as strings); reject floats, out-of-range, and non-numerics.
     *
     * @param array<string, mixed> $args
     */
    private function requireInt(array $args, string $key, int $min, int $max): ?int
    {
        if (!array_key_exists($key, $args)) {
            return null;
        }
        $raw = $args[$key];
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            $value = (int) trim($raw);
        } else {
            return null;
        }
        if ($value < $min || $value > $max) {
            return null;
        }
        return $value;
    }

    /**
     * Native function-calling declarations for the LlmRequest (one per tool). Deterministic
     * order so the request bytes are stable. NONE declares a patient parameter.
     *
     * @return list<array<string, mixed>>
     */
    public function declarations(): array
    {
        $intWindow = static fn(int $min, int $max): array => [
            'type' => 'integer',
            'minimum' => $min,
            'maximum' => $max,
        ];

        return [
            [
                'name' => ToolName::GetControlTrend->value,
                'description' => 'Return the pinned patient\'s glycemic/lipid control facts for one analyte over a window.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'analyte' => ['type' => 'string', 'enum' => self::ANALYTE_ENUM],
                        'window_months' => $intWindow(self::WINDOW_MIN, self::CONTROL_WINDOW_MAX),
                    ],
                    'required' => ['analyte', 'window_months'],
                ],
            ],
            [
                'name' => ToolName::GetMedHistory->value,
                'description' => 'Return the pinned patient\'s medication events (own + reconciled) and paired labs over a window.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'drug_filter' => ['type' => 'string'],
                        'window_months' => $intWindow(self::WINDOW_MIN, self::WINDOW_MAX_DEFAULT),
                    ],
                    'required' => ['window_months'],
                ],
            ],
            [
                'name' => ToolName::GetVitalsTrend->value,
                'description' => 'Return the pinned patient\'s vitals trend for one metric over a window.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'metric' => ['type' => 'string', 'enum' => self::METRIC_ENUM],
                        'window_months' => $intWindow(self::WINDOW_MIN, self::WINDOW_MAX_DEFAULT),
                    ],
                    'required' => ['metric', 'window_months'],
                ],
            ],
            [
                'name' => ToolName::GetOverdue->value,
                'description' => 'Return the pinned patient\'s overdue monitoring items with reorder-suppression notes.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name' => ToolName::GetPending->value,
                'description' => 'Return the pinned patient\'s active unresulted orders and preliminary results.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
        ];
    }
}
