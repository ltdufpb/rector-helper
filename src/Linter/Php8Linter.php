<?php

declare(strict_types=1);

namespace EduDeps\Linter;

use EduDeps\Config\RegressionCatalog;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Detecta padroes legados ANTES de rodar o Rector, usando os padroes regex
 * do catalogo de regressoes (config/regressions.yaml).
 *
 * E a metade "deteccao" do catalogo: o lint aponta a extensao de cada
 * problema conhecido (short tags, superglobais PHP 4, shim invertido...)
 * para que a migracao saiba o tamanho do trabalho antes da primeira
 * execucao — em vez de descobrir em runtime, como aconteceu nas 7
 * regressoes originais do e-cidade.
 */
final class Php8Linter
{
    private RegressionCatalog $catalog;

    /** @var list<string> */
    private array $excludePatterns;

    /**
     * @param list<string>|null $excludePatterns
     */
    public function __construct(RegressionCatalog $catalog, ?array $excludePatterns = null)
    {
        $this->catalog = $catalog;
        $this->excludePatterns = $excludePatterns ?? [
            '/vendor/',
            '/node_modules/',
            '/extension/modification/',
        ];
    }

    /**
     * @param string|null $onlyRuleId restringe a uma regra do catalogo
     * @return array{
     *   filesScanned: int,
     *   rules: array<string, array{titulo: string, files: int, occurrences: int, samples: list<string>}>,
     *   skippedRules: list<array{id: string, tipo: string}>
     * }
     */
    public function lint(string $projectRoot, ?string $onlyRuleId = null): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        $rules = [];
        $skippedRules = [];
        foreach ($this->catalog->all() as $entry) {
            if ($onlyRuleId !== null && $entry['id'] !== $onlyRuleId) {
                continue;
            }
            if ($entry['deteccao']['tipo'] !== 'regex') {
                // Regras ast/config exigem analise dedicada (fix-regressions
                // ou validacao de configuracao) — reportadas como fora do lint.
                $skippedRules[] = ['id' => $entry['id'], 'tipo' => $entry['deteccao']['tipo']];
                continue;
            }
            $rules[$entry['id']] = [
                'titulo' => $entry['titulo'],
                'pattern' => '/' . str_replace('/', '\/', $entry['deteccao']['padrao']) . '/',
                'files' => 0,
                'occurrences' => 0,
                'samples' => [],
            ];
        }

        $filesScanned = 0;
        if ($rules !== [] && is_dir($projectRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if ($this->isExcluded($path)) {
                    continue;
                }
                $source = @file_get_contents($path);
                if ($source === false) {
                    continue;
                }
                $filesScanned++;

                foreach ($rules as $id => &$rule) {
                    $count = preg_match_all($rule['pattern'], $source);
                    if ($count === false || $count === 0) {
                        continue;
                    }
                    $rule['files']++;
                    $rule['occurrences'] += $count;
                    if (count($rule['samples']) < 5) {
                        $rule['samples'][] = $path;
                    }
                }
                unset($rule);
            }
        }

        foreach ($rules as &$rule) {
            unset($rule['pattern']);
        }
        unset($rule);

        return [
            'filesScanned' => $filesScanned,
            'rules' => $rules,
            'skippedRules' => $skippedRules,
        ];
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
