<?php

declare(strict_types=1);

namespace EduDeps\Cli\Command;

use EduDeps\Config\RegressionCatalog;
use EduDeps\Fixer\OverrideOnPropertyFixer;
use EduDeps\Fixer\Php4ConstructorFixer;
use EduDeps\Fixer\ShortTagsFixer;
use EduDeps\Resolver\PathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aplica em batch os fixes automatizados do catalogo de regressoes
 * (config/regressions.yaml). Consolida os scripts hotfix da migracao do
 * e-cidade em comando unico da ferramenta.
 *
 * Modos:
 *  - default: aplica todos os fixes automatizaveis
 *  - --rule=<id>: aplica apenas uma regra
 *  - --dry-run: preview sem gravar disco
 *  - --list: lista regras do catalogo e status de automacao
 */
final class FixRegressionsCommand extends Command
{
    protected static $defaultName = 'fix-regressions';

    /**
     * Mapeamento regra do catalogo -> fixer implementado. Regras ausentes
     * aqui tem fix manual (a estrategia e descrita no proprio catalogo).
     *
     * @return array<string, callable(): object>
     */
    private function fixers(): array
    {
        return [
            'short-open-tags' => static fn (): object => new ShortTagsFixer(),
            'php4-constructor' => static fn (): object => new Php4ConstructorFixer(),
            'override-attribute-on-property' => static fn (): object => new OverrideOnPropertyFixer(),
        ];
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Aplica em batch os fixes automatizados do catalogo de regressoes.')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Raiz do projeto PHP legado')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Aplica apenas a regra informada (id)')
            ->addOption('catalog', null, InputOption::VALUE_REQUIRED, 'Caminho do regressions.yaml', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'So reporta, nao modifica disco')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Lista as regras do catalogo e status de automacao');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $catalogPath = $input->getOption('catalog') ?? dirname(__DIR__, 3) . '/config/regressions.yaml';
        $catalog = RegressionCatalog::fromFile((string) $catalogPath);
        $fixers = $this->fixers();

        if ($input->getOption('list')) {
            $io->title('Catalogo de regressoes');
            $rows = [];
            foreach ($catalog->all() as $entry) {
                $rows[] = [
                    $entry['id'],
                    $entry['origem'],
                    isset($fixers[$entry['id']]) ? 'automatizado' : 'manual (' . $entry['fix']['tipo'] . ')',
                    $entry['volume_ecidade']['arquivos'],
                ];
            }
            $io->table(['Regra', 'Origem', 'Fix', 'Arquivos no e-cidade'], $rows);
            return Command::SUCCESS;
        }

        $projectRoot = $input->getOption('project-root');
        if ($projectRoot === null) {
            $io->error('--project-root e obrigatorio (ou use --list).');
            return Command::FAILURE;
        }
        $projectRoot = PathResolver::normalize(rtrim((string) $projectRoot, '/\\'));
        if (!is_dir($projectRoot)) {
            $io->error(sprintf('Diretorio nao existe: %s', $projectRoot));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $onlyRule = $input->getOption('rule');
        if ($onlyRule !== null && $catalog->findById((string) $onlyRule) === null) {
            $io->error(sprintf('Regra "%s" nao existe no catalogo. Disponiveis: %s', $onlyRule, implode(', ', array_column($catalog->all(), 'id'))));
            return Command::FAILURE;
        }

        $io->title('edu-deps fix-regressions' . ($dryRun ? ' (dry-run)' : ''));
        $io->writeln(sprintf('Project root: <info>%s</info>', $projectRoot));

        $summaryRows = [];
        $manualRules = [];

        foreach ($catalog->all() as $entry) {
            $id = $entry['id'];
            if ($onlyRule !== null && $id !== (string) $onlyRule) {
                continue;
            }
            if (!isset($fixers[$id])) {
                $manualRules[] = $entry;
                continue;
            }

            $io->section(sprintf('Regra: %s', $id));
            $fixer = $fixers[$id]();
            $start = microtime(true);
            $result = $fixer->fix($projectRoot, $dryRun);
            $elapsed = microtime(true) - $start;

            if ($output->isVerbose()) {
                foreach ($result->affectedFiles as $row) {
                    $io->writeln(sprintf('%s %s (%d)', $dryRun ? '[would-fix]' : '[fix]', $row['path'], $row['count']));
                }
            }

            $io->writeln(sprintf(
                '  arquivos afetados: %d | ocorrencias: %d | erros: %d | %.1fs',
                $result->filesAffected,
                $result->tagsReplaced,
                $result->filesWithErrors,
                $elapsed
            ));
            $summaryRows[] = [$id, $result->filesAffected, $result->tagsReplaced];
        }

        if ($summaryRows !== []) {
            $io->section('Resumo' . ($dryRun ? ' (DRY-RUN, nenhum disco gravado)' : ''));
            $io->table(['Regra', 'Arquivos', 'Ocorrencias'], $summaryRows);
        }

        if ($manualRules !== []) {
            $io->section('Regras com fix manual (nao aplicadas)');
            foreach ($manualRules as $entry) {
                $descricao = $entry['fix']['descricao'] ?? $entry['prevencao'];
                $io->writeln(sprintf('  <comment>%s</comment>: %s', $entry['id'], trim((string) $descricao)));
            }
        }

        return Command::SUCCESS;
    }
}
