<?php

declare(strict_types=1);

namespace EduDeps\Graph;

/**
 * Ordenacao topologica via Kahn sobre o DAG condensado de SCCs.
 *
 * Cada SCC vira um macro-no; aresta entre macro-nos existe se ha aresta
 * original entre membros de SCCs distintas. Dentro de cada SCC, a ordem
 * dos nos e lexicografica (deterministica).
 */
final class TopologicalSorter
{
    /**
     * @param list<list<string>> $sccs
     * @return list<string> ordem topologica (dependencias antes dos dependentes)
     */
    public function sort(DependencyGraph $graph, array $sccs): array
    {
        $sccIndex = CycleDetector::sccIndex($sccs);
        $sccCount = count($sccs);

        $condensedAdj = array_fill(0, $sccCount, []);
        $inDegree = array_fill(0, $sccCount, 0);

        foreach ($graph->toAdjacencyArray() as $from => $neighbors) {
            foreach ($neighbors as $to) {
                $fromScc = $sccIndex[$from] ?? null;
                $toScc = $sccIndex[$to] ?? null;
                if ($fromScc === null || $toScc === null || $fromScc === $toScc) {
                    continue;
                }
                if (!isset($condensedAdj[$fromScc][$toScc])) {
                    $condensedAdj[$fromScc][$toScc] = true;
                    $inDegree[$toScc]++;
                }
            }
        }

        $queue = [];
        for ($i = 0; $i < $sccCount; $i++) {
            if ($inDegree[$i] === 0) {
                $queue[] = $i;
            }
        }
        sort($queue);

        $order = [];
        while ($queue !== []) {
            $currentScc = array_shift($queue);
            $members = $sccs[$currentScc];
            sort($members);
            foreach ($members as $member) {
                $order[] = $member;
            }

            $nextBatch = [];
            foreach (array_keys($condensedAdj[$currentScc]) as $neighborScc) {
                $inDegree[$neighborScc]--;
                if ($inDegree[$neighborScc] === 0) {
                    $nextBatch[] = $neighborScc;
                }
            }
            sort($nextBatch);
            foreach ($nextBatch as $scc) {
                $queue[] = $scc;
            }
        }

        $order = array_reverse($order);
        return $order;
    }
}
