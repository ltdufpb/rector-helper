# Arquitetura da ferramenta `edu-deps`

## Visao em uma frase

`edu-deps` e um analisador estatico de dependencias para arquivos PHP procedurais sem autoload PSR-4. Ele parte de "seeds" (arquivos `edu*.php` na raiz do e-cidade), percorre transitivamente todas as referencias estaticas (includes, `modification()`, `new cl_*`, `use Namespace`, chamadas estaticas) e produz a lista ordenada topologicamente que o Rector pode consumir como `withPaths()`.

## Diagrama de fluxo

```
seeds (edu*.php)
      |
      v
+---------------------+
| ClassMapBuilder     |  varre classes/, libs/, model/, src/
|   - PSR scanning    |  gera ClassMap (nome -> arquivo, FQCN -> arquivo)
+---------------------+
      |
      v
+---------------------+
| DependencyResolver  |  BFS sobre arquivos visitados
|   visita cada arq.: |
|     EncodingLoader  |  ISO-8859-1 -> UTF-8 (cache em disco)
|     AstCache        |  parse PHP -> AST (cache por sha1)
|     DependencyVisitor extrai:
|       - include/require* literal
|       - modification("path") literal
|       - new ClassName
|       - ClassName::metodo
|       - use Namespace\Class
|     PathResolver resolve cada referencia:
|       - Overrides primeiro (overrides.yaml)
|       - ClassMap (PSR-4 + nomes curtos)
|       - Convencao cl_X -> classes/db_X_classe.php
|       - Heuristica de path do chamador
+---------------------+
      |
      v
+---------------------+
| DependencyGraph     |  adjacencia + metadados de aresta
+---------------------+
      |
      v
+---------------------+
| CycleDetector       |  Tarjan SCC
| TopologicalSorter   |  Kahn sobre DAG condensado
+---------------------+
      |
      v
+---------------------+
| Outputs em out/     |
|   - graph.json      |  nos, arestas, ciclos, ordem topologica, unresolved
|   - files.txt       |  lista plana topologica para Rector
|   - graph.mmd       |  Mermaid para o relatorio TCC
|   - unresolved.csv  |  diagnostico
|   - rector-generated.php (via comando rector)
+---------------------+
```

## Decisoes de design

### Por que PHP e nao Python/Go?

O Rector ja roda em PHP e usa nikic/php-parser. Reutilizar a mesma toolchain
e mais barato e garante 100% de compatibilidade na interpretacao da sintaxe
PHP 5.6 (que tem peculiaridades que parsers genericos podem nao tratar).

### Por que BFS e nao DFS?

BFS tem garantia de terminacao mesmo com ciclos sem precisar de pilha
recursiva (importante para grafos com 3000+ nos no e-cidade). DFS recursivo
estouraria o limite de pilha PHP (default 256). DFS iterativo seria
equivalente, mas BFS produz ordem mais previsivel para debug.

### Por que cache em disco e nao Redis/SQLite?

Simplicidade. O cache e descartavel; uma re-execucao com `cache/` limpo
reconstroi tudo. Disco evita dependencia externa e e suficiente para o
volume de dados (~50MB por classmap completo).

### Por que ordenacao topologica reversa?

A semantica esperada para "ordem de carregamento" em PHP procedural e
"dependencias primeiro": se A precisa de B, B deve ser processado antes.
Kahn produz a ordem natural (raizes primeiro); aplicamos reverse pois nas
arestas `A -> B` interpretamos "A depende de B". Resultado: arquivos
sem dependentes saem por ultimo.

## Limitacoes conhecidas

1. **Includes dinamicos** (`include $var`): impossivel de resolver
   estaticamente. Mitigado por `overrides.yaml` (lista manual).
2. **Reflexao/`call_user_func` por string**: igualmente impossivel.
3. **Heranca multi-arquivo sem namespace**: classes que estendem outras sem
   carregar explicitamente o pai geram falsos negativos. ClassMap mitiga ao
   indexar todas as declaracoes, mas a `extends`/`implements` ainda nao e
   percorrida (TODO em fase futura).
4. **Geradores de codigo em build-time**: nao temos como saber. Documentado.

## Estrutura de arquivos

Ver raiz do projeto. Cada subpasta de `src/` tem responsabilidade unica:
`Parser/` so parsea, `Resolver/` so resolve, `Graph/` so algoritmos de grafo,
`Output/` so serializa, `Config/` so le configuracao, `Cli/` so orquestra.
