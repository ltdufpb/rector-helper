<?php

declare(strict_types=1);

namespace EduDeps\Output;

use EduDeps\Graph\DependencyGraph;
use EduDeps\Resolver\Unresolved;

final class JsonWriter
{
    /**
     * @param list<Unresolved> $unresolved
     * @param list<list<string>> $sccs
     * @param list<string> $topologicalOrder
     */
    public function writeGraph(
        DependencyGraph $graph,
        array $unresolved,
        array $sccs,
        array $topologicalOrder,
        string $outputDir
    ): string {
        $this->ensureDir($outputDir);

        $cycleComponents = array_values(array_filter($sccs, static fn ($s) => count($s) > 1));

        $payload = [
            'generated_at' => date('c'),
            'stats' => [
                'nodes' => $graph->nodeCount(),
                'edges' => $graph->edgeCount(),
                'sccs_total' => count($sccs),
                'sccs_with_cycle' => count($cycleComponents),
                'unresolved' => count($unresolved),
            ],
            'nodes' => array_values($graph->getNodes()),
            'edges' => $this->collectEdges($graph),
            'topological_order' => $topologicalOrder,
            'cycles' => $cycleComponents,
            'unresolved' => array_map(static function (Unresolved $u): array {
                return [
                    'sourceFile' => $u->sourceFile,
                    'line' => $u->line,
                    'reason' => $u->reason,
                    'snippet' => $u->snippet,
                ];
            }, $unresolved),
        ];

        $file = $outputDir . '/graph.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Falha ao codificar JSON: ' . json_last_error_msg());
        }
        file_put_contents($file, $json);
        return $file;
    }

    /**
     * @param list<string> $topologicalOrder
     */
    public function writeFilesList(array $topologicalOrder, string $outputDir): string
    {
        $this->ensureDir($outputDir);
        $file = $outputDir . '/files.txt';
        file_put_contents($file, implode(PHP_EOL, $topologicalOrder) . PHP_EOL);
        return $file;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Falha ao criar %s', $dir));
        }
    }

    /** @return list<array{from:string,to:string,line:int,sourceType:string}> */
    private function collectEdges(DependencyGraph $graph): array
    {
        $out = [];
        foreach ($graph->getEdgeMetadata() as $edges) {
            foreach ($edges as $edge) {
                $out[] = $edge;
            }
        }
        return $out;
    }
}
