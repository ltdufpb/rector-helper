<?php

declare(strict_types=1);

namespace EduDeps\Cli\Command;

use EduDeps\Config\Overrides;
use EduDeps\Fixer\ShortTagsFixer;
use EduDeps\Graph\CycleDetector;
use EduDeps\Graph\TopologicalSorter;
use EduDeps\Menu\MenuMapSeedCollector;
use EduDeps\Output\JsonWriter;
use EduDeps\Output\MermaidWriter;
use EduDeps\Output\UnresolvedReportWriter;
use EduDeps\Parser\AstCache;
use EduDeps\Parser\EncodingLoader;
use EduDeps\Resolver\ClassMapBuilder;
use EduDeps\Resolver\DependencyResolver;
use EduDeps\Resolver\PathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ScanCommand extends Command
{
    protected static $defaultName = 'scan';

    protected function configure(): void
    {
        $this
            ->setDescription('Resolve o grafo de dependencias a partir dos seeds do recorte Educacao (F1+F2: BFS + Tarjan + Mermaid).')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Raiz do projeto e-cidade')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Arquivo unico para analisar (relativo ao project-root)')
            ->addOption(
                'seeds-glob',
                null,
                InputOption::VALUE_REQUIRED,
                'Padrao(s) glob para seeds, separados por virgula',
                'edu*.php,mer*.php,func_mer_*.php,func_atendrequi.php,func_matestoquetransf*.php,func_matestoqueini*.php,func_matpedido*.php,mat1_matrequi*.php,mat1_matestoqueini*.php,mat4_matpedido002.php,mat4_matpedido003.php,mat1_matpedidoitem001.php'
            )
            ->addOption('menu-map', null, InputOption::VALUE_REQUIRED, 'HTML do mapa de menus para adicionar seeds de telas abertas diretamente')
            ->addOption('menu-area', null, InputOption::VALUE_REQUIRED, 'Filtro de area do mapa de menus', 'EDU')
            ->addOption('menu-module', null, InputOption::VALUE_REQUIRED, 'Filtro de modulo/submodulo do mapa de menus')
            ->addOption('menu-path-contains', null, InputOption::VALUE_REQUIRED, 'Filtro parcial do caminho de menu, ex.: Procedimentos')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Pasta de saida (default: ./out)', null)
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Pasta de cache (default: ./cache; passe "" para desabilitar)', null)
            ->addOption('skip-classmap', null, InputOption::VALUE_NONE, 'Pula construcao do classmap (apenas modification + includes literais)')
            ->addOption('overrides', null, InputOption::VALUE_REQUIRED, 'Arquivo YAML de overrides (default: ./config/overrides.yaml)', null)
            ->addOption('report', null, InputOption::VALUE_OPTIONAL, 'Grava metricas detalhadas em arquivo JSON (default: out/metrics.json se a flag aparecer sem valor)', false)
            ->addOption('skip-short-tags-check', null, InputOption::VALUE_NONE, 'Pula a pre-verificacao de short open tags pendentes')
            ->addOption('abort-on-short-tags', null, InputOption::VALUE_NONE, 'Aborta com exit 1 se houver short tags pendentes (CI-friendly)');
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

        $outputDir = $input->getOption('output-dir');
        if ($outputDir === null) {
            $outputDir = dirname(__DIR__, 3) . '/out';
        }
        $outputDir = PathResolver::normalize((string) $outputDir);

        $cacheDirOption = $input->getOption('cache-dir');
        if ($cacheDirOption === null) {
            $cacheDir = dirname(__DIR__, 3) . '/cache';
        } elseif ($cacheDirOption === '') {
            $cacheDir = null;
        } else {
            $cacheDir = PathResolver::normalize((string) $cacheDirOption);
        }

        $seeds = $this->collectSeeds($input, $projectRoot, $io);
        if ($seeds === null) {
            return Command::FAILURE;
        }

        // Pre-check: short open tags pendentes (Rector nao trata; rule da edu-deps).
        if (!$input->getOption('skip-short-tags-check')) {
            $detection = (new ShortTagsFixer())->detect($projectRoot);
            if ($detection->filesAffected > 0) {
                $io->warning(sprintf(
                    'Short open tags pendentes em %d arquivo(s) (%d tag(s) totais). '
                    . 'O Rector nao trata short tags — rode `bin/edu-deps fix-short-tags '
                    . '--project-root <path>` antes do scan para evitar parse errors em runtime.',
                    $detection->filesAffected,
                    $detection->tagsReplaced
                ));
                if ($input->getOption('abort-on-short-tags')) {
                    $io->error('Abortando devido a --abort-on-short-tags.');
                    return Command::FAILURE;
                }
            }
        }

        $io->title('edu-deps scan');
        $io->writeln(sprintf('Project root: <info>%s</info>', $projectRoot));
        $io->writeln(sprintf('Seeds:        <info>%d</info>', count($seeds)));
        $io->writeln(sprintf('Output dir:   <info>%s</info>', $outputDir));
        $io->newLine();

        $encodingLoader = new EncodingLoader($cacheDir);
        $astCache = new AstCache($cacheDir);

        $overridesFile = $input->getOption('overrides');
        if ($overridesFile === null) {
            $overridesFile = dirname(__DIR__, 3) . '/config/overrides.yaml';
        }
        $overrides = Overrides::loadFromFile($overridesFile);

        $classMap = null;
        if (!$input->getOption('skip-classmap')) {
            $io->writeln('<comment>Construindo classmap...</comment>');
            $builderStart = microtime(true);
            $builder = new ClassMapBuilder($encodingLoader, null, $cacheDir);
            $classMap = $builder->build($projectRoot);
            $io->writeln(sprintf(
                'Classmap: %d classes em %.2fs.',
                $classMap->size(),
                microtime(true) - $builderStart
            ));
        }

        $start = microtime(true);
        $resolver = new DependencyResolver(
            new PathResolver($projectRoot, $classMap, $overrides),
            null,
            $encodingLoader,
            $astCache
        );
        $graph = $resolver->resolveAll($seeds);
        $unresolved = $resolver->getUnresolved();

        $sccs = (new CycleDetector())->detect($graph);
        $order = (new TopologicalSorter())->sort($graph, $sccs);
        $elapsed = microtime(true) - $start;

        $jsonWriter = new JsonWriter();
        $graphFile = $jsonWriter->writeGraph($graph, $unresolved, $sccs, $order, $outputDir);
        $filesFile = $jsonWriter->writeFilesList($order, $outputDir);
        $mermaidFile = (new MermaidWriter())->write($graph, $outputDir, $projectRoot);

        $csvFile = null;
        if ($unresolved !== []) {
            $csvFile = (new UnresolvedReportWriter())->write($unresolved, $outputDir);
        }

        $cycleSccs = array_values(array_filter($sccs, static fn ($s) => count($s) > 1));

        $io->section('Resumo');
        $io->definitionList(
            ['Nos no grafo' => (string) $graph->nodeCount()],
            ['Arestas' => (string) $graph->edgeCount()],
            ['SCCs com ciclo' => (string) count($cycleSccs)],
            ['Nao-resolvidos' => (string) count($unresolved)],
            ['AST cache hits' => (string) $resolver->getCacheHits()],
            ['AST cache misses' => (string) $resolver->getCacheMisses()],
            ['Tempo (s)' => sprintf('%.3f', $elapsed)],
            ['Pico RAM (MB)' => sprintf('%.1f', memory_get_peak_usage(true) / 1024 / 1024)]
        );

        if ($cycleSccs !== []) {
            $io->section(sprintf('Ciclos detectados: %d SCC(s)', count($cycleSccs)));
            foreach ($cycleSccs as $i => $component) {
                $io->writeln(sprintf('<comment>SCC %d (%d nos):</comment>', $i + 1, count($component)));
                foreach ($component as $node) {
                    $io->writeln('  - ' . $node);
                }
            }
        }

        $io->section('Saidas');
        $io->writeln(sprintf('graph.json:    <info>%s</info>', $graphFile));
        $io->writeln(sprintf('files.txt:     <info>%s</info>', $filesFile));
        $io->writeln(sprintf('graph.mmd:     <info>%s</info>', $mermaidFile));
        if ($csvFile !== null) {
            $io->writeln(sprintf('unresolved.csv:<info>%s</info>', $csvFile));
        }

        $reportOption = $input->getOption('report');
        if ($reportOption !== false) {
            $reportFile = is_string($reportOption) && $reportOption !== ''
                ? $reportOption
                : $outputDir . '/metrics.json';
            $this->writeMetrics($reportFile, [
                'project_root' => $projectRoot,
                'seeds' => count($seeds),
                'nodes' => $graph->nodeCount(),
                'edges' => $graph->edgeCount(),
                'sccs_with_cycle' => count($cycleSccs),
                'unresolved' => count($unresolved),
                'cache_hits' => $resolver->getCacheHits(),
                'cache_misses' => $resolver->getCacheMisses(),
                'classmap_size' => $classMap !== null ? $classMap->size() : 0,
                'elapsed_seconds' => $elapsed,
                'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
                'generated_at' => date('c'),
            ]);
            $io->writeln(sprintf('metrics.json:  <info>%s</info>', $reportFile));
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $metrics */
    private function writeMetrics(string $file, array $metrics): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($file, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<string>|null
     */
    private function collectSeeds(InputInterface $input, string $projectRoot, SymfonyStyle $io): ?array
    {
        $file = $input->getOption('file');
        if ($file !== null) {
            $absolute = $projectRoot . '/' . ltrim(PathResolver::normalize((string) $file), '/');
            if (!is_file($absolute)) {
                $io->error(sprintf('Arquivo nao existe: %s', $absolute));
                return null;
            }
            return [PathResolver::normalize(realpath($absolute) ?: $absolute)];
        }

        $globs = $this->parseSeedGlobs((string) $input->getOption('seeds-glob'));
        $matches = [];
        foreach ($globs as $glob) {
            foreach (glob($projectRoot . '/' . $glob) ?: [] as $match) {
                $matches[$match] = true;
            }
        }
        $menuSeeds = $this->collectMenuSeeds($input, $projectRoot, $io);
        foreach ($menuSeeds as $seed) {
            $matches[$seed] = true;
        }

        $matches = array_keys($matches);
        if ($matches === []) {
            $io->error(sprintf('Nenhum seed encontrado para o(s) padrao(s) %s em %s', implode(', ', $globs), $projectRoot));
            return null;
        }

        $seeds = [];
        foreach ($matches as $match) {
            $real = realpath($match);
            if ($real !== false && is_file($real)) {
                $seeds[] = PathResolver::normalize($real);
            }
        }
        return $seeds;
    }

    /**
     * @return list<string>
     */
    private function collectMenuSeeds(InputInterface $input, string $projectRoot, SymfonyStyle $io): array
    {
        $menuMap = $input->getOption('menu-map');
        if ($menuMap === null || trim((string) $menuMap) === '') {
            return [];
        }

        $mapFile = PathResolver::normalize((string) $menuMap);
        $seeds = (new MenuMapSeedCollector())->collect(
            $mapFile,
            $projectRoot,
            (string) $input->getOption('menu-area'),
            $input->getOption('menu-module') !== null ? (string) $input->getOption('menu-module') : null,
            $input->getOption('menu-path-contains') !== null ? (string) $input->getOption('menu-path-contains') : null
        );

        if ($seeds === []) {
            $io->warning(sprintf('Mapa de menus nao adicionou seeds: %s', $mapFile));
            return [];
        }

        $io->writeln(sprintf('<comment>Mapa de menus adicionou %d seed(s).</comment>', count($seeds)));
        return $seeds;
    }

    /** @return list<string> */
    private function parseSeedGlobs(string $option): array
    {
        $globs = array_values(array_filter(
            array_map('trim', explode(',', $option)),
            static fn (string $glob): bool => $glob !== ''
        ));

        return $globs !== [] ? $globs : [
            'edu*.php',
            'mer*.php',
            'func_mer_*.php',
            'func_atendrequi.php',
            'func_matestoquetransf*.php',
            'func_matestoqueini*.php',
            'func_matpedido*.php',
            'mat1_matrequi*.php',
            'mat1_matestoqueini*.php',
            'mat4_matpedido002.php',
            'mat4_matpedido003.php',
            'mat1_matpedidoitem001.php',
        ];
    }
}
