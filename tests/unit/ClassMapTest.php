<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Resolver\ClassMap;
use PHPUnit\Framework\TestCase;

final class ClassMapTest extends TestCase
{
    public function test_finds_by_exact_name(): void
    {
        $map = new ClassMap(['Services_JSON' => ['/libs/JSON.php']], []);

        $this->assertSame(['/libs/JSON.php'], $map->findByName('Services_JSON'));
    }

    public function test_falls_back_to_case_insensitive_lookup(): void
    {
        // Caso real do e-cidade: "new services_json()" com a classe
        // declarada como "Services_JSON" em libs/JSON.php (93 ocorrencias).
        $map = new ClassMap(['Services_JSON' => ['/libs/JSON.php']], []);

        $this->assertSame(['/libs/JSON.php'], $map->findByName('services_json'));
        $this->assertSame(['/libs/JSON.php'], $map->findByName('SERVICES_JSON'));
    }

    public function test_exact_match_has_priority_over_case_variant(): void
    {
        $map = new ClassMap([
            'db_utils' => ['/libs/db_utils.php'],
            'DB_Utils' => ['/std/DB_Utils.php'],
        ], []);

        // Match exato nao pode ser contaminado pelo indice case-insensitive.
        $this->assertSame(['/libs/db_utils.php'], $map->findByName('db_utils'));
        $this->assertSame(['/std/DB_Utils.php'], $map->findByName('DB_Utils'));
    }

    public function test_case_variants_merge_in_fallback(): void
    {
        $map = new ClassMap([
            'db_utils' => ['/libs/db_utils.php'],
            'DB_Utils' => ['/std/DB_Utils.php'],
        ], []);

        // Lookup com caixa inexistente cai no fallback e retorna ambos.
        $this->assertSame(['/libs/db_utils.php', '/std/DB_Utils.php'], $map->findByName('Db_UtIlS'));
    }

    public function test_returns_empty_for_unknown_class(): void
    {
        $map = new ClassMap(['Services_JSON' => ['/libs/JSON.php']], []);

        $this->assertSame([], $map->findByName('cl_inexistente'));
    }
}
