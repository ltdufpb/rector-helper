<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Regressao php4-constructor (catalogo): metodo com o mesmo nome da classe
 * deixou de ser reconhecido como construtor no PHP 8.0. Renomeia para
 * __construct quando a classe nao define um.
 *
 * Versao em classe do script scripts/fix-php4-constructor.php (2.776
 * arquivos corrigidos no e-cidade), portada para a API do php-parser v5.
 */
final class Php4ConstructorFixer extends AbstractAstFixer
{
    protected function shouldParse(string $source): bool
    {
        return stripos($source, 'class ') !== false;
    }

    protected function mutate(array &$newAst): int
    {
        $renamed = 0;

        $visitor = new class($renamed) extends NodeVisitorAbstract {
            private int $renamed;

            public function __construct(int &$renamed)
            {
                $this->renamed = &$renamed;
            }

            public function enterNode(Node $node)
            {
                if (!$node instanceof Class_ || $node->name === null) {
                    return null;
                }
                $classNameLower = strtolower($node->name->toString());

                $hasConstruct = false;
                $php4Constructor = null;
                foreach ($node->stmts as $stmt) {
                    if (!$stmt instanceof ClassMethod) {
                        continue;
                    }
                    $methodNameLower = strtolower($stmt->name->toString());
                    if ($methodNameLower === '__construct') {
                        $hasConstruct = true;
                    }
                    if ($methodNameLower === $classNameLower) {
                        $php4Constructor = $stmt;
                    }
                }

                if ($php4Constructor !== null && !$hasConstruct) {
                    $php4Constructor->name = new Identifier('__construct');
                    $this->renamed++;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($newAst);

        return $renamed;
    }
}
