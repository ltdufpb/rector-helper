<?php

require_once(modification("libs/db_stdlib.php"));
require_once(modification("libs/db_utils.php"));
include(modification("classes/db_aluno_classe.php"));

require_once "libs/literal_include.php";

if ($acao == 'A') {
    require_once(modification("libs/condicional_ok.php"));
}

$x = $base . '/dynamic.php';
include $x;
