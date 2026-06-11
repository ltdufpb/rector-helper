<?php

declare(strict_types=1);

namespace EduDeps\Graph;

/**
 * Detector de componentes fortemente conexos (SCC) via algoritmo de Tarjan.
 *
 * Devolve a lista de SCCs (cada uma e uma lista de nos). SCCs com mais
 * de um no representam ciclos. Tambem entrega o mapeamento no -> id_da_scc
 * para uso pelo TopologicalSorter.
 */
final class CycleDetector
{
    /** @var array<string, int> */
    private array $index = [];

    /** @var array<string, int> */
    private array $lowLink = [];

    /** @var array<string, bool> */
    private array $onStack = [];

    /** @var list<string> */
    private array $stack = [];

    private int $counter = 0;

    /** @var list<list<string>> */
    private array $sccs = [];

    /** @var array<string, list<string>> */
    private array $adjacency = [];

    /**
     * @return list<list<string>> SCCs em ordem reversa da finalizacao do DFS
     */
    public function detect(DependencyGraph $graph): array
    {
        $this->reset();
        $this->adjacency = $graph->toAdjacencyArray();

        foreach ($graph->getNodes() as $node) {
            if (!isset($this->index[$node])) {
                $this->strongConnect($node);
            }
        }

        return $this->sccs;
    }

    /**
     * Retorna mapa no -> id da SCC (indice na lista retornada por detect()).
     *
     * @param list<list<string>> $sccs
     * @return array<string, int>
     */
    public static function sccIndex(array $sccs): array
    {
        $map = [];
        foreach ($sccs as $id => $component) {
            foreach ($component as $node) {
                $map[$node] = $id;
            }
        }
        return $map;
    }

    private function strongConnect(string $node): void
    {
        $this->index[$node] = $this->counter;
        $this->lowLink[$node] = $this->counter;
        $this->counter++;
        $this->stack[] = $node;
        $this->onStack[$node] = true;

        foreach ($this->adjacency[$node] ?? [] as $neighbor) {
            if (!isset($this->index[$neighbor])) {
                $this->strongConnect($neighbor);
                $this->lowLink[$node] = min($this->lowLink[$node], $this->lowLink[$neighbor]);
            } elseif (!empty($this->onStack[$neighbor])) {
                $this->lowLink[$node] = min($this->lowLink[$node], $this->index[$neighbor]);
            }
        }

        if ($this->lowLink[$node] === $this->index[$node]) {
            $component = [];
            do {
                $popped = array_pop($this->stack);
                if ($popped === null) {
                    break;
                }
                $this->onStack[$popped] = false;
                $component[] = $popped;
            } while ($popped !== $node);
            $this->sccs[] = $component;
        }
    }

    private function reset(): void
    {
        $this->index = [];
        $this->lowLink = [];
        $this->onStack = [];
        $this->stack = [];
        $this->counter = 0;
        $this->sccs = [];
        $this->adjacency = [];
    }
}
