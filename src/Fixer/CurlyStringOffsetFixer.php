<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Corrige acesso legado a string/array com chaves: $str{$i} -> $str[$i].
 *
 * Este e um problema pre-parse em PHP 8: arquivos com essa sintaxe podem
 * quebrar antes de qualquer regra AST conseguir atuar.
 */
final class CurlyStringOffsetFixer
{
    private const PATTERN = '/(?<![A-Za-z0-9_$])(\$[A-Za-z_][A-Za-z0-9_]*(?:(?:->|::)[A-Za-z_][A-Za-z0-9_]*|\[[^\]\r\n]+\])*)\{([^{}\r\n;]+)\}/';

    /** @var list<string> */
    private array $excludePatterns;

    /** @var list<string>|null */
    private ?array $includePatterns;

    /**
     * @param list<string>|null $excludePatterns
     * @param list<string>|null $includePatterns
     */
    public function __construct(?array $excludePatterns = null, ?array $includePatterns = null)
    {
        $this->excludePatterns = $excludePatterns ?? [
            '/vendor/',
            '/node_modules/',
            '/extension/modification/',
        ];
        $this->includePatterns = ($includePatterns === null || $includePatterns === []) ? null : $includePatterns;
    }

    public function detect(string $projectRoot): FixResult
    {
        return $this->run($projectRoot, true);
    }

    public function fix(string $projectRoot, bool $dryRun = false): FixResult
    {
        return $this->run($projectRoot, $dryRun);
    }

    private function run(string $projectRoot, bool $dryRun): FixResult
    {
        $result = new FixResult();
        $result->isDryRun = $dryRun;

        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        if (!is_dir($projectRoot)) {
            return $result;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if ($this->shouldSkip($path)) {
                $result->filesSkipped++;
                continue;
            }

            $result->filesScanned++;
            $source = @file_get_contents($path);
            if ($source === false) {
                $result->filesWithErrors++;
                continue;
            }

            if (strpos($source, '{') === false || strpos($source, '$') === false) {
                continue;
            }

            $count = 0;
            $new = preg_replace_callback(
                self::PATTERN,
                static fn (array $matches): string => $matches[1] . '[' . trim($matches[2]) . ']',
                $source,
                -1,
                $count
            );

            if ($new === null || $count === 0 || $new === $source) {
                continue;
            }

            $result->filesAffected++;
            $result->tagsReplaced += $count;
            $result->affectedFiles[] = ['path' => $path, 'count' => $count];

            if (!$dryRun) {
                file_put_contents($path, $new);
            }
        }

        return $result;
    }

    private function shouldSkip(string $path): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return true;
            }
        }

        if ($this->includePatterns === null) {
            return false;
        }

        foreach ($this->includePatterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }
}
