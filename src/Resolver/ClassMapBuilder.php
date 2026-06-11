<?php

declare(strict_types=1);

namespace EduDeps\Resolver;

use EduDeps\Parser\EncodingLoader;
use EduDeps\Parser\ParserFactory;
use EduDeps\Parser\Visitor\ClassDeclarationVisitor;
use PhpParser\NodeTraverser;
use PhpParser\Parser;

/**
 * Constroi um mapa "nome de classe -> arquivo" varrendo as pastas de codigo
 * do e-cidade. Usa cache em disco (classmap.json) com mtime guard para
 * acelerar reexecucoes.
 *
 * Indexa duas chaves para cada classe:
 *   - nome curto (ex: cl_aluno, AlunoRepository)
 *   - FQCN se houver namespace (ex: ECidade\Educacao\AlunoRepository)
 */
final class ClassMapBuilder
{
    private const DEFAULT_SCAN_DIRS = [
        'classes',
        'libs',
        'model',
        'src',
    ];

    private Parser $parser;
    private EncodingLoader $encodingLoader;
    private ?string $cacheDir;

    public function __construct(?EncodingLoader $encodingLoader = null, ?Parser $parser = null, ?string $cacheDir = null)
    {
        $this->encodingLoader = $encodingLoader ?? new EncodingLoader();
        $this->parser = $parser ?? ParserFactory::create();
        $this->cacheDir = $cacheDir;
        if ($this->cacheDir !== null) {
            $this->cacheDir = rtrim(str_replace('\\', '/', $this->cacheDir), '/');
            if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
                $this->cacheDir = null;
            }
        }
    }

    /**
     * @param list<string>|null $scanDirs caminhos relativos ao project-root
     * @return ClassMap
     */
    public function build(string $projectRoot, ?array $scanDirs = null): ClassMap
    {
        $projectRoot = PathResolver::normalize(rtrim($projectRoot, '/\\'));
        $scanDirs = $scanDirs ?? self::DEFAULT_SCAN_DIRS;

        $cachedMap = $this->loadFromCache($projectRoot, $scanDirs);
        if ($cachedMap !== null) {
            return $cachedMap;
        }

        $byName = [];
        $byFqcn = [];

        foreach ($scanDirs as $dir) {
            $absoluteDir = $projectRoot . '/' . $dir;
            if (!is_dir($absoluteDir)) {
                continue;
            }
            $this->scanDirectory($absoluteDir, $byName, $byFqcn);
        }

        $map = new ClassMap($byName, $byFqcn);
        $this->saveToCache($projectRoot, $scanDirs, $map);
        return $map;
    }

    /**
     * @param array<string, list<string>> $byName
     * @param array<string, string> $byFqcn
     */
    private function scanDirectory(string $dir, array &$byName, array &$byFqcn): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $absolutePath = PathResolver::normalize($file->getPathname());
            try {
                $loaded = $this->encodingLoader->load($absolutePath);
                $ast = $this->parser->parse($loaded['source']);
            } catch (\Throwable $e) {
                continue;
            }
            if ($ast === null) {
                continue;
            }

            $visitor = new ClassDeclarationVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->getDeclarations() as $decl) {
                $byName[$decl['name']][] = $absolutePath;
                $byFqcn[$decl['fqcn']] = $absolutePath;
            }
        }
    }

    /**
     * @param list<string> $scanDirs
     */
    private function cacheFile(string $projectRoot, array $scanDirs): ?string
    {
        if ($this->cacheDir === null) {
            return null;
        }
        $signature = sha1($projectRoot . '|' . implode(',', $scanDirs));
        return $this->cacheDir . '/classmap_' . $signature . '.json';
    }

    /**
     * @param list<string> $scanDirs
     */
    private function loadFromCache(string $projectRoot, array $scanDirs): ?ClassMap
    {
        $file = $this->cacheFile($projectRoot, $scanDirs);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['byName'], $data['byFqcn'], $data['mtime'])) {
            return null;
        }
        if ($this->scanDirsAreNewerThan($projectRoot, $scanDirs, (int) $data['mtime'])) {
            return null;
        }
        return new ClassMap($data['byName'], $data['byFqcn']);
    }

    /**
     * @param list<string> $scanDirs
     */
    private function saveToCache(string $projectRoot, array $scanDirs, ClassMap $map): void
    {
        $file = $this->cacheFile($projectRoot, $scanDirs);
        if ($file === null) {
            return;
        }
        $payload = [
            'mtime' => time(),
            'byName' => $map->getByName(),
            'byFqcn' => $map->getByFqcn(),
        ];
        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param list<string> $scanDirs
     */
    private function scanDirsAreNewerThan(string $projectRoot, array $scanDirs, int $cachedMtime): bool
    {
        foreach ($scanDirs as $dir) {
            $absoluteDir = $projectRoot . '/' . $dir;
            if (!is_dir($absoluteDir)) {
                continue;
            }
            $mtime = filemtime($absoluteDir);
            if ($mtime !== false && $mtime > $cachedMtime) {
                return true;
            }
        }
        return false;
    }
}
