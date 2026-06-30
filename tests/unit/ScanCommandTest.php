<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Cli\Command\ScanCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scan_command_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_scan_accepts_multiple_seed_globs_for_mixed_education_submodules(): void
    {
        $tester = new CommandTester(new ScanCommand());

        $exitCode = $tester->execute([
            '--project-root' => __DIR__ . '/../fixtures/edu_sample',
            '--seeds-glob' => 'edu*.php,mer*.php,func_mer_*.php,func_atendrequi.php,func_matestoquetransf*.php,func_matestoqueini*.php,func_matpedido*.php,mat1_matrequi*.php,mat1_matestoqueini*.php,mat4_matpedido002.php,mat4_matpedido003.php,mat1_matpedidoitem001.php',
            '--output-dir' => $this->tmpDir,
            '--cache-dir' => '',
            '--skip-short-tags-check' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $files = str_replace('\\', '/', file_get_contents($this->tmpDir . '/files.txt') ?: '');
        $this->assertStringContainsString('/edu_seed.php', $files);
        $this->assertStringContainsString('/mer_seed.php', $files);
        $this->assertStringContainsString('/func_mer_seed.php', $files);
        $this->assertStringContainsString('/func_atendrequi.php', $files);
        $this->assertStringContainsString('/func_matestoquetransf.php', $files);
        $this->assertStringContainsString('/func_matestoqueini.php', $files);
        $this->assertStringContainsString('/func_matpedido.php', $files);
        $this->assertStringContainsString('/mat1_matrequi002.php', $files);
        $this->assertStringContainsString('/mat1_matestoqueini003.php', $files);
        $this->assertStringContainsString('/mat4_matpedido002.php', $files);
        $this->assertStringContainsString('/mat4_matpedido003.php', $files);
        $this->assertStringContainsString('/mat1_matpedidoitem001.php', $files);
    }

    public function test_scan_adds_menu_map_entries_as_seeds(): void
    {
        $tester = new CommandTester(new ScanCommand());

        $exitCode = $tester->execute([
            '--project-root' => __DIR__ . '/../fixtures/edu_sample',
            '--seeds-glob' => 'edu*.php',
            '--menu-map' => __DIR__ . '/../fixtures/menu_map_educacao.html',
            '--menu-module' => 'Alimentacao Escolar',
            '--menu-path-contains' => 'Procedimentos',
            '--output-dir' => $this->tmpDir,
            '--cache-dir' => '',
            '--skip-short-tags-check' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $files = str_replace('\\', '/', file_get_contents($this->tmpDir . '/files.txt') ?: '');
        $this->assertStringContainsString('/edu_seed.php', $files);
        $this->assertStringContainsString('/mat1_matestoqueini003.php', $files);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }
}
