<?php

declare(strict_types=1);

namespace EduDeps\Cli\Command;

use EduDeps\Config\RegressionCatalog;
use EduDeps\Linter\Php8Linter;
use EduDeps\Resolver\PathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Detecta padroes legados conhecidos ANTES de rodar o Rector, dirigido pelo
 * catalogo config/regressions.yaml.
 *
 * Modos:
 *  - default: relatorio de ocorrencias por regra
 *  - --rule=<id>: restringe a uma regra
 *  - --strict: exit 1 se houver qualquer ocorrencia (CI)
 */
final class LintPhp8Command extends Command
{
    protected static $defaultName = 'lint-php8';

    protected function configure(): void
    {
        $this
            ->setDescription('Detecta padroes legados do catalogo de regressoes antes do Rector rodar.')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Raiz do projeto PHP legado')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Restringe a uma regra do catalogo (id)')
            ->addOption('catalog', null, InputOption::VALUE_REQUIRED, 'Caminho do regressions.yaml', null)
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 1 se houver ocorrencias (CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectRoot = $input->getOption('project-root');
        if ($projectRoot === null) {
            $io->error('--project-root e obrigatorio.');
            return Command::FAILURE;
        }
        $projectRoot = PathResolver::normalize(rtrim((string) $projectRoot, '/\\'));
        if (!is_dir($projectRoot)) {
            $io->error(sprintf('Diretorio nao existe: %s', $projectRoot));
            return Command::FAILURE;
        }

        $catalogPath = $input->getOption('catalog') ?? dirname(__DIR__, 3) . '/config/regressions.yaml';
        $catalog = RegressionCatalog::fromFile((string) $catalogPath);

        $onlyRule = $input->getOption('rule');
        if ($onlyRule !== null && $catalog->findById((string) $onlyRule) === null) {
            $io->error(sprintf('Regra "%s" nao existe no catalogo. Disponiveis: %s', $onlyRule, implode(', ', array_column($catalog->all(), 'id'))));
            return Command::FAILURE;
        }

        $io->title('edu-deps lint-php8');
        $io->writeln(sprintf('Project root: <info>%s</info>', $projectRoot));
        $io->writeln(sprintf('Catalogo:     <info>%s</info> (%d regras)', $catalogPath, $catalog->count()));

        $linter = new Php8Linter($catalog);
        $start = microtime(true);
        $report = $linter->lint($projectRoot, $onlyRule !== null ? (string) $onlyRule : null);
        $elapsed = microtime(true) - $start;

        $totalOccurrences = 0;
        $tableRows = [];
        foreach ($report['rules'] as $id => $rule) {
            $totalOccurrences += $rule['occurrences'];
            $tableRows[] = [$id, $rule['files'], $rule['occurrences']];
        }

        $io->section('Ocorrencias por regra (deteccao regex)');
        $io->table(['Regra', 'Arquivos', 'Ocorrencias'], $tableRows);

        if ($output->isVerbose()) {
            foreach ($report['rules'] as $id => $rule) {
                if ($rule['samples'] === []) {
                    continue;
                }
                $io->writeln(sprintf('<comment>%s</comment> — amostras:', $id));
                foreach ($rule['samples'] as $sample) {
                    $io->writeln('  ' . $sample);
                }
            }
        }

        if ($report['skippedRules'] !== []) {
            $io->section('Regras fora do lint (deteccao ast/config)');
            foreach ($report['skippedRules'] as $skipped) {
                $io->writeln(sprintf('  %s (%s) — usar fix-regressions ou validacao de config', $skipped['id'], $skipped['tipo']));
            }
        }

        $io->section('Resumo');
        $io->definitionList(
            ['Arquivos escaneados' => (string) $report['filesScanned']],
            ['Ocorrencias totais' => (string) $totalOccurrences],
            ['Tempo (s)' => sprintf('%.1f', $elapsed)]
        );

        if ($input->getOption('strict') && $totalOccurrences > 0) {
            $io->error(sprintf('%d ocorrencia(s) de padroes legados — modo --strict aborta com exit 1.', $totalOccurrences));
            return Command::FAILURE;
        }

        if ($totalOccurrences === 0) {
            $io->success('Nenhum padrao legado do catalogo encontrado.');
        } else {
            $io->warning(sprintf('%d ocorrencia(s) encontradas. Rode bin/edu-deps fix-regressions para corrigir as automatizaveis.', $totalOccurrences));
        }

        return Command::SUCCESS;
    }
}
