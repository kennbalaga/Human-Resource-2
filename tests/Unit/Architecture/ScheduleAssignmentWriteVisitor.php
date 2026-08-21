<?php

namespace Tests\Unit\Architecture;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Heuristic, not full type inference: walks the AST of one file tracking, per
 * function/method/closure scope, which local variables were either typed as
 * ScheduleAssignment (parameter hints) or assigned from a call chain rooted in
 * `ScheduleAssignment::...`. Any write-verb call (`create`, `update`, `delete`,
 * `save`, ...) on a variable so tracked — or chained through a relation method
 * literally named `assignments`/`scheduleAssignments` — is flagged.
 *
 * This deliberately does not attempt general type inference: it's scoped to
 * the exact call shapes this codebase's ScheduleAssignment write sites
 * actually use (verified against all of them — see ScheduleAssignmentWriteBoundaryTest).
 * It cannot and does not catch raw `DB::table('schedule_assignments')` SQL,
 * which bypasses the Eloquent model entirely — that gap is a Layer 4 (database
 * privilege separation) concern, out of scope here.
 */
class ScheduleAssignmentWriteVisitor extends NodeVisitorAbstract
{
    private const WRITE_METHODS = [
        'save', 'update', 'delete', 'forceDelete', 'increment', 'decrement',
        'create', 'upsert', 'forceCreate', 'updateOrCreate', 'firstOrCreate',
    ];

    private const STATIC_WRITE_METHODS = ['create', 'destroy', 'upsert', 'forceCreate', 'updateOrCreate'];

    private const RELATION_METHODS = ['assignments', 'scheduleAssignments'];

    /** @var array<int, array<string, bool>> */
    private array $scopeStack = [[]];

    /** @var array<int, int> */
    public array $flaggedLines = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            $this->scopeStack[] = $this->scopeFromParams($node->params);

            return null;
        }

        if ($node instanceof Node\Expr\Closure) {
            $scope = [];
            foreach ($node->uses as $use) {
                if ($use->var instanceof Node\Expr\Variable && is_string($use->var->name)
                    && ($this->currentScope()[$use->var->name] ?? false)) {
                    $scope[$use->var->name] = true;
                }
            }
            $this->scopeStack[] = array_merge($scope, $this->scopeFromParams($node->params));

            return null;
        }

        if ($node instanceof Node\Expr\ArrowFunction) {
            // Arrow functions auto-capture the enclosing scope by value.
            $this->scopeStack[] = array_merge($this->currentScope(), $this->scopeFromParams($node->params));

            return null;
        }

        if ($node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
            && $this->resolvesToScheduleAssignment($node->expr)) {
            $this->scopeStack[count($this->scopeStack) - 1][$node->var->name] = true;
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
            if ($methodName !== null
                && in_array($methodName, self::WRITE_METHODS, true)
                && $this->resolvesToScheduleAssignment($node->var)) {
                $this->flaggedLines[] = $node->getLine();
            }
        }

        if ($node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->class->getLast() === 'ScheduleAssignment') {
            $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
            if ($methodName !== null && in_array($methodName, self::STATIC_WRITE_METHODS, true)) {
                $this->flaggedLines[] = $node->getLine();
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction) {
            array_pop($this->scopeStack);
        }

        return null;
    }

    private function resolvesToScheduleAssignment(Node $expr): bool
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $this->currentScope()[$expr->name] ?? false;
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name) {
            return $expr->class->getLast() === 'ScheduleAssignment';
        }

        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            $methodName = $expr->name instanceof Node\Identifier ? $expr->name->toString() : null;
            if ($methodName !== null && in_array($methodName, self::RELATION_METHODS, true)) {
                return true;
            }

            return $this->resolvesToScheduleAssignment($expr->var);
        }

        return false;
    }

    /**
     * @param  array<int, Node\Param>  $params
     * @return array<string, bool>
     */
    private function scopeFromParams(array $params): array
    {
        $scope = [];
        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable
                && is_string($param->var->name)
                && $param->type instanceof Node\Name
                && $param->type->getLast() === 'ScheduleAssignment') {
                $scope[$param->var->name] = true;
            }
        }

        return $scope;
    }

    /** @return array<string, bool> */
    private function currentScope(): array
    {
        return $this->scopeStack[count($this->scopeStack) - 1];
    }
}
