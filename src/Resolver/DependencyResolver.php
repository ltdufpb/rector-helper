<?php

declare(strict_types=1);

namespace EduDeps\Resolver;

use EduDeps\Graph\DependencyGraph;
use EduDeps\Parser\AstCache;
use EduDeps\Parser\EncodingLoader;
use EduDeps\Parser\ParserFactory;
use EduDeps\Parser\Visitor\DependencyVisitor;
use PhpParser\NodeTraverser;
use PhpParser\Parser;

/**
 * Orquestrador BFS: parte de seeds, parseia cada arquivo (via EncodingLoader
 * + AstCache), extrai dependencias via DependencyVisitor, resolve cada alvo
 * via PathResolver e enfileira descobertas.
 */
final class DependencyResolver
{
    private PathResolver $pathResolver;
    private Parser $parser;
    private EncodingLoader $encodingLoader;
    private AstCache $astCache;

    /** @var array<string, true> */
    private array $visited = [];

    /** @var list<Unresolved> */
    private array $unresolved = [];

    private int $cacheHits = 0;
    private int $cacheMisses = 0;

    public function __construct(
        PathResolver $pathResolver,
        ?Parser $parser = null,
        ?EncodingLoader $encodingLoader = null,
        ?AstCache $astCache = null
    ) {
        $this->pathResolver = $pathResolver;
        $this->parser = $parser ?? ParserFactory::create();
        $this->encodingLoader = $encodingLoader ?? new EncodingLoader();
        $this->astCache = $astCache ?? new AstCache();
    }

    private function applyOverrides(string $absolutePath, DependencyGraph $graph, array &$queue): void
    {
        $overrides = $this->pathResolver->getOverrides();
        if ($overrides === null) {
            return;
        }
        $extras = $overrides->extrasForSourceFile($absolutePath, $this->pathResolver->getProjectRoot());
        foreach ($extras as $extra) {
            $resolved = $this->pathResolver->resolveLiteral($extra, 'override_extra', $absolutePath);
            if ($resolved === null) {
                $this->unresolved[] = new Unresolved($absolutePath, 0, 'override_target_missing', $extra);
                continue;
            }
            $this->enqueueEdge($graph, $absolutePath, $resolved->absolutePath, 0, 'override_extra', $queue);
        }
    }

    /**
     * @param list<string> $seeds paths absolutos dos arquivos seed
     */
    public function resolveAll(array $seeds): DependencyGraph
    {
        $graph = new DependencyGraph();
        $queue = [];

        foreach ($seeds as $seed) {
            $seed = PathResolver::normalize($seed);
            if (isset($this->visited[$seed])) {
                continue;
            }
            $graph->addNode($seed);
            $queue[] = $seed;
            $this->visited[$seed] = true;
        }

        while ($queue !== []) {
            $current = array_shift($queue);
            $this->processFile($current, $graph, $queue);
        }

        return $graph;
    }

    /**
     * @param list<string> $queue passado por referencia para enfileirar descobertas
     */
    private function processFile(string $absolutePath, DependencyGraph $graph, array &$queue): void
    {
        if (!is_file($absolutePath)) {
            return;
        }

        try {
            $loaded = $this->encodingLoader->load($absolutePath);
        } catch (\Throwable $e) {
            $this->unresolved[] = new Unresolved($absolutePath, 0, 'read_error', $e->getMessage());
            return;
        }

        $source = $loaded['source'];

        $sourceHash = sha1($source);
        $ast = $this->astCache->get($sourceHash);
        if ($ast !== null) {
            $this->cacheHits++;
        } else {
            try {
                $ast = $this->parser->parse($source);
            } catch (\Throwable $e) {
                $this->unresolved[] = new Unresolved($absolutePath, 0, 'parse_error', $e->getMessage());
                return;
            }
            if ($ast === null) {
                return;
            }
            $this->astCache->put($sourceHash, $ast);
            $this->cacheMisses++;
        }

        $visitor = new DependencyVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->getDependencies() as $dep) {
            $resolved = $this->pathResolver->resolveLiteral(
                $dep['target'],
                $dep['type'],
                $absolutePath
            );

            if ($resolved === null) {
                $this->unresolved[] = new Unresolved(
                    $absolutePath,
                    $dep['line'],
                    'file_not_found',
                    sprintf('%s -> %s', $dep['type'], $dep['target'])
                );
                continue;
            }

            $this->enqueueEdge($graph, $absolutePath, $resolved->absolutePath, $dep['line'], $dep['type'], $queue);
        }

        foreach ($visitor->getClassReferences() as $ref) {
            $resolved = $this->pathResolver->resolveClass($ref['name']);
            if ($resolved === null) {
                $this->unresolved[] = new Unresolved(
                    $absolutePath,
                    $ref['line'],
                    'class_not_in_map',
                    sprintf('%s %s', $ref['type'], $ref['name'])
                );
                continue;
            }
            $this->enqueueEdge($graph, $absolutePath, $resolved->absolutePath, $ref['line'], $ref['type'], $queue);
        }

        foreach ($visitor->getUseStatements() as $use) {
            $resolved = $this->pathResolver->resolveFqcn($use['fqcn']);
            if ($resolved === null) {
                $this->unresolved[] = new Unresolved(
                    $absolutePath,
                    $use['line'],
                    'use_not_in_map',
                    'use ' . $use['fqcn']
                );
                continue;
            }
            $this->enqueueEdge($graph, $absolutePath, $resolved->absolutePath, $use['line'], 'use_namespace', $queue);
        }

        foreach ($visitor->getUnresolved() as $u) {
            $this->unresolved[] = new Unresolved(
                $absolutePath,
                $u['line'],
                $u['reason'],
                $u['snippet']
            );
        }

        $this->applyOverrides($absolutePath, $graph, $queue);
    }

    /**
     * @param list<string> $queue
     */
    private function enqueueEdge(
        DependencyGraph $graph,
        string $from,
        string $to,
        int $line,
        string $sourceType,
        array &$queue
    ): void {
        $graph->addEdge($from, $to, $line, $sourceType);
        if (!isset($this->visited[$to])) {
            $this->visited[$to] = true;
            $queue[] = $to;
        }
    }

    /** @return list<Unresolved> */
    public function getUnresolved(): array
    {
        return $this->unresolved;
    }

    public function getCacheHits(): int
    {
        return $this->cacheHits;
    }

    public function getCacheMisses(): int
    {
        return $this->cacheMisses;
    }
}
