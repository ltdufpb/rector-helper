<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Parser\PhpBuiltinClasses;
use PHPUnit\Framework\TestCase;

final class PhpBuiltinClassesTest extends TestCase
{
    public function test_recognizes_builtin_classes(): void
    {
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('Exception'));
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('DateInterval'));
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('DOMDocument'));
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('ZipArchive'));
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('SoapClient'));
    }

    public function test_lookup_is_case_insensitive(): void
    {
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('stdclass'));
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('StdClass'));
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('EXCEPTION'));
    }

    public function test_tolerates_leading_backslash(): void
    {
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('\\Exception'));
        $this->assertTrue(PhpBuiltinClasses::isBuiltin('\\DateTime'));
    }

    public function test_rejects_project_classes(): void
    {
        $this->assertFalse(PhpBuiltinClasses::isBuiltin('cl_aluno'));
        $this->assertFalse(PhpBuiltinClasses::isBuiltin('DBDate'));
        $this->assertFalse(PhpBuiltinClasses::isBuiltin('rotulo'));
        $this->assertFalse(PhpBuiltinClasses::isBuiltin('db_stdClass'));
    }
}
