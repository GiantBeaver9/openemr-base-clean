<?php

/**
 * SeedRunner — idempotent loader for the Clinical Co-Pilot synthetic patients.
 *
 * Inserts the 3-table lab join, meds, vitals, and cadence config for the four
 * synthetic type-2-diabetes patients (pids 9001-9004) defined in
 * tests/Fixtures/*.json — the SAME rows the isolated contract tests consume, so
 * a dev-stack DB and an isolated FixtureReader see identical data.
 *
 * Idempotent: each patient carries a stable marker (patient_data.pubpid =
 * "CCPILOT-<pid>"); a patient already present is skipped, and every row is
 * additionally guarded by a primary-key existence check, so re-running never
 * duplicates. The module is otherwise READ-ONLY to core tables (T6); this
 * seeder is the one deliberate exception, used only to stand up synthetic
 * demo/test data (OPEN-1: synthetic patients only, never real PHI).
 *
 * This script requires the OpenEMR framework + a database and therefore does
 * NOT run in the isolated CI harness; it is executed inside the dev stack, e.g.
 *   php interface/modules/custom_modules/oe-module-clinical-copilot/tests/Seed/SeedRunner.php
 * from the site context, or via SeedRunner::run() from a bootstrapped page.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot module
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Seed;

use OpenEMR\Common\Database\QueryUtils;

final class SeedRunner
{
    /** Synthetic patient ids owned by this seed (stable across runs). */
    private const PIDS = [9001, 9002, 9003, 9004];

    /** Absolute path to the fixtures directory. */
    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    /**
     * Table load plan: fixture file => [table name, primary-key column].
     * Order matters — parents before children for the FK-shaped lab join.
     *
     * @var array<string, array{string, string}>
     */
    private const PLAN = [
        'patient_data.json'       => ['patient_data', 'pid'],
        'procedure_order.json'    => ['procedure_order', 'procedure_order_id'],
        'procedure_report.json'   => ['procedure_report', 'procedure_report_id'],
        'procedure_result.json'   => ['procedure_result', 'procedure_result_id'],
        'prescriptions.json'      => ['prescriptions', 'id'],
        'lists.json'              => ['lists', 'id'],
        'form_vitals.json'        => ['form_vitals', 'id'],
        'mod_copilot_cadence.json' => ['mod_copilot_cadence', 'id'],
    ];

    /**
     * Seed all fixtures. Returns a per-table count of rows inserted this run
     * (0 for a table whose rows were all already present).
     *
     * @return array<string, int>
     */
    public static function run(): array
    {
        if (self::alreadySeeded()) {
            return array_fill_keys(array_map(static fn(array $p): string => $p[0], self::PLAN), 0);
        }

        $inserted = [];
        foreach (self::PLAN as $file => [$table, $pkColumn]) {
            $inserted[$table] = self::loadTable($file, $table, $pkColumn);
        }

        return $inserted;
    }

    /**
     * Fast global guard: true only when every synthetic patient marker is
     * already present, so a fully-seeded DB short-circuits without touching
     * child tables.
     */
    private static function alreadySeeded(): bool
    {
        foreach (self::PIDS as $pid) {
            $exists = QueryUtils::fetchSingleValue(
                'SELECT COUNT(*) AS c FROM patient_data WHERE pubpid = ?',
                'c',
                ['CCPILOT-' . $pid],
            );
            if ((int) $exists === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Insert every row of one fixture file, skipping rows whose primary key
     * already exists. Returns the number of rows actually inserted.
     */
    private static function loadTable(string $file, string $table, string $pkColumn): int
    {
        $rows = self::readFixture($file);
        $count = 0;

        foreach ($rows as $row) {
            $row = self::stripDocKeys($row);
            $pk = $row[$pkColumn] ?? null;
            if ($pk !== null && self::rowExists($table, $pkColumn, $pk)) {
                continue;
            }
            self::insertRow($table, $row);
            $count++;
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readFixture(string $file): array
    {
        $path = self::FIXTURES_DIR . '/' . $file;
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Unable to read fixture file: ' . $file);
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture is not a JSON array: ' . $file);
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * Drop documentation-only keys (any key starting with "_"): real host rows
     * never carry them.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function stripDocKeys(array $row): array
    {
        return array_filter(
            $row,
            static fn(string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function rowExists(string $table, string $pkColumn, mixed $pk): bool
    {
        $safeTable = QueryUtils::escapeTableName($table);
        $safeColumn = QueryUtils::escapeColumnName($pkColumn);
        $exists = QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS c FROM `$safeTable` WHERE `$safeColumn` = ?",
            'c',
            [$pk],
        );

        return (int) $exists > 0;
    }

    /**
     * Parameterized INSERT. Column names come only from trusted fixture files
     * (never user input) and are escaped defensively; every value is bound.
     *
     * @param array<string, mixed> $row
     */
    private static function insertRow(string $table, array $row): void
    {
        if ($row === []) {
            return;
        }

        $safeTable = QueryUtils::escapeTableName($table);
        $columns = [];
        $placeholders = [];
        $binds = [];
        foreach ($row as $column => $value) {
            $columns[] = '`' . QueryUtils::escapeColumnName((string) $column) . '`';
            $placeholders[] = '?';
            $binds[] = $value;
        }

        $sql = "INSERT INTO `$safeTable` (" . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';

        QueryUtils::sqlInsert($sql, $binds);
    }
}

// Allow direct CLI execution inside the dev stack (globals.php must be bootstrapped
// by the caller / site entry point). No-op when included for its class only.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    if (class_exists(QueryUtils::class)) {
        $result = SeedRunner::run();
        foreach ($result as $table => $n) {
            fwrite(STDOUT, sprintf("%-24s %d inserted\n", $table, $n));
        }
    } else {
        fwrite(STDERR, "OpenEMR framework not bootstrapped; run from a site context.\n");
        exit(1);
    }
}
