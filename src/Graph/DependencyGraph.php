<?php

declare(strict_types=1);

namespace EduDeps\Graph;

/**
 * Grafo direcionado simples: cada no e um path absoluto, cada aresta
 * representa "A depende de B" (A precisa que B seja carregado antes).
 *
 * Estrutura escolhida (array<string, array<string,bool>>) garante O(1) para
 * adicao de arestas e evita duplicatas sem o custo de SplObjectStorage.
 */
final class DependencyGraph
{
    /** @var array<string, array<string, bool>> */
    private array $adjacency = [];

    /** @var array<string, list<array{from:string,to:string,line:int,sourceType:string}>> */
    private array $edgeMetadata = [];

    public function addNode(string $path): void
    {
        if (!isset($this->adjacency[$path])) {
            $this->adjacency[$path] = [];
        }
    }

    public function addEdge(string $from, string $to, int $line, string $sourceType): void
    {
        $this->addNode($from);
        $this->addNode($to);
        $this->adjacency[$from][$to] = true;
        $this->edgeMetadata[$from][] = [
            'from' => $from,
            'to' => $to,
            'line' => $line,
            'sourceType' => $sourceType,
        ];
    }

    public function hasNode(string $path): bool
    {
        return isset($this->adjacency[$path]);
    }

    /** @return list<string> */
    public function getNodes(): array
    {
        return array_keys($this->adjacency);
    }

    /** @return list<string> */
    public function getNeighbors(string $path): array
    {
        if (!isset($this->adjacency[$path])) {
            return [];
        }
        return array_keys($this->adjacency[$path]);
    }

    public function nodeCount(): int
    {
        return count($this->adjacency);
    }

    public function edgeCount(): int
    {
        $count = 0;
        foreach ($this->adjacency as $neighbors) {
            $count += count($neighbors);
        }
        return $count;
    }

    /** @return array<string, list<array{from:string,to:string,line:int,sourceType:string}>> */
    public function getEdgeMetadata(): array
    {
        return $this->edgeMetadata;
    }

    /** @return array<string, list<string>> */
    public function toAdjacencyArray(): array
    {
        $out = [];
        foreach ($this->adjacency as $from => $neighbors) {
            $out[$from] = array_keys($neighbors);
        }
        return $out;
    }
}
