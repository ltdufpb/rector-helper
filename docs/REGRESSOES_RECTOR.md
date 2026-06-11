# Regressões mapeadas após aplicação do Rector

Documento vivo: cada entrada registra um bug introduzido (ou desbloqueado) pelo Rector durante a refatoração automática do e-cidade, junto com a correção aplicada e a estratégia para automatizar a detecção/correção em futuras execuções via comando da `edu-deps`.

A motivação é dupla: (a) corrigir o sistema agora; (b) acumular um catálogo de "regressões conhecidas do Rector em projetos legados" que a `edu-deps` aprende a tratar — virando uma extensão complementar do Rector.

---

## Regressão #1 — `Error::_handle()` esperando `$context` obrigatório

**Sintoma observado em runtime:**
```
Uncaught ArgumentCountError: Too few arguments to function
ECidade\V3\Error\Handler\Error::_handle(), 4 passed and exactly 5 expected
in src/V3/Error/Handler/Error.php:20
```

**Onde ocorre:** ao fazer login no e-cidade. O PHP dispara um warning durante a execução, o error handler registrado é chamado com 4 argumentos (PHP 8 não passa mais `$context`), mas a assinatura ainda exigia 5.

**Causa:** **NÃO** é regressão direta do Rector — é incompatibilidade PHP 8 pré-existente. Mas foi "desbloqueada" pelo Rector: antes, o código quebrava antes de chegar ao handler; depois das transformações, o fluxo avança e o bug aparece.

**Correção aplicada:** `$context = null` (default) em `_handle()` e `handle()` no arquivo `src/V3/Error/Handler/Error.php`. Commit `4ed666613`.

**Diff:**
```diff
-  public static function _handle($type, $message, $file, $line, $context) {
+  public static function _handle($type, $message, $file, $line, $context = null) {
     static::handle($type, $message, $file, $line, $context);
     ...

-  public static function handle($type, $message, $file, $line, $context) {
+  public static function handle($type, $message, $file, $line, $context = null) {
```

**Estratégia para automatizar na `edu-deps`:**

Detectar handlers registrados via `set_error_handler()` ou `set_exception_handler()` cuja assinatura tenha mais de 4 parâmetros sem default. Pode virar uma regra Rector customizada ou um comando `edu-deps lint-error-handlers`.

**Pattern de detecção (pseudocódigo):**
```
para cada arquivo PHP:
  para cada chamada a set_error_handler($callback, ...):
    resolver $callback para função/método
    se assinatura tem >4 parâmetros sem default:
      reportar como warning ou aplicar fix automático
```

---

## Regressão #2 — `#[\Override]` aplicado em propriedades

**Sintoma observado em runtime:**
```
Attribute "Override" cannot target property (allowed targets: method)
```

**Onde ocorre:** ao carregar qualquer classe que tenha propriedade com `#[\Override]`. PHP 8.3 só aceita o atributo em métodos; em propriedades é erro fatal.

**Causa:** **Sim, regressão direta do Rector.** A regra `AddOverrideAttributeToOverriddenPropertiesRector` (45 ocorrências no log do dry-run) aplica `#[\Override]` em propriedades sobrescritas — mas o RFC do PHP 8.3 não autoriza esse alvo. A regra do Rector é prematura/incorreta.

**Escopo afetado:** 314 ocorrências de `#[Override]` em 169 arquivos. Parte dessas é em métodos (correto), parte em propriedades (incorreto). Difícil estimar a divisão sem AST.

**Correção aplicada:** script PHP via AST (`edu-deps/scripts/fix-rector-override-on-properties.php`) que:
1. Faz parse de cada arquivo PHP com `nikic/php-parser`.
2. Para cada nó `Property` (declaração de propriedade), procura `AttributeGroup` contendo `Attribute("Override")` e remove apenas esse atributo.
3. Preserva atributos válidos em métodos.
4. Re-grava o arquivo via PrettyPrinter mantendo encoding ISO-8859-1.

**Resultado da execução (07/06/2026):**
- Arquivos modificados: **45**
- Atributos removidos: **45**
- Parse errors: 0
- Diff cirúrgico: **-46 linhas / +1 linha** em 45 arquivos
- Commit: `cba1fdd0d`

**Detalhe técnico do diff cirúrgico:** primeira tentativa usou `PrettyPrinter::prettyPrintFile()` que re-formata o arquivo inteiro (resultado: -7.623 / +2.730 linhas, todas mudanças cosméticas). Refeito com `CloningVisitor` + `printFormatPreserving($newAst, $oldAst, $oldTokens)` que re-imprime apenas os nodes modificados, preservando byte-a-byte o resto do arquivo (comentários, indentação, linhas em branco intactos).

**Prevenção de recorrência:** adicionar ao `rector.php` base:
```php
->withSkip([
    \Rector\Php83\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector::class,
])
```

Assim, próximas execuções do Rector via `bin/edu-deps rector --mode=config` herdam o skip e nunca mais reintroduzem o bug.

**Estratégia para automatizar na `edu-deps`:**

Adicionar a regra Rector problemática à lista de "regras a sempre skipar" mantida pela `edu-deps`. Idealmente, criar comando `bin/edu-deps fix-regressions` que:
1. Le um catálogo de regressões conhecidas (este documento ou YAML estruturado).
2. Para cada regressão tipo "atributo errado em alvo errado", aplica o fix AST automaticamente.
3. Para regressões tipo "assinatura quebrada", reporta com sugestão de correção.

---

---

## Regressão #3 — Construtor PHP 4 (`function NomeClasse()`) não convertido

**Sintoma observado em runtime:** ao navegar para `DB:EDUCAÇÃO > Alimentação Escolar > Cadastro > Nutricionista` (ou qualquer módulo do e-cidade que use classes com construtor PHP 4):

```
Uncaught Error: Call to a member function label() on null
in forms/db_frmmer_nutricionista.php on line 29
```

A linha em questão é `$clmer_nutricionista->rotulo->label();` — falha porque `$clmer_nutricionista->rotulo` é `null`. A propriedade deveria ser inicializada no construtor da classe `cl_mer_nutricionista`.

**Causa raiz:** classes legadas (~2.776 arquivos em `classes/`, `libs/`, `dbforms/` e raiz) declaram o construtor com o estilo PHP 4 — método com o mesmo nome da classe:

```php
class cl_mer_nutricionista {
    var $rotulo = null;

    function cl_mer_nutricionista() {           // PHP 4 constructor — REMOVIDO em PHP 8.0
        $this->rotulo = new rotulo(...);
    }
}
```

Em PHP <8.0 o PHP reconhecia esse método como construtor. Em PHP 8.0+ ele é um método comum, e `new cl_mer_nutricionista()` chama o `__construct()` padrão (que não faz nada). Propriedades não são inicializadas, resultando em `null` access errors em runtime.

**Por que o Rector não cobriu?** O Rector tem a regra `RenameMethodOldNameToNewNameRector` (e ancestrais como `Php7\Rector\ClassMethod\Php4ConstructorRector`), mas ela depende de o arquivo estar no `withPaths()`. Mesmo expandindo com `edu-deps`, parte das classes ficou fora porque:
1. Estão em pastas não escaneadas (já abordado na Regressão / seção 5 do relatório)
2. Mesmo as 4.073 alcançadas, a regra específica de PHP 4 constructor não está no `LevelSetList::UP_TO_PHP_85` (foi mantida em sets antigos como `Php\Rector\PHP7\PreferThis*` mas não promovida).

**Escopo do bug:** 32.897 arquivos PHP escaneados → **2.776 com construtor PHP 4** → **2.911 métodos renomeados** (alguns arquivos têm múltiplas classes).

**Correção aplicada:** script `edu-deps/scripts/fix-php4-constructor.php` que:
1. Parseia cada arquivo PHP com `nikic/php-parser` (format-preserving).
2. Para cada `Class_`, procura `ClassMethod` cujo nome (case-insensitive em PHP) é igual ao nome da classe E não há `__construct()` definido.
3. Renomeia o método para `__construct`.
4. Re-imprime via `printFormatPreserving` — apenas a linha do `function` muda, resto byte-a-byte intacto.

**Resultado:**
- Arquivos modificados: **2.776**
- Métodos renomeados: **2.911**
- Diff cirúrgico: **+2.911 / −2.911 linhas** (1 mudança por método)
- Parse errors: **20** (arquivos com sintaxe extremamente antiga; listados em `edu-deps/out/fix-php4-constructor.log`)
- Commit: `2952b8435`

**Prevenção de recorrência:** adicionar a regra do Rector ao set padrão. No `rector.php` base:

```php
use Rector\Php70\Rector\ClassMethod\Php4ConstructorRector;

return RectorConfig::configure()
    // ...
    ->withRules([
        Php4ConstructorRector::class,
    ]);
```

**Estratégia para automatizar na `edu-deps`:** este script tem qualidade de comando first-class. Próxima iteração da ferramenta: `bin/edu-deps fix-regressions --rule=php4-constructor` que aplica este e os outros fixes mapeados de forma idempotente. Detecta automaticamente quais regras Rector estão ausentes do `withSets` ativo e oferece skipar ou aplicar.

**Observação relevante para o TCC:** este é um dos casos mais densos do "automatizável que o Rector não cobriu". Demonstra concretamente o valor da `edu-deps` como camada complementar — sem ela, seriam **2.776 arquivos** que um desenvolvedor teria que abrir um por um pra renomear o construtor manualmente.

---

## Regressão #4 — Short open tags (`<?`) em arquivos fora do escopo de migração inicial

**Sintoma observado em runtime:** ao navegar para qualquer módulo não-Educação (Biblioteca, Acordos, Veículos, Vacinas, etc.):

```
Notificação do Sistema
Desculpe, algo inesperado aconteceu:
Página não encontrada: pagina_retorno.""class = "button"> Retornar.
```

A "URL" exibida é literalmente a string PHP `".$clbiblioteca->pagina_retorno."` que o navegador interpretou como path. Causa: o arquivo PHP saiu como texto, sem ser executado pelo interpretador.

**Causa raiz:** o arquivo começava com `<?` em vez de `<?php`. PHP 8 desabilita `short_open_tag` por padrão, então `<?` é tratado como texto. Os commits PHP8-3 a PHP8-7 (e PHP8-9) já haviam corrigido **5.300+ arquivos** dos diretórios `classes/`, `libs/`, `forms/`, `dbforms/`, e dos seeds `edu*.php` + `mer*.php`. Porém **746 arquivos restavam** em outros módulos (`aco*`, `bib*`, `vei*`, `vac*`, `cam*`, `cus*`, `con*`, etc.) que não estavam no escopo das etapas anteriores. Como o `edu-deps` expande o escopo via grafo de dependências, alguns desses arquivos são alcançados em runtime por funcionalidades correlatas.

**Correção aplicada:** mesmo padrão dos commits anteriores — perl one-liner:

```bash
grep -lrE "^<\?[^pxe=]" --include="*.php" | \
  xargs perl -i -pe 's/<\?(?!php|=|xml)/<?php /g'
```

Preserva `<?xml`, `<?=`, `<?php`. Encoding ISO-8859-1 preservado.

**Resultado da rodada inicial (hotfix4):**
- Arquivos verificados: ~32.000
- Arquivos modificados: **746**
- Linhas: **+4.845 / −4.845** (1 mudança por short tag, diff cirúrgico)
- Commit: `a4eae6046`

**Bug do bug — segunda rodada (hotfix6):** o regex de DETECÇÃO usado no hotfix4 (`grep "^<?"`) só pegava arquivos onde `<?` aparecia no INÍCIO de uma linha. Mas o regex de SUBSTITUIÇÃO (perl) funciona em qualquer posição. Resultado: arquivos com `<?php` no início mas `<?` no meio (blocos PHP intercalados em HTML — padrão muito comum em forms do e-cidade) escaparam silenciosamente.

Sintoma observado quando o usuário navegou para "Alimentação Escolar → Procedimentos → Atendimento de Requisição" e "Materiais → Requisição de Saída → Inclusão":

```
rotulo->label(); $db_opcao = 1; ... $aParametrosCustos = db_stdClass::getParametro("parcustos",$aParamKeys);
```

PHP code aparecendo literalmente no HTML — o bloco entre `<?` e `?>` virou texto.

Re-detecção corrigida:
```bash
grep -lrE "<\?[^pxe=?]" --include="*.php"  # qualquer posicao
```

**Resultado da segunda rodada (hotfix6):**
- Arquivos verificados: ~32.000
- Arquivos modificados adicionais: **1.426** (40 do módulo `mat`, 0 do `mer` — já cobertos no hotfix anterior)
- Linhas: **+8.698 / −8.698**
- Pós-fix: **0** ocorrências restantes (busca universal)
- Commit: `2bc2c32a6`

**Bug-do-bug — terceira rodada (hotfix7):** o regex `[^pxe?=]` continua falhando para `<?` no FIM de linha (apenas seguido por `\n`), porque bracket expression exige um caractere real. `grep -P` (PCRE com lookahead) seria correto, mas falha sob locale ISO-8859-1.

Solução definitiva: script PHP standalone (`edu-deps/scripts/fix-short-open-tags.php`) usando PCRE diretamente (que funciona com qualquer encoding):

```php
$pattern = '/<\?(?!php|=|xml)/';
$replacement = '<?php ';
preg_replace($pattern, $replacement, $source, -1, $count);
```

O script só grava se houve mudança real (idempotente, não polui git status).

**Resultado da terceira rodada (hotfix7):**
- Arquivos escaneados: 32.851
- Arquivos modificados adicionais: **11.108**
- Short tags substituídos: **37.345**
- Pós-fix: **0** ocorrências restantes (varredura completa)
- Commit: `c09807392`

**Total acumulado de short tags corrigidos (3 rodadas):** 746 + 1.426 + 11.108 = **13.280 arquivos**, ~50.000 short tags.

**Lição central documentada:**
1. O regex de DETECÇÃO precisa cobrir EXATAMENTE os mesmos casos do regex de SUBSTITUIÇÃO.
2. Bracket expression `[^abc]` falha em fim de linha porque exige um caractere real.
3. Para projetos com encoding ISO-8859-1 (legacy), use PCRE via PHP, não `grep -P` (que requer locale UTF-8).
4. Sempre re-validar `grep -l ... | wc -l` antes E depois do fix para confirmar cobertura zero.

**Estratégia final para automatizar na `edu-deps`:** o script `fix-short-open-tags.php` já é a forma definitiva. Próxima iteração: integrar como `bin/edu-deps fix-short-tags --project-root <path>` (wrapper do script standalone) e como subcomando de `bin/edu-deps fix-regressions --rule=short-tags`.

**Status da automação (atualizado em 2026-06-08):** ✅ **integrado como comando first-class da CLI**. Disponível como `bin/edu-deps fix-short-tags --project-root <path> [--dry-run] [--strict]`. Lógica extraída para `EduDeps\Fixer\ShortTagsFixer` (reusável programaticamente). `ScanCommand` agora roda pre-check automático: se detectar short tags pendentes, emite warning sugerindo o comando. Cobertura: 7 testes unitários em `tests/unit/ShortTagsFixerTest.php` (incluindo idempotência, dry-run, exclusão de `vendor/`).

---

## Regressão #5 — Shim das superglobais `$HTTP_*_VARS` quebrado em `libs/db_stdlib.php`

**Sintoma observado em runtime (mesma página da Regressão #4 após o fix de short tags):**

Mesmo com o arquivo executando corretamente, `$clbiblioteca->pagina_retorno` retornava `""`. O construtor da classe faz:

```php
$this->pagina_retorno = basename($GLOBALS["HTTP_SERVER_VARS"]["PHP_SELF"]);
```

Em PHP 8, `$GLOBALS["HTTP_SERVER_VARS"]` é `null` → `basename(null)` retorna `""` → redirect vira `location.href=''` → página em branco / loop.

**Causa raiz:** as superglobais antigas PHP 4 (`$HTTP_SERVER_VARS`, `$HTTP_POST_VARS`, etc.) foram removidas no PHP 5.4 quando `register_long_arrays` foi deprecated/removed. O e-cidade usa essas variáveis em **9.778 arquivos**:
- ~78.000 ocorrências via `$GLOBALS["HTTP_*_VARS"]`
- ~19.000 ocorrências como variável direta (`$HTTP_POST_VARS`)

O `TCC_relatorio.md` documenta um shim que deveria estar em `libs/db_stdlib.php` (carregado por todo request). Mas o shim atual estava **errado** — apenas garantia que `$_SERVER`/`$_POST` existissem (que sempre existem em PHP 8):

```php
// ERRADO — não cria $HTTP_*_VARS:
if (!isset($_SERVER)) $_SERVER ??= [];
if (!isset($_POST))   $_POST ??= [];
```

**Correção aplicada:** shim corrigido em `libs/db_stdlib.php`:

```php
$GLOBALS["HTTP_SERVER_VARS"]  = $_SERVER  ?? [];
$GLOBALS["HTTP_POST_VARS"]    = $_POST    ?? [];
$GLOBALS["HTTP_GET_VARS"]     = $_GET     ?? [];
$GLOBALS["HTTP_COOKIE_VARS"]  = $_COOKIE  ?? [];
$GLOBALS["HTTP_SESSION_VARS"] = $_SESSION ?? [];
$GLOBALS["HTTP_ENV_VARS"]     = $_ENV     ?? [];
// Cria tambem aliases por referencia no escopo global:
$HTTP_SERVER_VARS  = &$GLOBALS["HTTP_SERVER_VARS"];
$HTTP_POST_VARS    = &$GLOBALS["HTTP_POST_VARS"];
$HTTP_GET_VARS     = &$GLOBALS["HTTP_GET_VARS"];
$HTTP_COOKIE_VARS  = &$GLOBALS["HTTP_COOKIE_VARS"];
$HTTP_SESSION_VARS = &$GLOBALS["HTTP_SESSION_VARS"];
$HTTP_ENV_VARS     = &$GLOBALS["HTTP_ENV_VARS"];
```

Cobertura: tanto usos `$GLOBALS["HTTP_*_VARS"]` (78.000+) quanto usos diretos `$HTTP_POST_VARS` (19.000+) passam a funcionar sem alterar nenhum dos 9.778 arquivos consumidores.

**Resultado:** 1 arquivo modificado (`libs/db_stdlib.php`), shim de 12 linhas. Junto com este commit (`47e0ef209`) também foram corrigidos manualmente 2 construtores PHP 4 em classes `_ext` que escaparam do hotfix3 — `cl_mer_nutricionista_ext` e `cl_mer_requiitem_ext` (declaravam construtor com o nome da classe pai, não da `_ext`).

**Estratégia para automatizar na `edu-deps`:**

O shim em `db_stdlib.php` é estável e suficiente para o e-cidade (ponto único de injeção). Mas para outros projetos PHP legados, a ferramenta pode oferecer:

- Comando `bin/edu-deps inject-shim --target=db_stdlib.php --vars=HTTP_SERVER_VARS,HTTP_POST_VARS,...` que adiciona shim análogo no bootstrap apropriado.
- Detecção automática de superglobais PHP 4 em uso (`grep -E "\\\$HTTP_[A-Z]+_VARS"`) e sugestão do shim antes da execução do Rector.

---

## Checagem sistemática do módulo Alimentação Escolar (Merenda) — 07/06/2026

A pedido do orientador, a ferramenta foi usada para validar a saúde do módulo após todos os hotfixes anteriores. Critérios verificados:

| Categoria | Total no módulo | Pendente após hotfixes |
|-----------|------------------|--------------------------|
| `mer*.php` na raiz | 187 | — |
| `classes/db_mer*.php` | 43 | — |
| `forms/db_frmmer*.php` | 42 | — |
| Short open tag `<?` | — | **0** ✓ |
| Construtor PHP 4 (`function NomeClasse()`) | — | **2** → 0 ✓ (corrigidos manualmente) |
| `#[\Override]` em propriedade | — | **0** ✓ |
| Arquivos com uso de `$HTTP_*_VARS` | — | 194 (cobertos pelo shim universal — não precisam ser alterados) |
| Arquivos com parse errors sintáticos | — | a confirmar em próximo run |

**Conclusão da checagem:** módulo Alimentação Escolar estruturalmente OK após hotfix5. Funcionalidade "Cadastro de Nutricionista" já confirmada funcionando pelo usuário. As outras telas do módulo (Cardápio, Refeições, Requisição, etc.) devem ser validadas em sequência.

---

## Checagem sistemática do submódulo Materiais/Procedimentos — 07/06/2026

Pedido após relato de erro em "Alimentação Escolar → Procedimentos → Atendimento de Requisição" e "Materiais → Requisição de Saída → Inclusão". Causa raiz já corrigida no hotfix6 (short tags em qualquer posição), mas a checagem foi feita para confirmar:

| Categoria | Total no submódulo | Pendente após hotfixes |
|-----------|----------------------|--------------------------|
| `mat*.php` na raiz | 281 | — |
| `classes/db_mat*.php` | 77 | — |
| `forms/db_frmmat*.php` | 52 | — |
| Short open tag `<?` (qualquer posição) | — | **0** ✓ (após hotfix6) |
| Construtor PHP 4 | — | **0** ✓ |
| `#[\Override]` em propriedade | — | **0** ✓ |
| Construtor PHP 4 em `_ext` classes | — | **0** ✓ |

**Conclusão:** submódulo estruturalmente OK após hotfix6. O usuário deve validar funcionalmente em sequência (Atendimento de Requisição, Saída de Materiais, Inclusão/Alteração/Exclusão).

---

## Próximas regressões esperadas (não confirmadas ainda)

Hipóteses baseadas nas regras Rector aplicadas em alto volume:

- **`ExplicitNullableParamTypeRector` (112 ocorrências):** pode quebrar herança se classe filha não receber o mesmo tipo `?Type` adicionado na base.
- **`AddOverrideAttributeToOverriddenMethodsRector` (133 ocorrências):** se um método pai mudou de assinatura em outro lugar, o filho com `#[\Override]` pode dar erro.
- **`OptionalParametersAfterRequiredRector` (61 ocorrências):** reordena parâmetros — qualquer caller que passa por posição quebra.

Cada uma delas, quando aparecer, vira nova entrada neste documento + nova regra no comando `edu-deps fix-regressions`.

---

## Catálogo formal (futuro)

A meta é converter este `.md` em um YAML estruturado que a `edu-deps` consome:

```yaml
regressions:
  - id: rector-override-on-property
    detection: ast-pattern
    pattern: "Property with Attribute('Override')"
    fix: remove-attribute
    prevention:
      skip_rule: Rector\Php83\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector
  - id: php8-error-handler-context
    detection: ast-pattern
    pattern: "callback registered in set_error_handler with >4 required params"
    fix: add-default-null-to-extra-params
    prevention: null  # bug pré-existente, não decorrente de regra Rector
```

Esse catálogo é a contribuição central da versão futura da `edu-deps`: ela deixa de ser apenas um descobridor de paths e passa a ser também um **guardião contra regressões conhecidas** específicas de PHP legado migrado por Rector.
