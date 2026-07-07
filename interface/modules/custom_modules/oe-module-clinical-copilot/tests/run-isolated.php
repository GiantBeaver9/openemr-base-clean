<?php

/**
 * Standalone isolated test runner for the Clinical Co-Pilot deterministic core.
 *
 * These tests exercise pure-logic units (fact model, canonical serializer, digest,
 * value parsing, unit conversion, supersession, capabilities' derived facts) with NO
 * OpenEMR framework and NO database — so they run under a bare `php` binary in any
 * environment, including CI web sessions where the Docker dev stack is unavailable.
 *
 * In the full dev stack these same assertions are also covered by the PHPUnit isolated
 * suite (openemr-cmd phpunit-isolated). This runner is the low-dependency companion so
 * the deterministic spine always has an executable green signal.
 *
 * Usage:  php tests/run-isolated.php
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests;

// Minimal PSR-4 autoloader scoped to this module's src/ — no Composer required.
spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenEMR\\Modules\\ClinicalCopilot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

final class Assert
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var list<string> */
    public static array $failures = [];

    public static function that(bool $cond, string $message): void
    {
        if ($cond) {
            self::$passed++;
            return;
        }
        self::$failed++;
        self::$failures[] = $message;
    }

    public static function equals(mixed $expected, mixed $actual, string $message): void
    {
        self::that(
            $expected === $actual,
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
        );
    }

    public static function throws(callable $fn, string $message): void
    {
        try {
            $fn();
            self::that(false, $message . ' (no exception thrown)');
        } catch (\Throwable) {
            self::that(true, $message);
        }
    }

    public static function summary(): int
    {
        echo "\n";
        foreach (self::$failures as $f) {
            echo "  FAIL: {$f}\n";
        }
        $total = self::$passed + self::$failed;
        echo sprintf("\n%d/%d assertions passed.\n", self::$passed, $total);
        return self::$failed === 0 ? 0 : 1;
    }
}

$suiteDir = __DIR__ . '/Unit';
$suites = is_dir($suiteDir) ? glob($suiteDir . '/*.php') : [];
sort($suites);
foreach ($suites as $suite) {
    require $suite;
    $fn = 'clinical_copilot_test_' . basename($suite, '.php');
    if (function_exists($fn)) {
        echo "• " . basename($suite, '.php') . "\n";
        $fn();
    }
}

exit(Assert::summary());
