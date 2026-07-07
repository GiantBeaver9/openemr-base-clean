<?php

/**
 * ForbiddenWriteRule — read-only enforcement, layer 1 (ARCHITECTURE.md §4).
 *
 * The Clinical Co-Pilot module is READ-ONLY to every core OpenEMR table (T6/I10): it may write
 * ONLY to its own `mod_copilot_*` tables, and ONLY through its dedicated persistence classes. This
 * module-scoped PHPStan rule (mirroring the host's `tests/PHPStan/Rules/*` pattern) makes that a
 * static-analysis failure rather than a review convention: it flags every write API —
 *
 *   - `QueryUtils::sqlInsert()` (always a write) and the global `sqlInsert()`;
 *   - `QueryUtils::sqlStatement*()` / `sqlStatementThrowException()` (and the global forms) whose
 *     first SQL literal begins with INSERT / UPDATE / DELETE / REPLACE;
 *   - host-service `insert()` / `update()` / `delete()` method calls;
 *
 * — when the call originates OUTSIDE the whitelisted persistence classes. Those classes are the only
 * ones permitted to persist, and each writes exclusively to a `mod_copilot_*` table. Everything else
 * in the module must be a pure reader.
 *
 * This is layer 1 of the three read-only layers; layer 2 is the SELECT-only MySQL user at deploy
 * time (see this directory's README), layer 3 is LLM egress redaction (Reduce\EgressRedactor).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<CallLike>
 */
final class ForbiddenWriteRule implements Rule
{
    /**
     * The persistence classes permitted to write — each targets a `mod_copilot_*` table only.
     * Matched by fully-qualified class name. CadenceConfigStore is included because it owns the one
     * narrow module-config write (`breaker:manual_state`) delegated to it by CircuitBreakerStore.
     *
     * @var list<class-string|string>
     */
    private const WHITELIST_CLASSES = [
        'OpenEMR\Modules\ClinicalCopilot\DocStore',
        'OpenEMR\Modules\ClinicalCopilot\Doc\DbDocGateway',
        'OpenEMR\Modules\ClinicalCopilot\Observability\DbTraceWriter',
        'OpenEMR\Modules\ClinicalCopilot\Observability\CircuitBreakerStore',
        'OpenEMR\Modules\ClinicalCopilot\Observability\CadenceConfigStore',
        'OpenEMR\Modules\ClinicalCopilot\Chat\DbSessionGateway',
    ];

    /** Whitelisted file-path fragments (the deterministic-seed fixtures legitimately insert rows). */
    private const WHITELIST_PATH_FRAGMENTS = [
        '/tests/Seed/',
    ];

    /** Only module files are in scope: the rule is a no-op elsewhere, so it is safe to run broadly. */
    private const MODULE_PATH_FRAGMENT = 'oe-module-clinical-copilot/';

    /** SQL-statement runner methods that may carry a write verb (checked against the SQL literal). */
    private const STATEMENT_METHODS = [
        'sqlStatement',
        'sqlStatementThrowException',
        'sqlStatementNoLog',
    ];

    /** Methods that are always a write regardless of arguments. */
    private const ALWAYS_WRITE_METHODS = [
        'sqlInsert',
    ];

    /** Host-service persistence method names. */
    private const SERVICE_WRITE_METHODS = [
        'insert',
        'update',
        'delete',
    ];

    /** SQL leading keywords that mean "this mutates rows". */
    private const WRITE_VERBS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param CallLike $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_contains($scope->getFile(), self::MODULE_PATH_FRAGMENT)) {
            return [];
        }
        if ($this->isWhitelisted($scope)) {
            return [];
        }

        $violation = match (true) {
            $node instanceof StaticCall => $this->checkStaticCall($node),
            $node instanceof FuncCall => $this->checkFuncCall($node),
            $node instanceof MethodCall => $this->checkMethodCall($node),
            default => null,
        };

        if ($violation === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Write API %s is forbidden here: the Clinical Co-Pilot module is read-only to core '
                . 'tables (T6/I10). Writes may go only to mod_copilot_* tables through the module\'s '
                . 'persistence classes.',
                $violation,
            ))
                ->identifier('clinicalCopilot.forbiddenWrite')
                ->tip('Move the write into a whitelisted mod_copilot_* repository, or make this a read.')
                ->build(),
        ];
    }

    private function checkStaticCall(StaticCall $node): ?string
    {
        if (!($node->name instanceof Identifier)) {
            return null;
        }
        $method = $node->name->name;

        if (in_array($method, self::ALWAYS_WRITE_METHODS, true)) {
            return $this->qualify($node->class, $method);
        }
        if (in_array($method, self::STATEMENT_METHODS, true) && $this->firstArgIsWriteSql($node)) {
            return $this->qualify($node->class, $method);
        }
        return null;
    }

    private function checkFuncCall(FuncCall $node): ?string
    {
        if (!($node->name instanceof Name)) {
            return null;
        }
        $function = $node->name->toString();

        if (in_array($function, self::ALWAYS_WRITE_METHODS, true)) {
            return $function . '()';
        }
        if (in_array($function, self::STATEMENT_METHODS, true) && $this->firstArgIsWriteSql($node)) {
            return $function . '()';
        }
        return null;
    }

    private function checkMethodCall(MethodCall $node): ?string
    {
        if (!($node->name instanceof Identifier)) {
            return null;
        }
        $method = $node->name->name;

        if (in_array($method, self::SERVICE_WRITE_METHODS, true)) {
            return '->' . $method . '()';
        }
        return null;
    }

    /**
     * True when the call's first argument is a string literal whose first SQL keyword mutates rows.
     */
    private function firstArgIsWriteSql(StaticCall|FuncCall|MethodCall $node): bool
    {
        $args = $node->getArgs();
        if ($args === []) {
            return false;
        }
        $first = $args[0]->value;
        if (!($first instanceof String_)) {
            // Non-literal SQL cannot be statically classified; the module always uses literals, so a
            // dynamic statement is left to review rather than risking a false positive here.
            return false;
        }
        $sql = ltrim($first->value);
        foreach (self::WRITE_VERBS as $verb) {
            if (stripos($sql, $verb) === 0) {
                return true;
            }
        }
        return false;
    }

    private function qualify(Node $class, string $method): string
    {
        $className = $class instanceof Name ? $class->toString() : 'class';
        return $className . '::' . $method . '()';
    }

    private function isWhitelisted(Scope $scope): bool
    {
        $file = $scope->getFile();
        foreach (self::WHITELIST_PATH_FRAGMENTS as $fragment) {
            if (str_contains($file, $fragment)) {
                return true;
            }
        }

        $class = $scope->getClassReflection();
        if ($class === null) {
            return false;
        }
        return in_array($class->getName(), self::WHITELIST_CLASSES, true);
    }
}
