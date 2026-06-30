<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Fixer\CurlyStringOffsetFixer;
use PHPUnit\Framework\TestCase;

final class CurlyStringOffsetFixerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/curly_offset_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    private function contents(string $file): string
    {
        return str_replace("\r\n", "\n", file_get_contents($file));
    }

    public function test_rewrites_variable_array_and_property_offsets(): void
    {
        $source = <<<'PHP'
<?php
$a = $str{$i};
$b = $code['display']{$i};
$this->_samples{$offset + $i} = $sampleBinary{$i};
PHP;
        $file = $this->tmpDir . '/sample.php';
        file_put_contents($file, $source);

        $result = (new CurlyStringOffsetFixer())->fix($this->tmpDir);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame(4, $result->tagsReplaced);
        $fixed = $this->contents($file);
        $this->assertStringContainsString('$a = $str[$i];', $fixed);
        $this->assertStringContainsString('$b = $code[\'display\'][$i];', $fixed);
        $this->assertStringContainsString('$this->_samples[$offset + $i] = $sampleBinary[$i];', $fixed);
    }

    public function test_dry_run_does_not_write(): void
    {
        $source = "<?php\n\$a = \$str{0};\n";
        $file = $this->tmpDir . '/dry.php';
        file_put_contents($file, $source);

        $result = (new CurlyStringOffsetFixer())->fix($this->tmpDir, true);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame($source, file_get_contents($file));
    }

    public function test_is_idempotent(): void
    {
        $file = $this->tmpDir . '/idempotent.php';
        file_put_contents($file, "<?php\n\$a = \$str{0};\n");

        (new CurlyStringOffsetFixer())->fix($this->tmpDir);
        $second = (new CurlyStringOffsetFixer())->fix($this->tmpDir);

        $this->assertSame(0, $second->filesAffected);
        $this->assertSame(0, $second->tagsReplaced);
    }

    public function test_include_filter_restricts_scope(): void
    {
        mkdir($this->tmpDir . '/securimage', 0777, true);
        mkdir($this->tmpDir . '/fora', 0777, true);
        file_put_contents($this->tmpDir . '/securimage/a.php', "<?php\n\$a = \$str{0};\n");
        file_put_contents($this->tmpDir . '/fora/b.php', "<?php\n\$b = \$str{1};\n");

        $result = (new CurlyStringOffsetFixer(null, ['/securimage/']))->fix($this->tmpDir);

        $this->assertSame(1, $result->filesAffected);
        $this->assertStringContainsString('$str[0]', file_get_contents($this->tmpDir . '/securimage/a.php'));
        $this->assertStringContainsString('$str{1}', file_get_contents($this->tmpDir . '/fora/b.php'));

        @unlink($this->tmpDir . '/securimage/a.php');
        @unlink($this->tmpDir . '/fora/b.php');
        @rmdir($this->tmpDir . '/securimage');
        @rmdir($this->tmpDir . '/fora');
    }
}
