<?php

declare(strict_types=1);

namespace EduDeps\Tests\unit;

use EduDeps\Graph\CycleDetector;
use EduDeps\Graph\DependencyGraph;
use EduDeps\Graph\TopologicalSorter;
use PHPUnit\Framework\TestCase;

final class CycleDetectorTest extends TestCase
{
    public function test_detects_simple_cycle(): void
    {
        $graph = new DependencyGraph();
        $graph->addEdge('a', 'b', 1, 'require');
        $graph->addEdge('b', 'a', 1, 'require');

        $sccs = (new CycleDetector())->detect($graph);
        $multiNodeSccs = array_filter($sccs, static fn ($s) => count($s) > 1);

        $this->assertCount(1, $multiNodeSccs);
        $component = array_values($multiNodeSccs)[0];
        sort($component);
        $this->assertSame(['a', 'b'], $component);
    }

    public function test_no_cycle_in_dag(): void
    {
        $graph = new DependencyGraph();
        $graph->addEdge('a', 'b', 1, 'require');
        $graph->addEdge('b', 'c', 1, 'require');
        $graph->addEdge('a', 'c', 1, 'require');

        $sccs = (new CycleDetector())->detect($graph);
        foreach ($sccs as $scc) {
            $this->assertCount(1, $scc);
        }
    }

    public function test_topological_order_dependencies_first(): void
    {
        $graph = new DependencyGraph();
        $graph->addEdge('a', 'b', 1, 'require');
        $graph->addEdge('b', 'c', 1, 'require');
        $graph->addEdge('a', 'c', 1, 'require');

        $sccs = (new CycleDetector())->detect($graph);
        $order = (new TopologicalSorter())->sort($graph, $sccs);

        $posA = array_search('a', $order, true);
        $posB = array_search('b', $order, true);
        $posC = array_search('c', $order, true);

        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertNotFalse($posC);
        $this->assertLessThan($posB, $posC, 'c deve preceder b (c e dep de b)');
        $this->assertLessThan($posA, $posB, 'b deve preceder a (b e dep de a)');
    }
}
