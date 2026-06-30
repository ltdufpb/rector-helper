# Regressao parse_str em Alimentacao Escolar/Procedimentos - 28/06/2026

## Sintoma

Ao abrir a tela de Atendimento/Devolucao de Materiais em Alimentacao Escolar >
Procedimentos, o e-cidade falhava em runtime:

```text
Uncaught exception 'ArgumentCountError' with message
'parse_str() expects exactly 2 arguments, 1 given'
in func_atendrequi.php on line 35
```

## Causa raiz

Nao era um caso que exigia correcao manual. A regra
`parse-str-sem-segundo-arg` e o `ParseStrFixer` ja corrigiam esse padrao.

O problema era de cobertura do grafo: `func_atendrequi.php` nao entrava no
scan porque o fluxo anterior partia apenas de `edu*.php`. Essa tela e uma
entrada direta de menu/procedimento ligada a Alimentacao Escolar e Materiais,
nao uma dependencia incluida estaticamente por um arquivo `edu*.php`.

## Melhoria aplicada no rector-helper

O comando `scan` agora aceita multiplos globs separados por virgula em
`--seeds-glob`. O padrao do recorte de Educacao passou a incluir:

```text
edu*.php,mer*.php,func_mer_*.php,func_atendrequi.php
```

Com isso, o scan ampliado de Educacao + Alimentacao Escolar encontrou:

```text
seeds: 1399
nodes: 3852
edges: 21148
unresolved: 128
```

A varredura AST sobre `out-edu-mer-procedimentos/files.txt` encontrou apenas
1 chamada `parse_str()` de um argumento no grafo ampliado:

```text
func_atendrequi.php:35
```

Na validacao posterior de Alimentacao Escolar > Cadastros, os fluxos de
alteracao e exclusao revelaram mais 41 chamadas pendentes em arquivos
`func_mer_*`. Esses arquivos sao pontos diretos dos fluxos de manutencao e,
por isso, tambem foram incluidos no seed padrao do scan.

## Correcao aplicada no e-cidade

Aplicada via:

```powershell
php bin\edu-deps fix-regressions --project-root C:\Pessoal\Estudo\Faculdade\TCC\e-cidade --rule=parse-str-sem-segundo-arg --include=/func_atendrequi.php
```

Diff gerado:

```php
parse_str($HTTP_SERVER_VARS["QUERY_STRING"], $_parseStr);
extract($_parseStr, EXTR_SKIP);
```

Isso e rejuvenescimento de compatibilidade PHP 8: preserva a semantica legada
de importar variaveis da query string para o escopo local e nao altera regra de
negocio.

## Validacao

- `docker exec ecidade_php56 php -l /var/www/html/func_atendrequi.php`: sem erros.
- Dry-run idempotente da regra no arquivo: 0 arquivos afetados, 0 ocorrencias.
- Testes do rector-helper: 57 testes, 237 assercoes.
