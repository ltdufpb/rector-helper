<?php

declare(strict_types=1);

namespace EduDeps\Parser;

use PhpParser\Node;
use PhpParser\NodeDumper;
use PhpParser\Parser;

/**
 * Cache em disco de ASTs serializados por sha1(conteudo).
 *
 * O nikic/php-parser nao serializa Nodes nativamente de forma estavel, entao
 * persistimos via serialize/unserialize do PHP. Em runs subsequentes evita
 * o custo de re-parse de arquivos identicos.
 */
final class AstCache
{
    private ?string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir;
        if ($this->cacheDir !== null) {
            $this->cacheDir = rtrim(str_replace('\\', '/', $this->cacheDir), '/') . '/ast';
            if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
                throw new \RuntimeException(sprintf('Falha ao criar cache dir: %s', $this->cacheDir));
            }
        }
    }

    /**
     * @return list<Node>|null
     */
    public function get(string $sourceHash): ?array
    {
        $file = $this->fileFor($sourceHash);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $blob = @file_get_contents($file);
        if ($blob === false) {
            return null;
        }
        try {
            $ast = unserialize($blob, ['allowed_classes' => true]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($ast)) {
            return null;
        }
        return $ast;
    }

    /**
     * @param list<Node> $ast
     */
    public function put(string $sourceHash, array $ast): void
    {
        $file = $this->fileFor($sourceHash);
        if ($file === null) {
            return;
        }
        @file_put_contents($file, serialize($ast));
    }

    public static function parserVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            return \Composer\InstalledVersions::getVersion('nikic/php-parser') ?? 'unknown';
        }
        return 'unknown';
    }

    private function fileFor(string $hash): ?string
    {
        if ($this->cacheDir === null) {
            return null;
        }
        return $this->cacheDir . '/' . $hash . '.ast';
    }

    /**
     * Hash canonico de cache: a versao do php-parser entra no hash porque
     * um AST serializado pela v4 nao pode ser reaproveitado apos upgrade
     * para v5 (estrutura de nodes muda e arquivos que a v4 nem parseava
     * passam a parsear). TODO caller de get/put deve usar este metodo.
     */
    public static function hashFor(string $source): string
    {
        return sha1($source . '|' . self::parserVersion());
    }

    public function parseAndCache(Parser $parser, string $source): ?array
    {
        $hash = self::hashFor($source);
        $cached = $this->get($hash);
        if ($cached !== null) {
            return $cached;
        }
        $ast = $parser->parse($source);
        if ($ast === null) {
            return null;
        }
        $this->put($hash, $ast);
        return $ast;
    }
}
