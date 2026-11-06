<?php

namespace App\Cadastro;

use Illuminate\Support\Str;

/**
 * NOME DO BAIRRO ENCURTADO PARA CABER (06/11/2026).
 *
 * "CONDOMÍNIO RESIDENCIAL VILLA PADOVA" não cabe na linha da impressão
 * térmica; "COND. RESID. VILLA PADOVA" cabe. A abreviação é feita SÓ NA HORA
 * DE MOSTRAR — o nome gravado continua inteiro — e SÓ ONDE O NOME NÃO CABE:
 * quem chama diz quantas letras cabem, e o nome que já cabe fica como está.
 * O A4, a ficha e os formulários mostram sempre o nome inteiro.
 *
 * Ordem: primeiro a palavra do início, depois as do meio, uma por vez, até
 * caber. Tabela aprovada por ele em 06/11/2026; só entra palavra que economize
 * pelo menos 3 letras (por isso "Vila" ficou de fora). A mesma tabela está em
 * public/js/nome-do-bairro.js — mudou aqui, muda lá.
 */
final class NomeDoBairro
{
    /** Quantas letras cabem numa linha de resumo. */
    public const CABE_LINHA = 22;

    /** As palavras sem acento, em maiúsculas → a abreviação, e se vale só fora da 1ª palavra. */
    private const ABREVIACOES = [
        [['DISTRITO', 'INDUSTRIAL'], 'DIST. IND.', false],
        [['LOTEAMENTO'], 'LOT.', false],
        [['JARDIM'], 'JD.', false],
        [['RESIDENCIAL'], 'RESID.', false],
        [['PARQUE'], 'PQ.', false],
        [['CONDOMINIO'], 'COND.', false],
        [['CONJUNTO'], 'CONJ.', false],
        [['CHACARA'], 'CHÁC.', false],
        [['CHACARAS'], 'CHÁC.', false],
        [['AMPLIACAO'], 'AMPL.', true],
        [['EXPANSAO'], 'EXP.', true],
    ];

    /** Nomes próprios que contêm uma palavra da tabela: "CIDADE JARDIM" não vira "CIDADE JD.". */
    private const SEM_ABREVIAR = [['CIDADE', 'JARDIM']];

    /** O nome do bairro com no máximo `$cabe` letras, se der. */
    public static function curto(?string $nome, int $cabe = self::CABE_LINHA): string
    {
        $texto = trim((string) $nome);
        if (mb_strlen($texto) <= $cabe) {
            return $texto;
        }
        $p = preg_split('/\s+/u', $texto);
        $chave = array_map([self::class, 'palavra'], $p);

        $achados = [];
        for ($i = 0; $i < count($p); $i++) {
            foreach (self::ABREVIACOES as [$de, $para, $meio]) {
                if (array_slice($chave, $i, count($de)) !== $de || ($meio && $i === 0) || self::protegida($chave, $i)) {
                    continue;
                }
                $achados[] = [$i, $de, $para];
                $i += count($de) - 1;
                break;
            }
        }

        // Uma abreviação a mais por volta, até caber; dentro da volta, de trás para a frente para os índices não andarem.
        $usados = [];
        $atual = $texto;
        foreach ($achados as $ac) {
            $usados[] = $ac;
            $q = $p;
            foreach (array_reverse($usados) as [$i, $de, $para]) {
                $n = count($de);
                preg_match('/^[^\p{L}\p{N}]*/u', $q[$i], $ini);
                preg_match('/[^\p{L}\p{N}]*$/u', $q[$i + $n - 1], $fim);
                $original = implode(' ', array_slice($p, $i, $n));
                array_splice($q, $i, $n, [$ini[0] . self::naCaixa($para, $original) . preg_replace('/^\./', '', $fim[0])]);
            }
            $atual = implode(' ', $q);
            if (mb_strlen($atual) <= $cabe) {
                break;
            }
        }

        return $atual;
    }

    /** A palavra sem acento, em maiúsculas, sem parêntese nem pontuação em volta. */
    private static function palavra(string $p): string
    {
        return preg_replace('/^[^A-Z0-9]+|[^A-Z0-9]+$/', '', Str::upper(Str::ascii($p)));
    }

    private static function protegida(array $chave, int $i): bool
    {
        foreach (self::SEM_ABREVIAR as $frase) {
            foreach (array_keys($frase) as $k) {
                if ($i - $k >= 0 && array_slice($chave, $i - $k, count($frase)) === $frase) {
                    return true;
                }
            }
        }

        return false;
    }

    /** "JD." no nome em maiúsculas; "Jd." no nome escrito normal. */
    private static function naCaixa(string $ab, string $original): string
    {
        return $original === mb_strtoupper($original) ? $ab : mb_convert_case(mb_strtolower($ab), MB_CASE_TITLE);
    }
}
