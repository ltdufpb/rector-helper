<?php

declare(strict_types=1);

namespace EduDeps\Parser;

use PhpParser\Parser;
use PhpParser\ParserFactory as NikicParserFactory;

final class ParserFactory
{
    public static function create(): Parser
    {
        return (new NikicParserFactory())->create(NikicParserFactory::PREFER_PHP7);
    }
}
