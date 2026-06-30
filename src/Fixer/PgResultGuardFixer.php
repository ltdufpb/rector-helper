<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * PHP 8+ tipa estritamente as funcoes pg_*: se db_query() retorna false,
 * @pg_numrows(false) deixa de ser warning suprimido e vira TypeError.
 *
 * O rejuvenescimento minimo preserva a semantica legada mais comum no
 * e-cidade: resultado invalido significa zero linhas/colunas.
 */
final class PgResultGuardFixer extends AbstractAstFixer
{
    /** @var array<string, true> */
    public const TARGET_FUNCTIONS = [
        'pg_numrows' => true,
        'pg_num_rows' => true,
        'pg_numfields' => true,
        'pg_num_fields' => true,
    ];

    protected function shouldParse(string $source): bool
    {
        return stripos($source, 'pg_num') !== false;
    }

    protected function mutate(array &$newAst): int
    {
        $fixed = 0;

        $visitor = new class($fixed) extends NodeVisitorAbstract {
            private int $fixed;

            public function __construct(int &$fixed)
            {
                $this->fixed = &$fixed;
            }

            public function leaveNode(Node $node)
            {
                if (!$node instanceof Assign) {
                    return null;
                }

                $call = $this->unwrapPgNumCall($node->expr);
                if ($call === null || count($call->args) !== 1 || !$call->args[0] instanceof Arg) {
                    return null;
                }
                if (!$call->args[0]->value instanceof Variable || !is_string($call->args[0]->value->name)) {
                    return null;
                }

                $argName = $call->args[0]->value->name;
                $node->expr = new Ternary(
                    new BooleanOr(
                        new Identical(new Variable($argName), new ConstFetch(new Name('false'))),
                        new Identical(new Variable($argName), new ConstFetch(new Name('null')))
                    ),
                    new Node\Scalar\LNumber(0),
                    $node->expr
                );

                $this->fixed++;
                return $node;
            }

            private function unwrapPgNumCall(Expr $expr): ?FuncCall
            {
                if ($expr instanceof Ternary) {
                    return null;
                }

                if ($expr instanceof ErrorSuppress) {
                    $expr = $expr->expr;
                }

                if (!$expr instanceof FuncCall || !$expr->name instanceof Name) {
                    return null;
                }

                $name = strtolower($expr->name->toString());
                return isset(PgResultGuardFixer::TARGET_FUNCTIONS[$name]) ? $expr : null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($newAst);

        return $fixed;
    }
}
