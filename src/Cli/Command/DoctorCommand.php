<?php

declare(strict_types=1);

namespace EduDeps\Cli\Command;

use EduDeps\Config\Overrides;
use EduDeps\Parser\EncodingLoader;
use EduDeps\Resolver\ClassMapBuilder;
use EduDeps\Resolver\PathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Comando interativo: le um unresolved.csv gerado por um scan anterior,
 * para cada classe nao resolvida sugere candidatos do classmap e grava as
 * decisoes do usuario em overrides.yaml.
 */
final class DoctorCommand extends Command
{
    protected static $defaultName = 'doctor';

    protected function configure(): void
    {
        $this
            ->setDescription('Modo interativo: popular overrides.yaml a partir de unresolved.csv.')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Raiz do projeto e-cidade')
            ->addOption('unresolved', null, InputOption::VALUE_REQUIRED, 'Caminho do unresolved.csv', null)
            ->addOption('overrides', null, InputOption::VALUE_REQUIRED, 'Arquivo overrides.yaml a atualizar', null)
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Pasta de cache do classmap', null);
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

        $unresolvedFile = $input->getOption('unresolved') ?? dirname(__DIR__, 3) . '/out/unresolved.csv';
        if (!is_file($unresolvedFile)) {
            $io->error(sprintf('Arquivo nao existe: %s', $unresolvedFile));
            return Command::FAILURE;
        }

        $overridesFile = $input->getOption('overrides') ?? dirname(__DIR__, 3) . '/config/overrides.yaml';
        $overrides = Overrides::loadFromFile($overridesFile);

        $cacheDir = $input->getOption('cache-dir') ?? dirname(__DIR__, 3) . '/cache';

        $io->writeln('<comment>Construindo classmap...</comment>');
        $classMap = (new ClassMapBuilder(new EncodingLoader($cacheDir), null, $cacheDir))->build($projectRoot);
        $io->writeln(sprintf('Classmap: %d classes.', $classMap->size()));

        $unresolvedClasses = $this->collectUnresolvedClasses($unresolvedFile);
        if ($unresolvedClasses === []) {
            $io->success('Nenhum unresolved do tipo classe encontrado no arquivo.');
            return Command::SUCCESS;
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $changed = 0;
        foreach ($unresolvedClasses as $className) {
            $candidates = $classMap->findByName($className);
            if ($candidates === []) {
                $io->writeln(sprintf('<comment>%s</comment>: nenhum candidato no classmap. Pulando.', $className));
                continue;
            }
            $candidates[] = '[pular]';
            $question = new ChoiceQuestion(
                sprintf('Escolha o arquivo para <info>%s</info>:', $className),
                $candidates,
                count($candidates) - 1
            );
            $chosen = $helper->ask($input, $output, $question);
            if ($chosen === '[pular]') {
                continue;
            }
            $relative = $this->makeRelative((string) $chosen, $projectRoot);
            $overrides->setClass($className, $relative);
            $changed++;
        }

        if ($changed === 0) {
            $io->writeln('Nenhuma alteracao feita.');
            return Command::SUCCESS;
        }

        $overrides->save($overridesFile);
        $io->success(sprintf('%d override(s) gravado(s) em %s', $changed, $overridesFile));
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collectUnresolvedClasses(string $csvFile): array
    {
        $fh = fopen($csvFile, 'r');
        if ($fh === false) {
            return [];
        }
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            return [];
        }
        $reasonIdx = array_search('reason', $header, true);
        $snippetIdx = array_search('snippet', $header, true);
        if ($reasonIdx === false || $snippetIdx === false) {
            fclose($fh);
            return [];
        }

        $seen = [];
        while (($row = fgetcsv($fh)) !== false) {
            if ($row[$reasonIdx] !== 'class_not_in_map') {
                continue;
            }
            $snippet = $row[$snippetIdx];
            if (preg_match('/^(?:new|static_call)\s+(\S+)/', $snippet, $m)) {
                $seen[$m[1]] = true;
            }
        }
        fclose($fh);
        return array_keys($seen);
    }

    private function makeRelative(string $path, string $projectRoot): string
    {
        $path = PathResolver::normalize($path);
        $projectRoot = rtrim($projectRoot, '/');
        if (strpos($path, $projectRoot) === 0) {
            return ltrim(substr($path, strlen($projectRoot)), '/');
        }
        return $path;
    }
}
