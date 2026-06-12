<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Regressao override-attribute-on-property (catalogo): a regra
 * AddOverrideAttributeToOverriddenPropertiesRector aplica #[\Override] em
 * propriedades, mas o atributo (PHP 8.3) so aceita metodos — fatal ao
 * carregar a classe. Remove o atributo apenas de Property, preservando os
 * validos em ClassMethod.
 *
 * Versao em classe do script scripts/fix-rector-override-on-properties.php
 * (45 arquivos corrigidos no e-cidade), portada para a API do php-parser v5.
 */
final class OverrideOnPropertyFixer extends AbstractAstFixer
{
    protected function shouldParse(string $source): bool
    {
        return stripos($source, 'Override') !== false;
    }

    protected function mutate(array $newAst): int
    {
        $removed = 0;

        $visitor = new class($removed) extends NodeVisitorAbstract {
            private int $removed;

            public function __construct(int &$removed)
            {
                $this->removed = &$removed;
            }

            public function enterNode(Node $node)
            {
                if (!$node instanceof Property) {
                    return null;
                }
                foreach ($node->attrGroups as $gi => $group) {
                    foreach ($group->attrs as $ai => $attr) {
                        if (strcasecmp(ltrim($attr->name->toString(), '\\'), 'Override') !== 0) {
                            continue;
                        }
                        unset($group->attrs[$ai]);
                        $this->removed++;
                    }
                    $group->attrs = array_values($group->attrs);
                    if ($group->attrs === []) {
                        unset($node->attrGroups[$gi]);
                    }
                }
                $node->attrGroups = array_values($node->attrGroups);
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($newAst);

        return $removed;
    }
}
