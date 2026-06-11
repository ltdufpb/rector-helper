<?php

declare(strict_types=1);

namespace EduDeps\Parser\Visitor;

use EduDeps\Parser\PhpBuiltinClasses;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor central de extracao de dependencias.
 *
 * Padroes reconhecidos:
 *  - include/require/include_once/require_once com literal string
 *  - include/require* com modification("...") literal
 *  - new ClassName(...) — gera referencia de classe a resolver via classmap
 *  - ClassName::metodoEstatico(...) — idem
 *  - use Foo\Bar — namespace moderno via classmap PSR-4
 *
 * Casos nao-resolviveis (concatenacao dinamica, variaveis) viram unresolved
 * com motivo e snippet textual para diagnostico.
 */
final class DependencyVisitor extends NodeVisitorAbstract
{
    /** @var list<array{type:string,line:int,target:string}> */
    private array $dependencies = [];

    /** @var list<array{type:string,line:int,name:string}> */
    private array $classReferences = [];

    /** @var list<array{line:int,fqcn:string}> */
    private array $useStatements = [];

    /** @var list<array{line:int,reason:string,snippet:string}> */
    private array $unresolved = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Include_) {
            $this->handleInclude($node);
            return null;
        }
        if ($node instanceof New_) {
            $this->handleNew($node);
            return null;
        }
        if ($node instanceof StaticCall) {
            $this->handleStaticCall($node);
            return null;
        }
        if ($node instanceof UseUse) {
            $fqcn = $node->name->toString();
            // "use Exception" / "use DateTime" no escopo global: classe nativa
            // do PHP, nao corresponde a arquivo do projeto. So filtra nomes
            // sem namespace — "use App\Foo" continua indo para o classmap.
            if (strpos($fqcn, '\\') === false && $this->isStdlibClass($fqcn)) {
                return null;
            }
            $this->useStatements[] = [
                'line' => $node->getStartLine(),
                'fqcn' => $fqcn,
            ];
            return null;
        }
        return null;
    }

    private function handleInclude(Include_ $node): void
    {
        $expr = $node->expr;
        $line = $node->getStartLine();
        $kind = $this->includeTypeName($node);

        $literal = $this->extractLiteralString($expr);
        if ($literal !== null) {
            $this->dependencies[] = ['type' => $kind, 'line' => $line, 'target' => $literal];
            return;
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name && strtolower($expr->name->toString()) === 'modification') {
            if (count($expr->args) === 0) {
                $this->unresolved[] = ['line' => $line, 'reason' => 'modification_no_args', 'snippet' => 'modification()'];
                return;
            }
            $modLiteral = $this->extractLiteralString($expr->args[0]->value);
            if ($modLiteral !== null) {
                $this->dependencies[] = [
                    'type' => $kind . '+modification',
                    'line' => $line,
                    'target' => $modLiteral,
                ];
                return;
            }
            $this->unresolved[] = ['line' => $line, 'reason' => 'modification_non_literal', 'snippet' => $this->snippetFor($expr)];
            return;
        }

        $this->unresolved[] = ['line' => $line, 'reason' => 'include_non_literal', 'snippet' => $this->snippetFor($expr)];
    }

    private function handleNew(New_ $node): void
    {
        if (!$node->class instanceof Name) {
            $this->unresolved[] = ['line' => $node->getStartLine(), 'reason' => 'new_dynamic_class', 'snippet' => 'new $...'];
            return;
        }
        $name = $node->class->toString();
        if ($this->isStdlibClass($name)) {
            return;
        }
        $this->classReferences[] = ['type' => 'new', 'line' => $node->getStartLine(), 'name' => $name];
    }

    private function handleStaticCall(StaticCall $node): void
    {
        if (!$node->class instanceof Name) {
            return;
        }
        $name = $node->class->toString();
        if ($this->isStdlibClass($name)) {
            return;
        }
        $this->classReferences[] = ['type' => 'static_call', 'line' => $node->getStartLine(), 'name' => $name];
    }

    private function isStdlibClass(string $name): bool
    {
        // Keywords de resolucao relativa nao sao classes nem dependencias.
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return true;
        }
        return PhpBuiltinClasses::isBuiltin($name);
    }

    private function extractLiteralString(Node $node): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }
        return null;
    }

    private function includeTypeName(Include_ $node): string
    {
        switch ($node->type) {
            case Include_::TYPE_INCLUDE:
                return 'include';
            case Include_::TYPE_INCLUDE_ONCE:
                return 'include_once';
            case Include_::TYPE_REQUIRE:
                return 'require';
            case Include_::TYPE_REQUIRE_ONCE:
                return 'require_once';
            default:
                return 'include';
        }
    }

    private function snippetFor(Node $node): string
    {
        return (new \ReflectionClass($node))->getShortName();
    }

    /** @return list<array{type:string,line:int,target:string}> */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /** @return list<array{type:string,line:int,name:string}> */
    public function getClassReferences(): array
    {
        return $this->classReferences;
    }

    /** @return list<array{line:int,fqcn:string}> */
    public function getUseStatements(): array
    {
        return $this->useStatements;
    }

    /** @return list<array{line:int,reason:string,snippet:string}> */
    public function getUnresolved(): array
    {
        return $this->unresolved;
    }
}
