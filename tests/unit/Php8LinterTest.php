<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Config\RegressionCatalog;
use EduDeps\Linter\Php8Linter;
use PHPUnit\Framework\TestCase;

final class Php8LinterTest extends TestCase
{
    private const CATALOG = __DIR__ . '/../../config/regressions.yaml';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lint_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function test_detects_catalog_patterns_in_project(): void
    {
        // 1 arquivo com short tag + superglobal antiga, 1 arquivo limpo.
        file_put_contents(
            $this->tmpDir . '/legado.php',
            "<? echo \$HTTP_SERVER_VARS['PHP_SELF']; ?>\n"
        );
        file_put_contents($this->tmpDir . '/limpo.php', "<?php echo 'ok';\n");

        $catalog = RegressionCatalog::fromFile(self::CATALOG);
        $report = (new Php8Linter($catalog))->lint($this->tmpDir);

        $this->assertSame(2, $report['filesScanned']);

        $shortTags = $report['rules']['short-open-tags'];
        $this->assertSame(1, $shortTags['files']);
        $this->assertSame(1, $shortTags['occurrences']);

        $httpVars = $report['rules']['http-vars-superglobais'];
        $this->assertSame(1, $httpVars['files']);

        // Regras ast/config nao entram no lint regex, mas sao reportadas.
        $skippedIds = array_column($report['skippedRules'], 'id');
        $this->assertContains('php4-constructor', $skippedIds);
        $this->assertContains('rector-skip-namespace-errado', $skippedIds);
    }

    public function test_only_rule_filter(): void
    {
        file_put_contents($this->tmpDir . '/legado.php', "<? echo 1;\n");

        $catalog = RegressionCatalog::fromFile(self::CATALOG);
        $report = (new Php8Linter($catalog))->lint($this->tmpDir, 'short-open-tags');

        $this->assertSame(['short-open-tags'], array_keys($report['rules']));
    }

    public function test_detects_fffd_encoding_corruption(): void
    {
        // Arquivo corrompido: acentos ISO viraram U+FFFD (bytes EF BF BD).
        file_put_contents(
            $this->tmpDir . '/corrompido.php',
            "<?php\necho \"DBSeller Inform\xEF\xBF\xBDtica\";\n// fun\xEF\xBF\xBD\xEF\xBF\xBDes\n"
        );
        // Arquivo ISO-8859-1 saudavel: acento como byte unico 0xE7 — nao dispara.
        file_put_contents(
            $this->tmpDir . '/iso_saudavel.php',
            "<?php\necho \"fun\xE7\xE3o\";\n"
        );

        $catalog = RegressionCatalog::fromFile(self::CATALOG);
        $report = (new Php8Linter($catalog))->lint($this->tmpDir, 'encoding-fffd');

        $rule = $report['rules']['encoding-fffd'];
        $this->assertSame(1, $rule['files']);
        $this->assertSame(3, $rule['occurrences']);
        $this->assertStringEndsWith('corrompido.php', $rule['samples'][0]);
    }

    public function test_detects_inverted_shim_pattern(): void
    {
        // Padrao da regressao #7 (hotfix9): superglobal moderna sobrescrita.
        file_put_contents(
            $this->tmpDir . '/shim.php',
            "<?php\n\$_SESSION = &\$GLOBALS[\"HTTP_SESSION_VARS\"];\n"
        );

        $catalog = RegressionCatalog::fromFile(self::CATALOG);
        $report = (new Php8Linter($catalog))->lint($this->tmpDir, 'shim-sobrescreve-superglobal');

        $this->assertSame(1, $report['rules']['shim-sobrescreve-superglobal']['files']);
    }
}
