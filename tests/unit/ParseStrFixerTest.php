<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Fixer\ParseStrFixer;
use PHPUnit\Framework\TestCase;

final class ParseStrFixerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/parsestr_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function fixContents(string $file): string
    {
        // Normaliza CRLF: com core.autocrlf=true (Windows) o heredoc deste
        // arquivo contem \r\n, que se propaga para a fixture e a saida.
        return str_replace("\r\n", "\n", file_get_contents($file));
    }

    public function test_adds_result_arg_and_extract_preserving_first_arg(): void
    {
        $source = <<<'PHP'
<?php
db_postmemory($HTTP_POST_VARS);
parse_str($HTTP_SERVER_VARS["QUERY_STRING"]);
$x = 1;
PHP;
        file_put_contents($this->tmpDir . '/a.php', $source);

        $result = (new ParseStrFixer())->fix($this->tmpDir);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame(1, $result->tagsReplaced);

        $fixed = $this->fixContents($this->tmpDir . '/a.php');
        // 1o argumento preservado intacto + 2o argumento acrescentado.
        $this->assertStringContainsString('parse_str($HTTP_SERVER_VARS["QUERY_STRING"], $_parseStr);', $fixed);
        $this->assertStringContainsString('extract($_parseStr, EXTR_SKIP);', $fixed);
        // Linhas vizinhas intactas (diff cirurgico).
        $this->assertStringContainsString('db_postmemory($HTTP_POST_VARS);', $fixed);
        $this->assertStringContainsString('$x = 1;', $fixed);
    }

    public function test_handles_nested_call_argument(): void
    {
        $source = <<<'PHP'
<?php
parse_str(base64_decode($HTTP_SERVER_VARS['QUERY_STRING']));
PHP;
        file_put_contents($this->tmpDir . '/b.php', $source);

        $result = (new ParseStrFixer())->fix($this->tmpDir);

        $this->assertSame(1, $result->tagsReplaced);
        $fixed = $this->fixContents($this->tmpDir . '/b.php');
        $this->assertStringContainsString("parse_str(base64_decode(\$HTTP_SERVER_VARS['QUERY_STRING']), \$_parseStr);", $fixed);
        $this->assertStringContainsString('extract($_parseStr, EXTR_SKIP);', $fixed);
    }

    public function test_does_not_touch_already_two_args(): void
    {
        $source = <<<'PHP'
<?php
parse_str($_SERVER["QUERY_STRING"] ?? "", $out);
PHP;
        file_put_contents($this->tmpDir . '/c.php', $source);

        $result = (new ParseStrFixer())->fix($this->tmpDir);

        $this->assertSame(0, $result->filesAffected);
        $this->assertSame($source, file_get_contents($this->tmpDir . '/c.php'));
    }

    public function test_does_not_touch_parse_str_used_as_expression(): void
    {
        // Fora de posicao de statement nao ha efeito de importacao de escopo
        // a preservar — e mexer aqui mudaria a semantica. Deve ser ignorado.
        $source = <<<'PHP'
<?php
if (parse_str($s)) {
    echo 1;
}
PHP;
        file_put_contents($this->tmpDir . '/d.php', $source);

        $result = (new ParseStrFixer())->fix($this->tmpDir);

        $this->assertSame(0, $result->filesAffected);
    }

    public function test_include_filter_restricts_scope(): void
    {
        $src = "<?php\nparse_str(\$s);\n";
        mkdir($this->tmpDir . '/edu', 0777, true);
        mkdir($this->tmpDir . '/almox', 0777, true);
        file_put_contents($this->tmpDir . '/edu/func_aluno.php', $src);
        file_put_contents($this->tmpDir . '/almox/func_matestoque.php', $src);

        // So o path que contem '/edu/' deve ser tocado.
        $result = (new ParseStrFixer(null, ['/edu/']))->fix($this->tmpDir);

        $this->assertSame(1, $result->filesAffected);
        $this->assertStringContainsString('extract($_parseStr, EXTR_SKIP);', file_get_contents($this->tmpDir . '/edu/func_aluno.php'));
        $this->assertSame($src, file_get_contents($this->tmpDir . '/almox/func_matestoque.php'));

        // Limpeza dos subdiretorios (tearDown so remove *.php do nivel raiz).
        @unlink($this->tmpDir . '/edu/func_aluno.php');
        @unlink($this->tmpDir . '/almox/func_matestoque.php');
        @rmdir($this->tmpDir . '/edu');
        @rmdir($this->tmpDir . '/almox');
    }

    public function test_dry_run_does_not_write(): void
    {
        $source = <<<'PHP'
<?php
parse_str($s);
PHP;
        file_put_contents($this->tmpDir . '/e.php', $source);

        $result = (new ParseStrFixer())->fix($this->tmpDir, true);

        $this->assertSame(1, $result->filesAffected);
        $this->assertSame($source, file_get_contents($this->tmpDir . '/e.php'));
    }
}
