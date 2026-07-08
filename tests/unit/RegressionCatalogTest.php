<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Config\RegressionCatalog;
use PHPUnit\Framework\TestCase;

final class RegressionCatalogTest extends TestCase
{
    private const CATALOG = __DIR__ . '/../../config/regressions.yaml';

    public function test_loads_catalog_with_eleven_regressions(): void
    {
        $catalog = RegressionCatalog::fromFile(self::CATALOG);

        $this->assertSame(11, $catalog->count());
    }

    public function test_every_entry_has_complete_schema(): void
    {
        $catalog = RegressionCatalog::fromFile(self::CATALOG);

        foreach ($catalog->all() as $entry) {
            $this->assertNotEmpty($entry['id']);
            $this->assertNotEmpty($entry['sintoma']);
            $this->assertNotEmpty($entry['causa_raiz']);
            $this->assertContains($entry['origem'], ['php8', 'rector', 'hotfix']);
            $this->assertArrayHasKey('tipo', $entry['deteccao']);
            $this->assertArrayHasKey('tipo', $entry['fix']);
            $this->assertArrayHasKey('arquivos', $entry['volume_ecidade']);
            // commits pode ser vazio para regressao recem-catalogada (ainda
            // sem hash de fix no fork do e-cidade); o schema so exige a chave.
            $this->assertIsArray($entry['commits']);
        }
    }

    public function test_finds_entry_by_id(): void
    {
        $catalog = RegressionCatalog::fromFile(self::CATALOG);

        $entry = $catalog->findById('short-open-tags');
        $this->assertNotNull($entry);
        $this->assertSame(13280, $entry['volume_ecidade']['arquivos']);

        $this->assertNull($catalog->findById('inexistente'));
    }

    public function test_regex_detection_patterns_are_valid_pcre(): void
    {
        $catalog = RegressionCatalog::fromFile(self::CATALOG);
        $regexEntries = $catalog->withRegexDetection();

        $this->assertNotEmpty($regexEntries);
        foreach ($regexEntries as $entry) {
            $pattern = '/' . str_replace('/', '\/', $entry['deteccao']['padrao']) . '/';
            $this->assertNotFalse(
                @preg_match($pattern, ''),
                sprintf('Padrao regex invalido na regressao "%s"', $entry['id'])
            );
        }
    }

    public function test_short_tags_detection_pattern_matches_real_cases(): void
    {
        $catalog = RegressionCatalog::fromFile(self::CATALOG);
        $pattern = '/' . $catalog->findById('short-open-tags')['deteccao']['padrao'] . '/';

        $this->assertSame(1, preg_match($pattern, '<? echo $x; ?>'));
        $this->assertSame(1, preg_match($pattern, "<?\n"));
        $this->assertSame(1, preg_match($pattern, '<?'));
        $this->assertSame(0, preg_match($pattern, '<?php echo $x;'));
        $this->assertSame(0, preg_match($pattern, '<?= $x ?>'));
        $this->assertSame(0, preg_match($pattern, '<?xml version="1.0"?>'));
    }

    public function test_encoding_fffd_pattern_matches_bytes_not_mojibake_text(): void
    {
        $catalog = RegressionCatalog::fromFile(self::CATALOG);
        $pattern = '/' . $catalog->findById('encoding-fffd')['deteccao']['padrao'] . '/';

        // Sequencia de bytes do U+FFFD (arquivo corrompido) — deve casar.
        $this->assertSame(1, preg_match($pattern, "Inform\xEF\xBF\xBDtica"));
        // Acento ISO-8859-1 saudavel (0xE1 = a-agudo) — NAO deve casar.
        $this->assertSame(0, preg_match($pattern, "Inform\xE1tica"));
        // Acento UTF-8 valido (arquivo ja convertido) — NAO deve casar.
        $this->assertSame(0, preg_match($pattern, "Inform\xC3\xA1tica"));
    }

    public function test_rejects_yaml_with_missing_required_field(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reg') . '.yaml';
        file_put_contents($tmp, "version: 1\nregressions:\n  - id: incompleto\n    titulo: Sem os demais campos\n");

        try {
            $this->expectException(\RuntimeException::class);
            RegressionCatalog::fromFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
