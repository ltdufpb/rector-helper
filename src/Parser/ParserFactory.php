<?php

declare(strict_types=1);

namespace EduDeps\Parser;

use PhpParser\Parser;
use PhpParser\ParserFactory as NikicParserFactory;

final class ParserFactory
{
    public static function create(): Parser
    {
        // createForNewestSupportedVersion: o e-cidade pos-Rector contem
        // sintaxe PHP 8.4+ (ex: new Classe()->metodo(), gerada pelo set
        // PHP_84 do proprio Rector). O parser precisa acompanhar a versao
        // alvo da migracao, senao esses arquivos caem como parse_error e
        // somem do classmap.
        return (new NikicParserFactory())->createForNewestSupportedVersion();
    }
}
