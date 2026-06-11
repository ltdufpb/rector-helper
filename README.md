# rector-helper

Ferramenta auxiliar para viabilizar o uso do [Rector](https://getrector.com) na migração de projetos PHP legados **sem autoload PSR-4**.

Desenvolvida como parte de TCC de graduação (UFPB) sobre migração do [e-cidade](https://github.com/DBSeller/e-cidade) — ERP municipal open-source com ~32.000 arquivos PHP escritos para PHP 5.6 — para PHP 8.x.

> **Nota:** a ferramenta foi criada com o nome `edu-deps` (referência ao módulo de Educação do e-cidade, usado como estudo de caso). O binário e os namespaces internos ainda usam esse nome; a renomeação completa para `rector-helper` está planejada.

---

## O problema

O Rector refatora **apenas os arquivos listados explicitamente** em `withPaths()` na sua configuração, e pressupõe autoload PSR-4 para descobrir classes. Projetos legados como o e-cidade quebram essa premissa:

- Milhares de arquivos procedurais na raiz, sem namespace nem autoload;
- Dependências carregadas via wrapper proprietário — `require_once(modification("classes/db_aluno_classe.php"))` — que nenhuma ferramenta padrão entende;
- Convenções de nomenclatura implícitas (`new cl_aluno()` → `classes/db_aluno_classe.php`);
- Arquivos em ISO-8859-1, que quebram o parser.

Resultado prático: a configuração convencional do Rector alcançava **532 arquivos** de um conjunto real de milhares. O sistema passava no lint, mas quebrava em runtime, porque as dependências transitivas nunca eram refatoradas. Ferramentas como `deptrac`, `phpstan` e `composer-unused` não resolvem o caso (ver `docs/COMPARISON.md`).

## O que a ferramenta faz

A partir de arquivos-semente (ex.: `edu*.php`), percorre **estaticamente** o grafo de dependências do projeto e gera a lista completa de arquivos que o Rector precisa processar:

1. **Parsing AST** (`nikic/php-parser`) com conversão ISO-8859-1 → UTF-8 e cache em disco;
2. **Reconhecimento de padrões legados**: wrapper `modification()`, includes literais, convenção `cl_X → classes/db_X_classe.php`, classmap das pastas do projeto;
3. **Grafo de dependências** com detecção de ciclos (Tarjan SCC) e ordenação topológica (Kahn);
4. **Geração automática** de um `rector-generated.php` pronto para uso, clonando a configuração base do projeto e injetando os paths descobertos;
5. **Correções que o Rector não cobre**: comandos para padrões pré-parse (ex.: short open tags `<?`), com catálogo de regressões conhecidas de migração PHP legado em expansão.

### Resultados medidos no e-cidade

| Métrica | Rector sozinho | Rector + rector-helper | Ganho |
|---|---|---|---|
| Arquivos no escopo | 532 | 4.073 | 7,7× |
| Arquivos efetivamente modificados | 279 | 3.073 | 11,0× |
| Tipos de regra Rector aplicados | ~5 | 72 | 14,4× |

---

## Requisitos

- PHP 7.4+ (no host, fora do container do projeto-alvo)
- Composer 2.x
- Extensões: `mbstring`, `iconv`

## Instalação

```bash
git clone https://github.com/ltdufpb/rector-helper.git
cd rector-helper
composer install
```

## Uso

### `scan` — resolve o grafo de dependências

```bash
bin/edu-deps scan --project-root /caminho/do/projeto --report
```

| Opção | Default | Descrição |
|---|---|---|
| `--project-root` | (obrigatório) | Raiz do projeto legado |
| `--file` | (vazio) | Roda contra um único arquivo seed |
| `--seeds-glob` | `edu*.php` | Padrão glob para seeds na raiz |
| `--output-dir` | `./out` | Pasta de saída |
| `--cache-dir` | `./cache` | Cache de AST/encoding (string vazia desabilita) |
| `--skip-classmap` | off | Pula construção do classmap (só includes literais) |
| `--overrides` | `./config/overrides.yaml` | YAML com overrides manuais |
| `--report[=FILE]` | off | Grava `metrics.json` |
| `--skip-short-tags-check` | off | Pula a pré-verificação de short open tags |
| `--abort-on-short-tags` | off | Exit 1 se houver short tags pendentes (CI) |

Saídas em `out/`:

- `graph.json` — nós, arestas, SCCs, ordem topológica, não-resolvidos
- `files.txt` — lista plana topológica para o Rector
- `graph.mmd` — diagrama Mermaid do grafo
- `unresolved.csv` — diagnóstico dos casos não resolvidos
- `metrics.json` — métricas (com `--report`)

### `rector` — gera a configuração expandida

```bash
bin/edu-deps rector --project-root /caminho/do/projeto --mode=config
```

Clona o `rector.php` da raiz do projeto e substitui o array de `withPaths()` pela lista topológica gerada pelo `scan`. Saída: `out/rector-generated.php`. Depois:

```bash
vendor/bin/rector process --dry-run --config=out/rector-generated.php
```

### `fix-short-tags` — corrige `<?` → `<?php` em massa

```bash
bin/edu-deps fix-short-tags --project-root /caminho/do/projeto --dry-run
```

Usa PCRE com lookahead (`/<\?(?!php|=|xml)/`), preservando `<?xml`, `<?=` e `<?php`. Idempotente. Existe porque short open tags são um problema **pré-parse** — verificado: nenhuma das 141 regras oficiais do Rector cobre isso.

| Opção | Default | Descrição |
|---|---|---|
| `--project-root` | (obrigatório) | Raiz do projeto |
| `--dry-run` | off | Só reporta o que faria |
| `--strict` | off | Exit 1 se houver pendências (CI) |

### `doctor` — resolve casos dinâmicos interativamente

```bash
bin/edu-deps doctor --project-root /caminho/do/projeto
```

Lê `unresolved.csv`, sugere candidatos do classmap para cada referência não resolvida e grava as decisões em `config/overrides.yaml`.

---

## Testes

```bash
composer test
```

- `tests/unit/DependencyVisitorTest.php` — extração de `modification()`/includes
- `tests/unit/PathResolverTest.php` — resolução literal e normalização Windows
- `tests/unit/CycleDetectorTest.php` — Tarjan + Kahn

## Documentação

- `docs/ARCHITECTURE.md` — arquitetura interna, fluxo, decisões de design
- `docs/COMPARISON.md` — comparação com deptrac, phpstan, composer-unused e Rector standalone
- `docs/RELATORIO_PROFESSOR.md` — relatório formal da ferramenta
- `docs/REGRESSOES_RECTOR.md` — catálogo de regressões de migração descobertas em runtime

## Roadmap

- [ ] Renomear binário/namespaces de `edu-deps` para `rector-helper`
- [ ] `fix-regressions` — aplica em batch os fixes do catálogo de regressões (`--rule=X`, `--dry-run`)
- [ ] `lint-php8` — detecta padrões legados antes do Rector e projeta cobertura
- [ ] `config/regressions.yaml` — catálogo estruturado consumido pelos comandos
- [ ] Elevar a taxa de resolução de dependências de 83,5% para ~99,8% (skip-list de built-ins, pastas adicionais no classmap, lookup case-insensitive)
- [ ] Validação em segundo módulo do e-cidade como prova de generalização
