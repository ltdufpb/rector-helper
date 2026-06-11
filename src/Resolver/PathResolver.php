<?php

declare(strict_types=1);

namespace EduDeps\Resolver;

use EduDeps\Config\Overrides;

/**
 * Resolve referencias estaticas em arquivos absolutos.
 *
 * F1+: alvos de modification() e includes literais.
 * F4+: classes legadas cl_* (via convencao + classmap) e namespaces modernos.
 */
final class PathResolver
{
    private string $projectRoot;
    private ?ClassMap $classMap;
    private ?Overrides $overrides;

    public function __construct(string $projectRoot, ?ClassMap $classMap = null, ?Overrides $overrides = null)
    {
        $this->projectRoot = self::normalize(rtrim($projectRoot, '/\\'));
        $this->classMap = $classMap;
        $this->overrides = $overrides;
    }

    public function resolveLiteral(string $target, string $sourceType, ?string $callerFile = null): ?Resolved
    {
        $target = self::normalize($target);

        $candidates = [];
        $candidates[] = $this->projectRoot . '/' . ltrim($target, '/');

        if ($callerFile !== null) {
            $callerDir = dirname(self::normalize($callerFile));
            $candidates[] = $callerDir . '/' . ltrim($target, '/');
        }

        foreach ($candidates as $candidate) {
            $real = @realpath($candidate);
            if ($real !== false && is_file($real)) {
                return new Resolved(self::normalize($real), $sourceType);
            }
        }

        return null;
    }

    /**
     * Resolve uma referencia de classe (vinda de `new X(...)` ou `X::method()`).
     *
     * Estrategia:
     *  1. Se nome comeca com cl_ → tenta a convencao classes/db_<resto>_classe.php
     *  2. Senao, consulta o classmap por nome curto
     *  3. Em ambos os casos, prefere arquivo do classmap quando disponivel
     */
    public function resolveClass(string $className): ?Resolved
    {
        if ($this->overrides !== null) {
            $override = $this->overrides->getClassPath($className);
            if ($override !== null) {
                $candidate = $this->projectRoot . '/' . ltrim(self::normalize($override), '/');
                $real = @realpath($candidate);
                if ($real !== false && is_file($real)) {
                    return new Resolved(self::normalize($real), 'override', 1.0);
                }
            }
        }

        if ($this->classMap === null) {
            return $this->resolveLegacyClassByConvention($className);
        }

        $resolvedByMap = $this->resolveByClassMap($className);
        if ($resolvedByMap !== null) {
            return $resolvedByMap;
        }

        return $this->resolveLegacyClassByConvention($className);
    }

    public function getOverrides(): ?Overrides
    {
        return $this->overrides;
    }

    public function resolveFqcn(string $fqcn): ?Resolved
    {
        if ($this->classMap === null) {
            return null;
        }
        $path = $this->classMap->findByFqcn($fqcn);
        if ($path === null) {
            return null;
        }
        return new Resolved(self::normalize($path), 'use_namespace');
    }

    private function resolveByClassMap(string $className): ?Resolved
    {
        $hits = $this->classMap !== null ? $this->classMap->findByName($className) : [];
        if (count($hits) === 1) {
            return new Resolved(self::normalize($hits[0]), 'classmap', 1.0);
        }
        if (count($hits) > 1) {
            return new Resolved(self::normalize($hits[0]), 'classmap_ambiguous', 0.5);
        }
        return null;
    }

    private function resolveLegacyClassByConvention(string $className): ?Resolved
    {
        if (strncasecmp($className, 'cl_', 3) !== 0) {
            return null;
        }
        $suffix = substr($className, 3);
        $candidate = $this->projectRoot . '/classes/db_' . $suffix . '_classe.php';
        $real = @realpath($candidate);
        if ($real !== false && is_file($real)) {
            return new Resolved(self::normalize($real), 'cl_convention', 0.8);
        }
        return null;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
