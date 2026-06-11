<?php

declare(strict_types=1);

namespace EduDeps\Fixer;

/**
 * Resultado de uma operacao de detect() ou fix() executada por um Fixer.
 *
 * Value object simples: stats numericos + flag de dryRun + lista de paths
 * afetados (para output verbose ou logs detalhados).
 */
final class FixResult
{
    public int $filesScanned = 0;
    public int $filesSkipped = 0;
    public int $filesAffected = 0;
    public int $tagsReplaced = 0;
    public int $filesWithErrors = 0;
    public bool $isDryRun = false;

    /** @var list<array{path:string,count:int}> */
    public array $affectedFiles = [];
}
