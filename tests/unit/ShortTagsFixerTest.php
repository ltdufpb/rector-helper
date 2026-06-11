<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Fixer\ShortTagsFixer;
use PHPUnit\Framework\TestCase;

final class ShortTagsFixerTest extends TestCase
{
    private string $sourceDir;
    private string $workDir;

    protected function setUp(): void
    {
        $this->sourceDir = realpath(__DIR__ . '/../fixtures/short_tags_sample');
        $this->assertNotFalse($this->sourceDir, 'Fixture dir missing');

        // Copia a fixture para um dir temporario antes de modificar.
        $this->workDir = sys_get_temp_dir() . '/edu-deps-shorttags-' . uniqid('', true);
        mkdir($this->workDir, 0777, true);
        $this->copyDir($this->sourceDir, $this->workDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            $this->removeDir($this->workDir);
        }
    }

    public function test_detect_counts_short_tags_without_modifying_disk(): void
    {
        $fixer = new ShortTagsFixer();
        $beforeContent = $this->snapshot($this->workDir);

        $result = $fixer->detect($this->workDir);

        $this->assertGreaterThan(0, $result->filesAffected);
        $this->assertGreaterThan(0, $result->tagsReplaced);
        $this->assertTrue($result->isDryRun);
        $this->assertSame($beforeContent, $this->snapshot($this->workDir), 'detect() nao deve modificar disco');
    }

    public function test_fix_dry_run_does_not_modify_disk(): void
    {
        $fixer = new ShortTagsFixer();
        $beforeContent = $this->snapshot($this->workDir);

        $result = $fixer->fix($this->workDir, true);

        $this->assertGreaterThan(0, $result->filesAffected);
        $this->assertTrue($result->isDryRun);
        $this->assertSame($beforeContent, $this->snapshot($this->workDir), 'dry-run nao deve modificar disco');
    }

    public function test_fix_real_modifies_files_and_matches_detect_count(): void
    {
        $fixer = new ShortTagsFixer();
        $detection = $fixer->detect($this->workDir);

        $applied = $fixer->fix($this->workDir, false);

        $this->assertSame($detection->filesAffected, $applied->filesAffected);
        $this->assertSame($detection->tagsReplaced, $applied->tagsReplaced);
        $this->assertFalse($applied->isDryRun);

        // Apos fix, nenhum arquivo deve mais conter short tag.
        $secondDetect = $fixer->detect($this->workDir);
        $this->assertSame(0, $secondDetect->filesAffected, 'apos fix, nao pode haver short tag pendente');
        $this->assertSame(0, $secondDetect->tagsReplaced);
    }

    public function test_fix_is_idempotent(): void
    {
        $fixer = new ShortTagsFixer();
        $first = $fixer->fix($this->workDir, false);
        $afterFirst = $this->snapshot($this->workDir);

        $second = $fixer->fix($this->workDir, false);

        $this->assertSame(0, $second->filesAffected, 'segunda execucao deve ser no-op');
        $this->assertSame($afterFirst, $this->snapshot($this->workDir), 'segunda execucao nao deve modificar disco');
    }

    public function test_does_not_touch_already_clean_arquivo(): void
    {
        $fixer = new ShortTagsFixer();
        $cleanPath = $this->workDir . '/already_clean.php';
        $originalContent = file_get_contents($cleanPath);

        $fixer->fix($this->workDir, false);

        $this->assertSame(
            $originalContent,
            file_get_contents($cleanPath),
            'arquivo ja limpo (<?php, <?=) nao deve ser tocado'
        );
    }

    public function test_does_not_touch_xml_declaration(): void
    {
        $fixer = new ShortTagsFixer();
        $xmlPath = $this->workDir . '/xml_declaration.php';
        $originalContent = file_get_contents($xmlPath);

        $fixer->fix($this->workDir, false);

        $newContent = file_get_contents($xmlPath);
        // O `<?xml` dentro de string nao deve ser transformado.
        $this->assertStringContainsString('<?xml version', $newContent);
    }

    public function test_skips_excluded_paths(): void
    {
        // Cria um arquivo dentro de vendor/ dentro do workDir.
        $vendorDir = $this->workDir . '/vendor';
        mkdir($vendorDir, 0777, true);
        $vendorFile = $vendorDir . '/legacy.php';
        file_put_contents($vendorFile, "<?\necho 'should not be touched';\n?>\n");
        $originalContent = file_get_contents($vendorFile);

        (new ShortTagsFixer())->fix($this->workDir, false);

        $this->assertSame($originalContent, file_get_contents($vendorFile), 'arquivos em vendor/ devem ser pulados');
    }

    private function copyDir(string $src, string $dst): void
    {
        foreach (scandir($src) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $src . '/' . $entry;
            $dstPath = $dst . '/' . $entry;
            if (is_dir($srcPath)) {
                mkdir($dstPath, 0777, true);
                $this->copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /** @return array<string, string> map de path relativo => conteudo */
    private function snapshot(string $dir): array
    {
        $out = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $rel = str_replace($dir . '/', '', str_replace('\\', '/', $file->getPathname()));
                $out[$rel] = file_get_contents($file->getPathname());
            }
        }
        return $out;
    }
}
