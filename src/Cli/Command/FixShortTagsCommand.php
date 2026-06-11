<?php

declare(strict_types=1);

namespace EduDeps\Cli\Command;

use EduDeps\Fixer\ShortTagsFixer;
use EduDeps\Resolver\PathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Comando CLI para corrigir short open tags PHP 4 (`<?` → `<?php`).
 *
 * O Rector nao trata short open tags porque sao problema PRE-parse (o
 * runtime PHP nem reconhece o arquivo como PHP). Esta tarefa fica com a
 * edu-deps como "tratador de problemas pre-parse que o Rector nao cobre".
 *
 * Modos:
 *  - default: aplica o fix em disco
 *  - --dry-run: so reporta o que faria
 *  - --strict: exit 1 se houver short tags pendentes (util para CI)
 */
final class FixShortTagsCommand extends Command
{
    protected static $defaultName = 'fix-short-tags';

    protected function configure(): void
    {
        $this
            ->setDescription('Substitui `<?` por `<?php` em todo o project-root, exceto vendor/node_modules.')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Raiz do projeto PHP legado')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'So reporta, nao modifica disco')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 1 se houver short tags pendentes');
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

        $dryRun = (bool) $input->getOption('dry-run');
        $strict = (bool) $input->getOption('strict');

        $io->title('edu-deps fix-short-tags' . ($dryRun ? ' (dry-run)' : ''));
        $io->writeln(sprintf('Project root: <info>%s</info>', $projectRoot));

        $fixer = new ShortTagsFixer();
        $start = microtime(true);
        $result = $fixer->fix($projectRoot, $dryRun);
        $elapsed = microtime(true) - $start;

        if ($output->isVerbose() && $result->affectedFiles !== []) {
            $io->section('Arquivos afetados');
            foreach ($result->affectedFiles as $row) {
                $tag = $dryRun ? '[would-fix]' : '[fix]';
                $io->writeln(sprintf('%s %s (%d tag(s))', $tag, $row['path'], $row['count']));
            }
        }

        $io->section('Resumo');
        $io->definitionList(
            ['Arquivos escaneados' => (string) $result->filesScanned],
            ['Arquivos pulados (vendor/etc)' => (string) $result->filesSkipped],
            ['Arquivos afetados' => (string) $result->filesAffected . ($dryRun ? ' (DRY-RUN)' : '')],
            ['Short tags substituidos' => (string) $result->tagsReplaced],
            ['Erros de leitura' => (string) $result->filesWithErrors],
            ['Tempo (s)' => sprintf('%.3f', $elapsed)]
        );

        if ($strict && $result->filesAffected > 0) {
            $io->error(sprintf(
                '%d arquivo(s) com short tags pendentes — modo --strict aborta com exit 1.',
                $result->filesAffected
            ));
            return Command::FAILURE;
        }

        if ($result->filesAffected === 0) {
            $io->success('Nenhum short tag encontrado. Projeto limpo.');
        } elseif ($dryRun) {
            $io->warning(sprintf(
                'Rode novamente sem --dry-run para corrigir os %d arquivo(s).',
                $result->filesAffected
            ));
        } else {
            $io->success(sprintf(
                '%d arquivo(s) corrigido(s) (%d tag(s) substituidos).',
                $result->filesAffected,
                $result->tagsReplaced
            ));
        }

        return Command::SUCCESS;
    }
}
