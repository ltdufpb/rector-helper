<?php

declare(strict_types=1);

namespace EduDeps\Parser;

/**
 * Registro de classes nativas do PHP (built-in).
 *
 * Usado pelo DependencyVisitor para distinguir referencias que precisam
 * ser resolvidas para arquivos do projeto (ex: new cl_aluno()) de classes
 * que ja existem no runtime do PHP (ex: new DateTime()) e portanto nao
 * geram dependencia de arquivo.
 *
 * O lookup e case-insensitive porque nomes de classe em PHP sao
 * case-insensitive (new stdclass() e new stdClass() instanciam a mesma
 * classe), e tolera o prefixo "\" de nomes fully-qualified.
 */
final class PhpBuiltinClasses
{
    /**
     * Nomes em minusculo para lookup case-insensitive.
     *
     * @var list<string>
     */
    private const NAMES = [
        // Nucleo
        'stdclass',
        'closure',
        'generator',
        'throwable',
        'exception',
        'errorexception',
        'error',
        'typeerror',
        'valueerror',
        'argumentcounterror',
        'arithmeticerror',
        'divisionbyzeroerror',
        // SPL — exceptions
        'runtimeexception',
        'logicexception',
        'invalidargumentexception',
        'unexpectedvalueexception',
        'outofboundsexception',
        'outofrangeexception',
        'lengthexception',
        'domainexception',
        'rangeexception',
        'overflowexception',
        'underflowexception',
        'badfunctioncallexception',
        'badmethodcallexception',
        'jsonexception',
        // SPL — estruturas
        'arrayobject',
        'arrayiterator',
        'splfileinfo',
        'splfileobject',
        'splobjectstorage',
        'splqueue',
        'splstack',
        // Data/hora
        'datetime',
        'datetimeimmutable',
        'datetimezone',
        'dateinterval',
        'dateperiod',
        // Banco de dados
        'pdo',
        'pdostatement',
        'pdoexception',
        'mysqli',
        // XML/DOM
        'domdocument',
        'domelement',
        'domnode',
        'domxpath',
        'simplexmlelement',
        'xmlreader',
        'xmlwriter',
        // Atributos nativos (PHP 8+)
        'override',
        'attribute',
        'returntypewillchange',
        'allowdynamicproperties',
        'sensitiveparameter',
        // Diversos
        'ziparchive',
        'soapclient',
        'soapfault',
        'soapheader',
        'reflectionclass',
        'reflectionmethod',
        'reflectionproperty',
        'curlfile',
    ];

    public static function isBuiltin(string $name): bool
    {
        return in_array(strtolower(ltrim($name, '\\')), self::NAMES, true);
    }
}
