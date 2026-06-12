<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Fixer\Php4ConstructorFixer;
use PHPUnit\Framework\TestCase;

final class Php4ConstructorFixerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/php4ctor_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function test_renames_php4_constructor_preserving_formatting(): void
    {
        $source = <<<'PHP'
<?php
class cl_mer_nutricionista {
    var $rotulo = null;

    function cl_mer_nutricionista() {
        $this->rotulo = 1;
    }

    function outro_metodo() {
        return 2;
    }
}
PHP;
        file_put_contents($this->tmpDir . '/a.php', $source);

        $result = (new Php4ConstructorFixer())->fix($this->tmpDir);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame(1, $result->tagsReplaced);

        $fixed = file_get_contents($this->tmpDir . '/a.php');
        $this->assertStringContainsString('function __construct() {', $fixed);
        $this->assertStringNotContainsString('function cl_mer_nutricionista()', $fixed);
        // Diff cirurgico: o resto do arquivo permanece intacto.
        $this->assertStringContainsString("    function outro_metodo() {\n        return 2;\n    }", $fixed);
        $this->assertStringContainsString('var $rotulo = null;', $fixed);
    }

    public function test_does_not_touch_class_with_existing_construct(): void
    {
        $source = <<<'PHP'
<?php
class cl_aluno {
    function __construct() {}
    function cl_aluno() { return 'metodo comum, nao construtor'; }
}
PHP;
        file_put_contents($this->tmpDir . '/b.php', $source);

        $result = (new Php4ConstructorFixer())->fix($this->tmpDir);

        $this->assertSame(0, $result->filesAffected);
        $this->assertStringContainsString('function cl_aluno()', file_get_contents($this->tmpDir . '/b.php'));
    }

    public function test_dry_run_does_not_write(): void
    {
        $source = "<?php\nclass foo { function FOO() {} }\n";
        file_put_contents($this->tmpDir . '/c.php', $source);

        $result = (new Php4ConstructorFixer())->fix($this->tmpDir, true);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame($source, file_get_contents($this->tmpDir . '/c.php'));
    }
}
