<?php

/**
 * Readiness snapshot for the external medical-knowledge Postgres.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

/**
 * Answers "is the separate knowledge store configured, reachable, and populated?"
 * for the readiness endpoint and the observability dashboard — the same shape as
 * the LLM/worker readiness rows. Distinguishes the three states an operator
 * actually cares about:
 *
 *   - not_configured : no env set ⇒ the module is running on the offline corpus
 *                      (a normal, healthy state, not an error).
 *   - unreachable    : configured but the connection/probe query failed.
 *   - ok             : reachable; `chunk_count` reports how much knowledge is loaded.
 */
final class KnowledgeBaseStatus
{
    public function __construct(
        private readonly KnowledgeBaseConfig $config,
        private readonly KnowledgeQueryRunner $runner,
    ) {
    }

    public static function createDefault(): self
    {
        $config = KnowledgeBaseConfig::fromEnv();

        return new self($config, new KnowledgeBaseConnection($config));
    }

    /**
     * @return array{state: 'not_configured'|'unreachable'|'ok', configured: bool, chunk_count: int|null}
     */
    public function snapshot(): array
    {
        if (!$this->config->isConfigured() || !$this->runner->isAvailable()) {
            return ['state' => 'not_configured', 'configured' => $this->config->isConfigured(), 'chunk_count' => null];
        }

        $table = $this->config->table;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table) !== 1) {
            return ['state' => 'unreachable', 'configured' => true, 'chunk_count' => null];
        }

        $rows = $this->runner->select("SELECT count(*) AS n FROM {$table}");
        if ($rows === []) {
            return ['state' => 'unreachable', 'configured' => true, 'chunk_count' => null];
        }

        $count = is_numeric($rows[0]['n'] ?? null) ? (int)$rows[0]['n'] : 0;

        return ['state' => 'ok', 'configured' => true, 'chunk_count' => $count];
    }
}
