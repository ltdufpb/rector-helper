<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Resolver\ClassMapBuilder;
use PHPUnit\Framework\TestCase;

final class ClassMapBuilderTest extends TestCase
{
    private const FIXTURE_ROOT = __DIR__ . '/../fixtures/edu_sample';

    public function test_indexes_classes_from_default_scan_dirs(): void
    {
        $map = (new ClassMapBuilder())->build(self::FIXTURE_ROOT);

        $files = $map->findByName('cl_aluno');
        $this->assertNotEmpty($files);
        $this->assertStringEndsWith('classes/db_aluno_classe.php', $files[0]);
    }

    public function test_indexes_classes_from_legacy_dirs_std_and_dbforms(): void
    {
        $map = (new ClassMapBuilder())->build(self::FIXTURE_ROOT);

        $std = $map->findByName('DBDate');
        $this->assertNotEmpty($std, 'DBDate em std/ deve entrar no classmap');
        $this->assertStringEndsWith('std/DBDate.php', $std[0]);

        $dbforms = $map->findByName('rotulo');
        $this->assertNotEmpty($dbforms, 'rotulo em dbforms/ deve entrar no classmap');
        $this->assertStringEndsWith('dbforms/rotulo.php', $dbforms[0]);
    }

    public function test_indexes_psr4_classes_from_app_dir(): void
    {
        $map = (new ClassMapBuilder())->build(self::FIXTURE_ROOT);

        $byFqcn = $map->findByFqcn('App\Domain\Helpers\StorageHelper');
        $this->assertNotNull($byFqcn, 'FQCN App\... em app/ deve entrar no classmap');
        $this->assertStringEndsWith('app/Domain/Helpers/StorageHelper.php', $byFqcn);

        $byName = $map->findByName('StorageHelper');
        $this->assertNotEmpty($byName, 'nome curto de classe em app/ deve entrar no classmap');
    }

    public function test_explicit_scan_dirs_override_defaults(): void
    {
        $map = (new ClassMapBuilder())->build(self::FIXTURE_ROOT, ['classes']);

        $this->assertNotEmpty($map->findByName('cl_aluno'));
        $this->assertEmpty($map->findByName('DBDate'), 'std/ fora dos scanDirs explicitos nao deve ser indexado');
    }
}
