<?php

declare(strict_types=1);

namespace EduDeps\Output;

use EduDeps\Resolver\Unresolved;

final class UnresolvedReportWriter
{
    /**
     * @param list<Unresolved> $unresolved
     */
    public function write(array $unresolved, string $outputDir): string
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Falha ao criar %s', $outputDir));
        }

        $file = $outputDir . '/unresolved.csv';
        $fh = fopen($file, 'w');
        if ($fh === false) {
            throw new \RuntimeException('Falha ao abrir ' . $file);
        }

        fputcsv($fh, ['sourceFile', 'line', 'reason', 'snippet']);
        foreach ($unresolved as $u) {
            fputcsv($fh, [$u->sourceFile, (string) $u->line, $u->reason, $u->snippet]);
        }
        fclose($fh);
        return $file;
    }
}
