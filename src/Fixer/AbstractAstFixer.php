<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

use EduDeps\Parser\ParserFactory;
use FilesystemIterator;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Base para fixers que reescrevem AST preservando formatacao.
 *
 * Template method: a subclasse implementa mutate() (modifica o AST clonado e
 * retorna o numero de ocorrencias corrigidas); esta base cuida de iteracao,
 * encoding ISO-8859-1, parse com tokens, clone via CloningVisitor e
 * impressao com printFormatPreserving — que re-imprime apenas os nodes
 * modificados, gerando diff cirurgico (licao da Regressao #2: a primeira
 * versao com prettyPrintFile reformatava o arquivo inteiro).
 */
abstract class AbstractAstFixer
{
    /** @var list<string> substrings de path; arquivo cujo path contem qualquer uma e pulado */
    private array $excludePatterns;

    private Parser $parser;
    private Standard $printer;

    /**
     * @param list<string>|null $excludePatterns
     */
    public function __construct(?array $excludePatterns = null)
    {
        $this->excludePatterns = $excludePatterns ?? [
            '/vendor/',
            '/node_modules/',
            '/extension/modification/',
        ];
        $this->parser = ParserFactory::create();
        $this->printer = new Standard();
    }

    public function detect(string $projectRoot): FixResult
    {
        return $this->run($projectRoot, true);
    }

    public function fix(string $projectRoot, bool $dryRun = false): FixResult
    {
        return $this->run($projectRoot, $dryRun);
    }

    /**
     * Pre-filtro barato por substring antes do parse (ex: "class ").
     * Retornar true sempre desabilita o filtro.
     */
    abstract protected function shouldParse(string $source): bool;

    /**
     * Modifica o AST clonado in-place e retorna o numero de ocorrencias
     * corrigidas (0 = arquivo intacto, nada e gravado).
     *
     * @param array<\PhpParser\Node> $newAst
     */
    abstract protected function mutate(array $newAst): int;

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
            if ($this->isExcluded($path)) {
                $result->filesSkipped++;
                continue;
            }

            $result->filesScanned++;

            $source = @file_get_contents($path);
            if ($source === false) {
                $result->filesWithErrors++;
                continue;
            }
            if (!$this->shouldParse($source)) {
                continue;
            }

            $encoding = mb_detect_encoding($source, ['UTF-8', 'ISO-8859-1'], true);
            $sourceUtf8 = $encoding === 'ISO-8859-1'
                ? (string) iconv('ISO-8859-1', 'UTF-8//IGNORE', $source)
                : $source;

            try {
                $oldAst = $this->parser->parse($sourceUtf8);
            } catch (\Throwable $e) {
                $result->filesWithErrors++;
                continue;
            }
            if ($oldAst === null) {
                continue;
            }
            $oldTokens = $this->parser->getTokens();

            $cloneTraverser = new NodeTraverser();
            $cloneTraverser->addVisitor(new CloningVisitor());
            $newAst = $cloneTraverser->traverse($oldAst);

            $occurrences = $this->mutate($newAst);
            if ($occurrences === 0) {
                continue;
            }

            if (!$dryRun) {
                $newCode = $this->printer->printFormatPreserving($newAst, $oldAst, $oldTokens);
                if ($encoding === 'ISO-8859-1') {
                    $newCode = (string) iconv('UTF-8', 'ISO-8859-1//IGNORE', $newCode);
                }
                file_put_contents($path, $newCode);
            }

            $result->filesAffected++;
            $result->tagsReplaced += $occurrences;
            $result->affectedFiles[] = ['path' => $path, 'count' => $occurrences];
        }

        return $result;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
