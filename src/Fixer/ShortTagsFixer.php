<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Corrige short open tags PHP 4 (`<?`) em projetos legados.
 *
 * Padrao alvo: `<?` que nao seja `<?php`, `<?=` ou `<?xml`. Usa PCRE com
 * lookahead negativo para cobrir casos especiais como `<?` no fim de linha
 * (apenas seguido por \n) que escapam de regex bracket como `[^pxe?=]`.
 *
 * Idempotente: rodar duas vezes resulta em 0 mudancas na segunda. So grava
 * arquivos com mudancas reais (preserva mtime, evita poluir git status).
 *
 * Esta classe e usada pelo comando CLI `bin/edu-deps fix-short-tags` e
 * tambem pelo script standalone `scripts/fix-short-open-tags.php` (que
 * agora e wrapper fino sobre ela).
 *
 * Lican aprendida documentada em edu-deps/docs/REGRESSOES_RECTOR.md
 * (Regressao #4): o Rector NAO converte `<?` para `<?php` — short tag e
 * problema PRE-parse, fora do escopo de regras Rector. Por isso a edu-deps
 * precisa cobrir.
 */
final class ShortTagsFixer
{
    private const PATTERN = '/<\?(?!php|=|xml)/';
    private const REPLACEMENT = '<?php ';

    /** @var list<string> substrings de path; arquivo cujo path contem qualquer uma e pulado */
    private array $excludePatterns;

    /** @var list<string>|null substrings de path; se setado, so processa paths que contem alguma (escopo) */
    private ?array $includePatterns;

    /**
     * @param list<string>|null $excludePatterns
     * @param list<string>|null $includePatterns escopo: null = todo o projeto; senao, so paths que contem alguma substring
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

    /**
     * Conta short tags sem modificar disco. Util para pre-check do scan.
     */
    public function detect(string $projectRoot): FixResult
    {
        return $this->run($projectRoot, true);
    }

    /**
     * Aplica o fix. Em modo dryRun, conta mas nao grava.
     */
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

            // Otimizacao: pula arquivos sem nenhum '<?'
            if (strpos($source, '<?') === false) {
                continue;
            }

            $count = 0;
            $new = preg_replace(self::PATTERN, self::REPLACEMENT, $source, -1, $count);

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
        if ($this->includePatterns !== null) {
            foreach ($this->includePatterns as $pattern) {
                if (strpos($path, $pattern) !== false) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
