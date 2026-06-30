<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Regressao parse-str-sem-segundo-arg (catalogo): parse_str() com um unico
 * argumento foi removida no PHP 8.0 (ArgumentCountError em runtime). O modo
 * de um argumento importava as variaveis no escopo local; o equivalente
 * moderno escreve num array de saida e re-injeta com extract().
 *
 * Transforma, de forma cirurgica (preservando o texto do 1o argumento):
 *
 *     parse_str($s);
 *
 * em:
 *
 *     parse_str($s, $_parseStr);
 *     extract($_parseStr, EXTR_SKIP);
 *
 * Reproduz o conserto ad-hoc (regex Perl) aplicado em ~479 arquivos do
 * e-cidade pelo passe anterior — que ficou INCOMPLETO (so casava parse_str de
 * $var simples, e so em alguns modulos), deixando 83 arquivos de Educacao
 * quebrados. Aqui vira regra reproduzivel e AST-based: alcanca tambem casos
 * como parse_str(base64_decode($x)) que o regex nao pegava.
 */
final class ParseStrFixer extends AbstractAstFixer
{
    /** Nome do array de saida; mesmo usado pelo passe anterior, p/ uniformidade. */
    private const RESULT_VAR = '_parseStr';

    protected function shouldParse(string $source): bool
    {
        return stripos($source, 'parse_str') !== false;
    }

    protected function mutate(array &$newAst): int
    {
        $fixed = 0;

        $visitor = new class($fixed, self::RESULT_VAR) extends NodeVisitorAbstract {
            private int $fixed;
            private string $resultVar;

            public function __construct(int &$fixed, string $resultVar)
            {
                $this->fixed = &$fixed;
                $this->resultVar = $resultVar;
            }

            public function leaveNode(Node $node)
            {
                // So tratamos parse_str em posicao de statement (onde o modo de
                // 1 argumento tinha efeito de importar variaveis no escopo).
                // Usos como expressao (if (parse_str(...))) sao ignorados.
                if (!$node instanceof Expression || !$node->expr instanceof FuncCall) {
                    return null;
                }
                $call = $node->expr;
                if (!$call->name instanceof Name || strtolower($call->name->toString()) !== 'parse_str') {
                    return null;
                }
                if (count($call->args) !== 1 || !$call->args[0] instanceof Arg) {
                    return null;
                }
                if ($call->args[0]->unpack) {
                    return null;
                }

                // Acrescenta o 2o argumento sem tocar no 1o (diff cirurgico).
                $call->args[] = new Arg(new Variable($this->resultVar));

                $extract = new Expression(new FuncCall(new Name('extract'), [
                    new Arg(new Variable($this->resultVar)),
                    new Arg(new ConstFetch(new Name('EXTR_SKIP'))),
                ]));

                $this->fixed++;
                return [$node, $extract];
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        // Reatribui: a insercao de statements no array de topo so existe no
        // retorno de traverse() (o array raiz nao e mutado in-place).
        $newAst = $traverser->traverse($newAst);

        return $fixed;
    }
}
