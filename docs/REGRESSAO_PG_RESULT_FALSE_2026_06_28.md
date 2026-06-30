# Regressao pg_numrows false/null - 28/06/2026

## Sintoma

Na tela Informacoes dos alunos > registro, o e-cidade falhou em runtime:

```text
TypeError: pg_numrows(): Argument #1 ($result) must be of type PgSql\Result, false given
in dbforms/db_classes_iframe_alterar_excluir.php on line 153
```

## Causa raiz

`db_query($sql)` pode retornar `false` quando a consulta dinamica falha. No
PHP legado, chamadas como `@pg_numrows(false)` geravam warning suprimido pelo
operador `@`. No PHP 8+, a extensao PostgreSQL valida tipos antes do retorno e
lança `TypeError`; `@` nao suprime excecoes/TypeError.

Esse e um caso de rejuvenescimento, nao de refatoracao: a regra de negocio nao
deve mudar. O comportamento compativel e tratar resultado invalido como zero
linhas/colunas e deixar a tela cair no fluxo ja existente de "sem registros".

## Correcao aplicada no e-cidade

Arquivo:

```text
dbforms/db_classes_iframe_alterar_excluir.php
```

Padrao aplicado:

```php
$numrows = ($result === false || $result === null) ? 0 : @pg_numrows($result);
$numcols = ($result === false || $result === null) ? 0 : @pg_numfields($result);
```

Tambem foram protegidos os resultsets auxiliares do mesmo arquivo:
`$result01`, `$res_servico`, `$res_reservasaldo` e `$res_comparar`.

## Melhoria aplicada no rector-helper

Nova regra no catalogo:

```text
pg-result-false-typeerror
```

Novo fixer AST:

```text
EduDeps\Fixer\PgResultGuardFixer
```

Ele transforma atribuicoes diretas com `pg_numrows`, `pg_num_rows`,
`pg_numfields` e `pg_num_fields` sobre variavel em uma expressao ternaria com
guard `false/null`, preservando a chamada original no caminho valido.

## Validacao

- Lint PHP 8.4.21 no arquivo corrigido: sem erros.
- Testes do rector-helper: 59 testes, 251 assercoes.
- Dry-run da nova regra no arquivo ja corrigido: 0 ocorrencias.
- Dry-run ampliado em `/dbforms,/libs`: 33 arquivos e 147 ocorrencias
  candidatas, ainda nao aplicadas em massa.

## Decisao de escopo

Por enquanto, a aplicacao em massa da regra foi adiada. A correcao feita no
e-cidade ficou restrita ao arquivo que quebrou em runtime. O dry-run ampliado
serve como fila de risco para as proximas validacoes funcionais.
