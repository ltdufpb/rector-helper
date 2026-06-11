<?php

declare(strict_types=1);

namespace EduDeps\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Overrides manuais para casos que a analise estatica nao resolve.
 *
 *  - classes: mapa direto "nome -> caminho relativo ao project-root"
 *  - patterns: mapa "glob -> { extra: [paths] }" — para um arquivo origem
 *    que casa com o glob, as deps extras sao adicionadas ao grafo
 */
final class Overrides
{
    /** @var array<string, string> */
    private array $classes;

    /** @var array<string, list<string>> */
    private array $patternExtras;

    /**
     * @param array<string, string> $classes
     * @param array<string, list<string>> $patternExtras
     */
    public function __construct(array $classes = [], array $patternExtras = [])
    {
        $this->classes = $classes;
        $this->patternExtras = $patternExtras;
    }

    public static function loadFromFile(string $file): self
    {
        if (!is_file($file)) {
            return new self();
        }
        $data = Yaml::parseFile($file);
        if (!is_array($data)) {
            return new self();
        }

        $classes = is_array($data['classes'] ?? null) ? $data['classes'] : [];

        $patternExtras = [];
        foreach (($data['patterns'] ?? []) as $glob => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $extra = $cfg['extra'] ?? [];
            if (!is_array($extra)) {
                continue;
            }
            $patternExtras[(string) $glob] = array_values(array_map('strval', $extra));
        }

        return new self($classes, $patternExtras);
    }

    public function getClassPath(string $className): ?string
    {
        return $this->classes[$className] ?? null;
    }

    /**
     * Devolve os paths extras (relativos ao project-root) para um arquivo
     * origem cujo nome casa com algum dos globs configurados.
     *
     * @return list<string>
     */
    public function extrasForSourceFile(string $sourceFile, string $projectRoot): array
    {
        $sourceFile = str_replace('\\', '/', $sourceFile);
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $relative = $sourceFile;
        if (strpos($sourceFile, $projectRoot) === 0) {
            $relative = ltrim(substr($sourceFile, strlen($projectRoot)), '/');
        }
        $basename = basename($relative);

        $extras = [];
        foreach ($this->patternExtras as $glob => $paths) {
            if (fnmatch($glob, $basename) || fnmatch($glob, $relative)) {
                foreach ($paths as $p) {
                    $extras[] = $p;
                }
            }
        }
        return $extras;
    }

    public function save(string $file): void
    {
        $data = [
            'classes' => $this->classes,
            'patterns' => array_map(
                static fn (array $paths) => ['extra' => array_values($paths)],
                $this->patternExtras
            ),
        ];
        file_put_contents($file, Yaml::dump($data, 4, 2));
    }

    public function setClass(string $className, string $relativePath): void
    {
        $this->classes[$className] = $relativePath;
    }

    /** @return array<string, string> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
