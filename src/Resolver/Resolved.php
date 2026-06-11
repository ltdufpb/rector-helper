<?php

declare(strict_types=1);

namespace EduDeps\Resolver;

/**
 * Resultado da tentativa de resolver uma dependencia em arquivo concreto.
 *
 * Um Resolved sempre carrega o path absoluto normalizado. Um Unresolved carrega
 * o motivo e um snippet textual para diagnostico.
 */
final class Resolved
{
    public string $absolutePath;
    public float $confidence;
    public string $sourceType;

    public function __construct(string $absolutePath, string $sourceType, float $confidence = 1.0)
    {
        $this->absolutePath = $absolutePath;
        $this->sourceType = $sourceType;
        $this->confidence = $confidence;
    }
}
