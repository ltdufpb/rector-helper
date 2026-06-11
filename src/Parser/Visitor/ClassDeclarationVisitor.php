<?php

declare(strict_types=1);

namespace EduDeps\Parser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

/**
 * Coleta declaracoes de classe/interface/trait num arquivo, com namespace
 * contextual quando presente. Usado pelo ClassMapBuilder para indexar o
 * codigo do e-cidade antes do BFS.
 */
final class ClassDeclarationVisitor extends NodeVisitorAbstract
{
    /** @var list<array{name:string,fqcn:string,kind:string}> */
    private array $declarations = [];

    private ?string $currentNamespace = null;

    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = $node->name !== null ? $node->name->toString() : null;
            return null;
        }

        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_) {
            if ($node->name === null) {
                return null;
            }
            $shortName = $node->name->toString();
            $fqcn = $this->currentNamespace !== null
                ? $this->currentNamespace . '\\' . $shortName
                : $shortName;
            $this->declarations[] = [
                'name' => $shortName,
                'fqcn' => $fqcn,
                'kind' => $this->kindOf($node),
            ];
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = null;
        }
        return null;
    }

    /**
     * @return list<array{name:string,fqcn:string,kind:string}>
     */
    public function getDeclarations(): array
    {
        return $this->declarations;
    }

    private function kindOf(Node $node): string
    {
        if ($node instanceof Class_) {
            return 'class';
        }
        if ($node instanceof Interface_) {
            return 'interface';
        }
        return 'trait';
    }
}
