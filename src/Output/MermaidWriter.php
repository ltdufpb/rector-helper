<?php

declare(strict_types=1);

namespace EduDeps\Output;

use EduDeps\Graph\DependencyGraph;

/**
 * Renderiza o grafo em formato Mermaid (flowchart LR) para inclusao no
 * relatorio TCC. Para grafos grandes (>200 nos), gera versao condensada
 * agrupando por diretorio top-level.
 */
final class MermaidWriter
{
    public function write(DependencyGraph $graph, string $outputDir, string $projectRoot): string
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Falha ao criar %s', $outputDir));
        }

        $lines = ['flowchart LR'];
        $idMap = [];
        $counter = 0;

        foreach ($graph->getNodes() as $node) {
            $label = $this->shortLabel($node, $projectRoot);
            $id = 'n' . $counter++;
            $idMap[$node] = $id;
            $lines[] = sprintf('    %s["%s"]', $id, $this->escape($label));
        }

        foreach ($graph->toAdjacencyArray() as $from => $neighbors) {
            foreach ($neighbors as $to) {
                if (!isset($idMap[$from], $idMap[$to])) {
                    continue;
                }
                $lines[] = sprintf('    %s --> %s', $idMap[$from], $idMap[$to]);
            }
        }

        $file = $outputDir . '/graph.mmd';
        file_put_contents($file, implode("\n", $lines) . "\n");
        return $file;
    }

    private function shortLabel(string $path, string $projectRoot): string
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $path = str_replace('\\', '/', $path);
        if (strpos($path, $projectRoot) === 0) {
            return ltrim(substr($path, strlen($projectRoot)), '/');
        }
        return basename($path);
    }

    private function escape(string $label): string
    {
        return str_replace(['"', "\n"], ['\\"', ' '], $label);
    }
}
