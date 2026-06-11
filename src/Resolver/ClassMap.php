<?php

declare(strict_types=1);

namespace EduDeps\Resolver;

/**
 * Estrutura imutavel de mapeamento nome-de-classe -> arquivo.
 *
 * - byName: nome curto -> lista de paths (pode haver homonimos em namespaces distintos)
 * - byFqcn: FQCN -> path unico
 */
final class ClassMap
{
    /** @var array<string, list<string>> */
    private array $byName;

    /** @var array<string, string> */
    private array $byFqcn;

    /**
     * @param array<string, list<string>> $byName
     * @param array<string, string> $byFqcn
     */
    public function __construct(array $byName, array $byFqcn)
    {
        $this->byName = $byName;
        $this->byFqcn = $byFqcn;
    }

    /**
     * @return list<string> caminhos absolutos
     */
    public function findByName(string $shortName): array
    {
        return $this->byName[$shortName] ?? [];
    }

    public function findByFqcn(string $fqcn): ?string
    {
        return $this->byFqcn[$fqcn] ?? null;
    }

    /** @return array<string, list<string>> */
    public function getByName(): array
    {
        return $this->byName;
    }

    /** @return array<string, string> */
    public function getByFqcn(): array
    {
        return $this->byFqcn;
    }

    public function size(): int
    {
        return count($this->byName);
    }
}
