<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Resolver\PathResolver;
use PHPUnit\Framework\TestCase;

final class PathResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(__DIR__ . '/../fixtures/edu_sample');
        $this->assertNotFalse($this->root, 'Fixtures dir missing');
    }

    public function test_resolves_modification_target_to_existing_file(): void
    {
        $resolver = new PathResolver($this->root);
        $resolved = $resolver->resolveLiteral('libs/db_stdlib.php', 'require_once+modification');

        $this->assertNotNull($resolved);
        $this->assertStringEndsWith('libs/db_stdlib.php', $resolved->absolutePath);
    }

    public function test_returns_null_when_target_does_not_exist(): void
    {
        $resolver = new PathResolver($this->root);
        $this->assertNull($resolver->resolveLiteral('libs/nonexistent.php', 'require'));
    }

    public function test_normalizes_windows_path_separators(): void
    {
        $resolver = new PathResolver($this->root);
        $resolved = $resolver->resolveLiteral('libs\\db_stdlib.php', 'include');

        $this->assertNotNull($resolved);
        $this->assertStringNotContainsString('\\', $resolved->absolutePath);
    }
}
