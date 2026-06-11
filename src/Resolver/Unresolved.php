<?php

declare(strict_types=1);

namespace EduDeps\Resolver;

final class Unresolved
{
    public string $reason;
    public string $snippet;
    public int $line;
    public string $sourceFile;

    public function __construct(string $sourceFile, int $line, string $reason, string $snippet)
    {
        $this->sourceFile = $sourceFile;
        $this->line = $line;
        $this->reason = $reason;
        $this->snippet = $snippet;
    }
}
