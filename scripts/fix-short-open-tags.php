#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Wrapper standalone do EduDeps\Fixer\ShortTagsFixer.
 *
 * Mantido para backward compat com invocacoes diretas via
 *   php scripts/fix-short-open-tags.php <project-root> [--dry-run]
 *
 * A logica real esta em src/Fixer/ShortTagsFixer.php e e usada tambem pelo
 * comando CLI `bin/edu-deps fix-short-tags`.
 */

require __DIR__ . '/../vendor/autoload.php';

use EduDeps\Fixer\ShortTagsFixer;

if ($argc < 2) {
    fwrite(STDERR, "Uso: php fix-short-open-tags.php <project-root> [--dry-run]\n");
    exit(1);
}

$projectRoot = $argv[1];
$dryRun = in_array('--dry-run', $argv, true);

if (!is_dir($projectRoot)) {
    fwrite(STDERR, "Diretorio nao existe: $projectRoot\n");
    exit(1);
}

$result = (new ShortTagsFixer())->fix($projectRoot, $dryRun);

foreach ($result->affectedFiles as $row) {
    $tag = $dryRun ? '[would-fix]' : '[fix]';
    echo "$tag {$row['path']} ({$row['count']} tag(s))\n";
}

echo "\n=== Resumo ===\n";
echo "Arquivos escaneados: {$result->filesScanned}\n";
echo "Arquivos pulados (vendor/etc): {$result->filesSkipped}\n";
echo "Arquivos modificados: {$result->filesAffected}" . ($dryRun ? ' (DRY-RUN)' : '') . "\n";
echo "Short tags substituidos: {$result->tagsReplaced}\n";
echo "Erros de leitura: {$result->filesWithErrors}\n";
