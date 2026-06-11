<?php

declare(strict_types=1);

namespace EduDeps\Output;

use EduDeps\Parser\ParserFactory;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Gera um rector-generated.php a partir do rector.php base do e-cidade,
 * substituindo o array passado para withPaths() pela lista completa de
 * arquivos resolvidos pela ferramenta.
 *
 * Preserva todas as outras chamadas (withSets, withSkipPath, withPhpVersion)
 * pretty-printando o AST modificado.
 */
final class RectorConfigWriter
{
    /**
     * @param list<string> $paths lista de paths absolutos a injetar em withPaths
     */
    public function write(string $rectorBaseFile, array $paths, string $outputDir, string $projectRoot): string
    {
        if (!is_file($rectorBaseFile)) {
            throw new \RuntimeException(sprintf('rector.php base nao existe: %s', $rectorBaseFile));
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Falha ao criar %s', $outputDir));
        }

        $source = (string) file_get_contents($rectorBaseFile);
        $parser = ParserFactory::create();
        $ast = $parser->parse($source);
        if ($ast === null) {
            throw new \RuntimeException('Falha ao parsear rector.php base.');
        }

        $finder = new NodeFinder();
        /** @var MethodCall|null $withPathsCall */
        $withPathsCall = $finder->findFirst($ast, static function (Node $node): bool {
            return $node instanceof MethodCall
                && $node->name instanceof Identifier
                && $node->name->toString() === 'withPaths';
        });

        if ($withPathsCall === null) {
            throw new \RuntimeException('Nao foi possivel localizar withPaths(...) em rector.php');
        }

        $items = [];
        foreach ($paths as $path) {
            $items[] = new ArrayItem(new String_($path));
        }
        $newArray = new Array_($items);
        $withPathsCall->args = [new Arg($newArray)];

        $printer = new StandardPrinter();
        $generatedCode = $printer->prettyPrintFile($ast);
        $generatedCode = $this->rewriteDirInString($generatedCode, $projectRoot);

        $file = $outputDir . '/rector-generated.php';
        file_put_contents($file, $generatedCode . "\n");
        return $file;
    }

    /**
     * Substitui `__DIR__ . '/algo'` por `'<projectRoot>/algo'` no codigo gerado.
     *
     * Necessario porque o rector-generated.php fica em out/, nao na raiz do
     * e-cidade — `__DIR__` resolveria errado em runtime do Rector. Como a
     * substituicao via AST visitor nao surtiu efeito (provavelmente o pretty
     * printer reescreveu a sequencia), fazemos pos-processamento textual.
     */
    private function rewriteDirInString(string $code, string $projectRoot): string
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        $code = preg_replace_callback(
            '/__DIR__\s*\.\s*\'([^\']+)\'/',
            static function (array $m) use ($projectRoot): string {
                return "'" . $projectRoot . $m[1] . "'";
            },
            $code
        );

        $code = preg_replace_callback(
            '/__DIR__\s*\.\s*"([^"]+)"/',
            static function (array $m) use ($projectRoot): string {
                return '"' . $projectRoot . $m[1] . '"';
            },
            $code
        );

        return $code;
    }

    /**
     * Substitui `__DIR__` literal (e expressoes `__DIR__ . '/algo'`) pelo path absoluto
     * do project-root no AST. Deixado aqui como referencia, nao mais chamado — pre
     * pretty printer aparentemente nao respeita node replacement em ConstFetch nessa
     * situacao especifica.
     *
     * @param list<\PhpParser\Node> $ast
     */
    private function rewriteDirConstant(array $ast, string $projectRoot): void
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        $visitor = new class($projectRoot) extends NodeVisitorAbstract {
            public string $projectRoot;
            public function __construct(string $projectRoot) { $this->projectRoot = $projectRoot; }
            public function enterNode(\PhpParser\Node $node)
            {
                if ($node instanceof Concat) {
                    if ($node->left instanceof ConstFetch
                        && $node->left->name instanceof Name
                        && strtoupper($node->left->name->toString()) === '__DIR__'
                        && $node->right instanceof String_) {
                        return new String_($this->projectRoot . $node->right->value);
                    }
                }
                if ($node instanceof ConstFetch
                    && $node->name instanceof Name
                    && strtoupper($node->name->toString()) === '__DIR__') {
                    return new String_($this->projectRoot);
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
