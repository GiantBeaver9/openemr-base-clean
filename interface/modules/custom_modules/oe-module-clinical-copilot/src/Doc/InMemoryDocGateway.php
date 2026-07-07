<?php

/**
 * InMemoryDocGateway — a database-free DocGateway for isolated tests.
 *
 * Holds rows in a PHP array with an auto-incrementing id and enforces the same
 * (pid, fact_digest) unique key as the real table, so the append-only content-address
 * behaviour and the row mapping can be exercised without a stack. Insert-only + read: it
 * never mutates a stored row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Doc;

final class InMemoryDocGateway implements DocGateway
{
    /** @var list<array<string, mixed>> stored rows, each including its assigned id */
    private array $rows = [];

    private int $nextId = 1;

    public function insert(array $row): int
    {
        $existing = $this->findByPidAndDigest((int) $row['pid'], (string) $row['fact_digest']);
        if ($existing !== null) {
            // Content address already present: serve the original row (T7), do not re-store.
            return (int) $existing['id'];
        }

        $id = $this->nextId++;
        $row['id'] = $id;
        $this->rows[] = $row;

        return $id;
    }

    public function findByPidAndDigest(int $pid, string $digest): ?array
    {
        foreach ($this->rows as $row) {
            if ((int) $row['pid'] === $pid && (string) $row['fact_digest'] === $digest) {
                return $row;
            }
        }

        return null;
    }

    public function historyByPid(int $pid): array
    {
        $matches = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => (int) $row['pid'] === $pid,
        ));

        // ORDER BY computed_at, with id as a stable tie-break for equal timestamps.
        usort($matches, static function (array $a, array $b): int {
            return [(string) $a['computed_at'], (int) $a['id']]
                <=> [(string) $b['computed_at'], (int) $b['id']];
        });

        return $matches;
    }
}
