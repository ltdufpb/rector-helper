#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fix da regressao Rector: AddOverrideAttributeToOverriddenPropertiesRector.
 *
 * Em PHP 8.3 o atributo #[\Override] so aceita METODOS. O Rector tem uma
 * regra que aplica esse atributo tambem em propriedades sobrescritas, o que
 * gera fatal error em runtime: "Attribute Override cannot target property".
 *
 * Este script:
 *  1. Le cada arquivo PHP do project-root informado.
 *  2. Parseia com nikic/php-parser.
 *  3. Para cada Property node que tenha um Attribute "Override", remove o
 *     atributo (mantendo a propriedade intacta).
 *  4. Re-grava o arquivo via PrettyPrinter, preservando formatacao original
 *     o maximo possivel via cloner + replacer.
 *
 * Uso:
 *   php fix-rector-override-on-properties.php <project-root>
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser\Php7;

if ($argc < 2) {
    fwrite(STDERR, "Uso: php fix-rector-override-on-properties.php <project-root>\n");
    exit(1);
}

$projectRoot = rtrim(str_replace('\\', '/', $argv[1]), '/');
if (!is_dir($projectRoot)) {
    fwrite(STDERR, "Diretorio nao existe: $projectRoot\n");
    exit(1);
}

// Format-preserving setup: lexer com tokens preservados + Php7 parser.
// Apos a remocao do atributo, o printFormatPreserving so re-imprime os
// nodes modificados, mantendo o resto exatamente como estava no original
// (incluindo linhas em branco, comentarios, indentacao).
$lexer = new Emulative([
    'usedAttributes' => ['comments', 'startLine', 'endLine', 'startTokenPos', 'endTokenPos'],
]);
$parser = new Php7($lexer);
$printer = new PhpParser\PrettyPrinter\Standard();

$filesFixed = 0;
$attributesRemoved = 0;
$filesWithErrors = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (strpos($path, '/vendor/') !== false || strpos($path, '/node_modules/') !== false) {
        continue;
    }

    $source = file_get_contents($path);
    if ($source === false || strpos($source, 'Override') === false) {
        continue;
    }

    // Decode if ISO-8859-1
    $encoding = mb_detect_encoding($source, ['UTF-8', 'ISO-8859-1'], true);
    $sourceUtf8 = $source;
    if ($encoding === 'ISO-8859-1') {
        $sourceUtf8 = iconv('ISO-8859-1', 'UTF-8//IGNORE', $source);
    }

    try {
        $oldAst = $parser->parse($sourceUtf8);
    } catch (\Throwable $e) {
        $filesWithErrors++;
        fwrite(STDERR, "PARSE ERROR: $path: " . $e->getMessage() . "\n");
        continue;
    }
    if ($oldAst === null) {
        continue;
    }
    $oldTokens = $lexer->getTokens();

    // Clone AST: necessario para que o printFormatPreserving consiga comparar
    // novo vs antigo e re-imprimir apenas o que mudou.
    $cloneTraverser = new NodeTraverser();
    $cloneTraverser->addVisitor(new CloningVisitor());
    $newAst = $cloneTraverser->traverse($oldAst);

    $removedInThisFile = 0;

    $visitor = new class($removedInThisFile) extends NodeVisitorAbstract {
        public int $removed;
        public function __construct(int &$counter) {
            $this->removed = &$counter;
        }
        public function enterNode(Node $node)
        {
            if (!$node instanceof Property) {
                return null;
            }
            $newGroups = [];
            foreach ($node->attrGroups as $group) {
                $newAttrs = [];
                foreach ($group->attrs as $attr) {
                    $name = $attr->name->toString();
                    $shortName = strtolower(ltrim($name, '\\'));
                    if ($shortName === 'override') {
                        $this->removed++;
                        continue;
                    }
                    $newAttrs[] = $attr;
                }
                if ($newAttrs !== []) {
                    $newGroups[] = new AttributeGroup($newAttrs, $group->getAttributes());
                }
            }
            $node->attrGroups = $newGroups;
            return null;
        }
    };

    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($newAst);

    if ($visitor->removed === 0) {
        continue;
    }

    // Re-print preservando formatacao original em nodes nao tocados.
    $newCode = $printer->printFormatPreserving($newAst, $oldAst, $oldTokens);
    if ($encoding === 'ISO-8859-1') {
        $newCode = iconv('UTF-8', 'ISO-8859-1//IGNORE', $newCode);
    }

    file_put_contents($path, $newCode);
    $filesFixed++;
    $attributesRemoved += $visitor->removed;
    echo "[fix] $path ($visitor->removed attribute(s))\n";
}

echo "\n=== Resumo ===\n";
echo "Arquivos modificados: $filesFixed\n";
echo "Atributos removidos:  $attributesRemoved\n";
echo "Parse errors:         $filesWithErrors\n";
