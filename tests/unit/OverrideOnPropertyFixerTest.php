<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Fixer\OverrideOnPropertyFixer;
use PHPUnit\Framework\TestCase;

final class OverrideOnPropertyFixerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/override_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function test_removes_override_from_property_but_keeps_on_method(): void
    {
        $source = <<<'PHP'
<?php
class Filha extends Base {
    #[\Override]
    public $campo = 1;

    #[\Override]
    public function metodo(): void {}
}
PHP;
        file_put_contents($this->tmpDir . '/a.php', $source);

        $result = (new OverrideOnPropertyFixer())->fix($this->tmpDir);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame(1, $result->tagsReplaced);

        // Normaliza CRLF (ver Php4ConstructorFixerTest): assertiva nao deve
        // depender do line ending do checkout (autocrlf no Windows).
        $fixed = str_replace("\r\n", "\n", file_get_contents($this->tmpDir . '/a.php'));
        // Removido da propriedade...
        $this->assertStringNotContainsString("#[\\Override]\n    public \$campo", $fixed);
        $this->assertStringContainsString('public $campo = 1;', $fixed);
        // ...mas preservado no metodo (la e valido).
        $this->assertStringContainsString("#[\\Override]\n    public function metodo", $fixed);
    }

    public function test_ignores_files_without_override(): void
    {
        file_put_contents($this->tmpDir . '/b.php', "<?php\nclass X { public \$y = 2; }\n");

        $result = (new OverrideOnPropertyFixer())->fix($this->tmpDir);

        $this->assertSame(0, $result->filesAffected);
    }
}
