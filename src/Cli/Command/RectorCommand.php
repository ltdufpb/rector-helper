<?php

declare(strict_types=1);

namespace EduDeps\Cli\Command;

use EduDeps\Output\RectorConfigWriter;
use EduDeps\Resolver\PathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le out/graph.json gerado por um scan anterior e produz
 * out/rector-generated.php — clone do rector.php base com withPaths() substituido
 * pela lista topologica de arquivos.
 *
 * Modos:
 *   --mode=paths   (default) so emite out/files.txt (ja existe no scan)
 *   --mode=config  emite out/rector-generated.php usando rector.php base
 */
final class RectorCommand extends Command
{
    protected static $defaultName = 'rector';

    protected function configure(): void
    {
        $this
            ->setDescription('Gera rector-generated.php a partir do grafo (F6).')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Raiz do projeto e-cidade')
            ->addOption('graph', null, InputOption::VALUE_REQUIRED, 'Caminho do graph.json (default: ./out/graph.json)', null)
            ->addOption('rector-base', null, InputOption::VALUE_REQUIRED, 'rector.php base (default: <project-root>/rector.php)', null)
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Pasta de saida (default: ./out)', null)
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'paths | config', 'config');
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

        $outputDir = $input->getOption('output-dir') ?? dirname(__DIR__, 3) . '/out';
        $outputDir = PathResolver::normalize((string) $outputDir);

        $graphFile = $input->getOption('graph') ?? $outputDir . '/graph.json';
        if (!is_file($graphFile)) {
            $io->error(sprintf('graph.json nao existe: %s', $graphFile));
            return Command::FAILURE;
        }

        $rectorBase = $input->getOption('rector-base') ?? $projectRoot . '/rector.php';
        if (!is_file($rectorBase)) {
            $io->error(sprintf('rector.php base nao existe: %s', $rectorBase));
            return Command::FAILURE;
        }

        $mode = (string) $input->getOption('mode');
        $jsonRaw = (string) file_get_contents($graphFile);
        $data = json_decode($jsonRaw, true);
        if (!is_array($data) || !isset($data['topological_order']) || !is_array($data['topological_order'])) {
            $io->error('graph.json invalido: falta topological_order.');
            return Command::FAILURE;
        }
        $paths = array_values(array_map('strval', $data['topological_order']));

        if ($mode === 'paths') {
            $io->writeln(sprintf('Modo paths: a lista esta em <info>%s/files.txt</info>', $outputDir));
            return Command::SUCCESS;
        }

        $writer = new RectorConfigWriter();
        $generated = $writer->write($rectorBase, $paths, $outputDir, $projectRoot);

        $io->success(sprintf('Gerado: %s', $generated));
        $io->writeln('Para rodar:');
        $io->writeln(sprintf(
            '  cd %s && vendor\\bin\\rector process --config=%s',
            $projectRoot,
            $generated
        ));
        return Command::SUCCESS;
    }
}
