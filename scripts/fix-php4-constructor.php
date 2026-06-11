#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regressao Rector #3: Construtor estilo PHP 4 nao convertido para __construct.
 *
 * Em PHP <8.0, um metodo com o mesmo nome da classe era reconhecido como
 * construtor (PHP 4 style). PHP 8.0 REMOVEU esse comportamento — agora esse
 * metodo e tratado como metodo normal e __construct nao e chamado, deixando
 * propriedades nao inicializadas e gerando "Call to a member function X()
 * on null" em runtime.
 *
 * Este script:
 *  1. Le cada arquivo PHP do project-root informado.
 *  2. Parseia com nikic/php-parser (format-preserving).
 *  3. Para cada Class_ que tenha um ClassMethod com nome igual ao da classe
 *     (comparacao case-insensitive — PHP nao diferencia maiusculas em metodos)
 *     E NAO tenha um ClassMethod chamado "__construct":
 *     - Renomeia o metodo para "__construct".
 *  4. Re-grava o arquivo preservando formatacao byte-a-byte (apenas a linha
 *     da declaracao do metodo muda).
 *
 * Uso:
 *   php fix-php4-constructor.php <project-root> [--dry-run]
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser\Php7;

if ($argc < 2) {
    fwrite(STDERR, "Uso: php fix-php4-constructor.php <project-root> [--dry-run]\n");
    exit(1);
}

$projectRoot = rtrim(str_replace('\\', '/', $argv[1]), '/');
$dryRun = in_array('--dry-run', $argv, true);

if (!is_dir($projectRoot)) {
    fwrite(STDERR, "Diretorio nao existe: $projectRoot\n");
    exit(1);
}

$lexer = new Emulative([
    'usedAttributes' => ['comments', 'startLine', 'endLine', 'startTokenPos', 'endTokenPos'],
]);
$parser = new Php7($lexer);
$printer = new PhpParser\PrettyPrinter\Standard();

$filesScanned = 0;
$filesFixed = 0;
$methodsRenamed = 0;
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

    $filesScanned++;
    $source = file_get_contents($path);
    if ($source === false || stripos($source, 'class ') === false) {
        continue;
    }

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

    $cloneTraverser = new NodeTraverser();
    $cloneTraverser->addVisitor(new CloningVisitor());
    $newAst = $cloneTraverser->traverse($oldAst);

    $renamedInThisFile = 0;
    $renamedDetails = [];

    $visitor = new class($renamedInThisFile, $renamedDetails) extends NodeVisitorAbstract {
        public int $renamed;
        /** @var list<array{class:string,method:string}> */
        public array $details;

        public function __construct(int &$counter, array &$details) {
            $this->renamed = &$counter;
            $this->details = &$details;
        }

        public function enterNode(Node $node)
        {
            if (!$node instanceof Class_ || $node->name === null) {
                return null;
            }
            $className = $node->name->toString();
            $classNameLower = strtolower($className);

            // Coletar methods existentes
            $hasConstruct = false;
            $php4Constructor = null;

            foreach ($node->stmts as $stmt) {
                if (!$stmt instanceof ClassMethod) {
                    continue;
                }
                $methodNameLower = strtolower($stmt->name->toString());
                if ($methodNameLower === '__construct') {
                    $hasConstruct = true;
                }
                if ($methodNameLower === $classNameLower) {
                    $php4Constructor = $stmt;
                }
            }

            // Renomear apenas se ha php4-constructor E nao ha __construct
            if ($php4Constructor !== null && !$hasConstruct) {
                $oldName = $php4Constructor->name->toString();
                $php4Constructor->name = new Identifier('__construct');
                $this->renamed++;
                $this->details[] = ['class' => $className, 'method' => $oldName];
            }
            return null;
        }
    };

    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($newAst);

    if ($visitor->renamed === 0) {
        continue;
    }

    if (!$dryRun) {
        $newCode = $printer->printFormatPreserving($newAst, $oldAst, $oldTokens);
        if ($encoding === 'ISO-8859-1') {
            $newCode = iconv('UTF-8', 'ISO-8859-1//IGNORE', $newCode);
        }
        file_put_contents($path, $newCode);
    }

    $filesFixed++;
    $methodsRenamed += $visitor->renamed;
    foreach ($visitor->details as $detail) {
        $tag = $dryRun ? '[would-fix]' : '[fix]';
        echo "$tag $path :: class {$detail['class']} -> method {$detail['method']}() -> __construct()\n";
    }
}

echo "\n=== Resumo ===\n";
echo "Arquivos escaneados:  $filesScanned\n";
echo "Arquivos modificados: $filesFixed" . ($dryRun ? ' (DRY-RUN, nenhum disco gravado)' : '') . "\n";
echo "Metodos renomeados:   $methodsRenamed\n";
echo "Parse errors:         $filesWithErrors\n";
