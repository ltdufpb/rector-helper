# Comparacao com ferramentas existentes

Esta secao documenta por que o `edu-deps` foi construido em vez de usar
ferramentas ja existentes. Material para o capitulo de revisao do TCC.

## Ferramentas avaliadas

### 1. `deptrac` (qossmic/deptrac)

**O que faz:** analise de dependencias entre camadas arquiteturais.
Requer:
- Composer com autoload PSR-4 configurado
- Classes em namespaces
- Arquivos UTF-8

**Por que nao serve para o e-cidade:**
- E-cidade nao tem autoload PSR-4 para os arquivos legados (`edu*.php`,
  `classes/db_*_classe.php`, `libs/*`). Sao 4.000+ arquivos procedurais
  sem namespace.
- `deptrac` ignora arquivos fora do classmap do Composer. Resultado:
  praticamente 100% dos arquivos do escopo seriam invisiveis.
- Sem suporte ao padrao `modification()` (wrapper proprietario do e-cidade).

**Conclusao:** ferramenta desenhada para projetos PSR-4 modernos. Inutil
para o caso do TCC.

### 2. `phpstan` (phpstan/phpstan)

**O que faz:** analise estatica de tipos e bugs. Tem uma camada de
descoberta de classes via Composer e suporte limitado a "bootstrap files".

**Por que nao serve:**
- Mesma premissa de autoload PSR-4.
- O bootstrap aceita arquivos que registram classes globais, mas exige
  carregamento PHP real (executa o arquivo). Carregar `edu*.php` significa
  executar o codigo, que tem efeitos colaterais (sessoes, queries, output
  HTML).
- Nao expoe API para "qual conjunto de arquivos esta envolvido na execucao
  de X" — esse e o requisito central do TCC.
- Plugins/extensoes nao cobrem o caso de pre-processador de paths.

**Conclusao:** `phpstan` pode rodar APOS o `edu-deps` ter resolvido a lista
de arquivos, mas nao substitui a resolucao em si.

### 3. `composer-unused` e `composer-require-checker`

**O que fazem:** verificam pacotes Composer nao usados/declarados.

**Por que nao servem:** operam sobre o `composer.json`, nao sobre includes
manuais. Irrelevantes para o problema.

### 4. Rector standalone

**O que faz:** refatoracao automatica baseada em AST.

**Por que sozinho nao basta:** Rector so refatora os arquivos que estao no
`withPaths()`. Se voce passa `edu*.php`, ele nao segue includes
transitivamente — assume autoload. O resultado e refatoracao incompleta:
`edu*.php` podem ser refatorados, mas `classes/db_*_classe.php` que sao
chamadas implicitamente ficam para tras.

**Conclusao:** Rector e a ferramenta de destino. O `edu-deps` complementa
ele resolvendo "quais arquivos precisam estar em `withPaths()` para uma
refatoracao completa".

## Tabela resumo

| Ferramenta | Resolve `modification()`? | Funciona sem PSR-4? | Sai com lista de paths? | Util para o e-cidade? |
|------------|---------------------------|---------------------|--------------------------|------------------------|
| deptrac    | Nao                       | Nao                 | Nao                     | Nao                    |
| phpstan    | Nao                       | Parcial             | Nao                     | Nao (sozinho)          |
| composer-unused | Nao                  | N/A                 | Nao                     | Nao                    |
| Rector     | Nao                       | Sim, mas sem BFS    | Indireto                | Sim, complementado     |
| **edu-deps** | **Sim**                | **Sim**             | **Sim**                 | **Sim**                |

## Contribuicao original

`edu-deps` preenche a lacuna entre "Rector espera autoload PSR-4 / paths
explicitos" e "e-cidade tem 1.211 arquivos procedurais carregados por
funcao wrapper proprietaria". A combinacao de:

1. Reconhecimento sintatico de `modification(...)`,
2. Convencao de nomenclatura `cl_X -> classes/db_X_classe.php`,
3. Ordenacao topologica para preservar ordem de carregamento,

E especifica do dominio do e-cidade e nao existe em nenhuma ferramenta
generica do ecossistema PHP em 2025.
