<?php
// A regra de numeração dos desmembrados (App\Cadastro\SequenciaDoDesmembramento) — sem banco.
require __DIR__.'/../vendor/autoload.php';

use App\Cadastro\SequenciaDoDesmembramento as S;

$ok = fn ($cond, $msg) => $cond ?: throw new RuntimeException($msg);
$igual = fn ($a, $b, $msg) => $ok($a === $b, $msg . ': ' . json_encode($a) . ' ≠ ' . json_encode($b));

// O exemplo dele: 4A = 200, 4B = 300; no cadastro .001 = 300 e .002 = 200 → invertido.
$igual(S::atribuir([200.0, 300.0], [1 => 300.0, 2 => 200.0]), [2, 1], 'Inversão pela área');
// Sem inversão: fica a sequência.
$igual(S::atribuir([300.0, 200.0], [1 => 300.0, 2 => 200.0]), [1, 2], 'Sequência quando a área confirma');
// .000 ativa: é a do A, e o resto segue.
$igual(S::atribuir([250.0, 250.0, 250.0], [0 => 250.0, 1 => 250.0, 2 => 250.0]), [0, 1, 2], '.000 ativa fica com o A');
// .000 inativa (fora da lista): começa na .001.
$igual(S::atribuir([250.0, 250.0], [1 => 250.0, 2 => 250.0]), [1, 2], 'Sem a .000 começa na .001');
// Empate (225 e 225): sequência crescente, mesmo com a ordem das chaves trocada.
$igual(S::atribuir([225.0, 225.0], [2 => 225.0, 1 => 225.0]), [1, 2], 'Empate fica com a sequência');
// Mais letras que inscrições: a que sobra é a de área mais longe.
$igual(S::atribuir([200.0, 300.0, 150.0], [1 => 300.0, 2 => 150.0]), [null, 1, 2], 'Parte a mais sem inscrição');
// Nenhuma inscrição: todas sem.
$igual(S::atribuir([200.0, 300.0], []), [null, null], 'Sem cadastro');
// Área desconhecida no cadastro: decide a sequência.
$igual(S::atribuir([200.0, 300.0], [1 => null, 2 => null]), [1, 2], 'Sem área no cadastro');
// A soma decide, não parte a parte: 4A=260 está mais perto de 250 (.001), mas 4B=245 também,
// e dar a .001 ao 4B (diferença 5) e a .002 ao 4A (diferença 40) soma menos que o contrário (10 + 45).
$igual(S::atribuir([260.0, 245.0], [1 => 250.0, 2 => 300.0]), [2, 1], 'Menor soma das diferenças');
// Muitas partes iguais (quadra inteira como lote): termina, e na sequência.
$igual(S::atribuir(array_fill(0, 20, 300.0), array_fill(1, 20, 300.0)), range(1, 20), '20 partes iguais');

// Ordem no desenho
$igual(S::numeroELetra('05A'), [5, 'A'], 'Lote 05A');
$igual(S::numeroELetra('5'), [5, ''], 'Lote sem letra');
$igual(S::numeroELetra('12AB'), null, '12AB é unificação, fora da regra');

echo "OK: inversão, .000, empate, sobra, soma e letras.\n";
