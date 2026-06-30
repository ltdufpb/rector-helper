<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Fixer\PgResultGuardFixer;
use PHPUnit\Framework\TestCase;

final class PgResultGuardFixerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pg_result_guard_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function test_wraps_pg_numrows_and_pg_numfields_assignments_with_false_guard(): void
    {
        $source = <<<'PHP'
<?php
$result = @db_query($sql);
$numrows = @pg_numrows($result);
$numcols = @pg_numfields($result);
PHP;
        file_put_contents($this->tmpDir . '/a.php', $source);

        $result = (new PgResultGuardFixer())->fix($this->tmpDir);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame(2, $result->tagsReplaced);

        $fixed = str_replace("\r\n", "\n", file_get_contents($this->tmpDir . '/a.php'));
        $this->assertStringContainsString(
            '$numrows = $result === false || $result === null ? 0 : @pg_numrows($result);',
            $fixed
        );
        $this->assertStringContainsString(
            '$numcols = $result === false || $result === null ? 0 : @pg_numfields($result);',
            $fixed
        );
    }

    public function test_does_not_wrap_already_guarded_assignment(): void
    {
        $source = <<<'PHP'
<?php
$numrows = $result === false || $result === null ? 0 : @pg_numrows($result);
PHP;
        file_put_contents($this->tmpDir . '/b.php', $source);

        $result = (new PgResultGuardFixer())->fix($this->tmpDir);

        $this->assertSame(0, $result->filesAffected);
        $this->assertSame($source, file_get_contents($this->tmpDir . '/b.php'));
    }
}
