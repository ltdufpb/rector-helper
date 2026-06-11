<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Parser\ParserFactory;
use EduDeps\Parser\Visitor\DependencyVisitor;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\TestCase;

final class DependencyVisitorTest extends TestCase
{
    public function test_extracts_modification_calls(): void
    {
        $source = <<<'PHP'
<?php
require_once(modification("libs/db_stdlib.php"));
include(modification("classes/db_aluno_classe.php"));
PHP;

        $deps = $this->extract($source);

        $this->assertCount(2, $deps);
        $this->assertSame('require_once+modification', $deps[0]['type']);
        $this->assertSame('libs/db_stdlib.php', $deps[0]['target']);
        $this->assertSame('include+modification', $deps[1]['type']);
        $this->assertSame('classes/db_aluno_classe.php', $deps[1]['target']);
    }

    public function test_extracts_literal_includes(): void
    {
        $source = <<<'PHP'
<?php
require_once "libs/literal.php";
include 'libs/other.php';
PHP;

        $deps = $this->extract($source);

        $this->assertCount(2, $deps);
        $this->assertSame('require_once', $deps[0]['type']);
        $this->assertSame('libs/literal.php', $deps[0]['target']);
        $this->assertSame('include', $deps[1]['type']);
        $this->assertSame('libs/other.php', $deps[1]['target']);
    }

    public function test_reports_dynamic_includes_as_unresolved(): void
    {
        $source = <<<'PHP'
<?php
$x = "libs/dynamic.php";
include $x;
include $base . "/file.php";
PHP;

        $visitor = $this->runVisitor($source);

        $this->assertCount(0, $visitor->getDependencies());
        $this->assertCount(2, $visitor->getUnresolved());
        $this->assertSame('include_non_literal', $visitor->getUnresolved()[0]['reason']);
    }

    /**
     * @return list<array{type:string,line:int,target:string}>
     */
    private function extract(string $source): array
    {
        return $this->runVisitor($source)->getDependencies();
    }

    private function runVisitor(string $source): DependencyVisitor
    {
        $parser = ParserFactory::create();
        $ast = $parser->parse($source);
        $this->assertNotNull($ast);

        $visitor = new DependencyVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor;
    }
}
