<?php

namespace App\Support;

/**
 * A inscrição imobiliária do município, e o único lugar que conhece o formato.
 *
 *   XX . XXX . XXX . XXXX . XXX
 *   01   124   004   0020   000
 *   ↑    ↑     ↑     ↑      ↑
 *   │    │     │     │      └── variação: 000 no lote original, e o sufixo que
 *   │    │     │     │          cada parte ganha quando ele é desmembrado
 *   │    │     │     └───────── lote, 4 dígitos
 *   │    │     └─────────────── quadra, 3 dígitos
 *   │    └───────────────────── código do bairro, 3 dígitos (cadastro_bairros)
 *   └────────────────────────── setor: 01 é o urbano
 *
 * São 15 dígitos. Os pontos são de leitura — o que se guarda e se compara é o
 * número puro, porque a mesma inscrição chega com pontos da tela e sem pontos
 * da exportação da prefeitura, e duas grafias da mesma coisa não podem
 * significar dois imóveis.
 *
 * ── Por que uma classe, e não um sprintf espalhado ──
 *
 * A regra vive em três lugares que precisam concordar: a busca (que recebe o
 * que o fiscal digita), a ficha (que mostra) e o casamento com o cadastro
 * externo (que compara). Escrita três vezes, ela diverge na primeira correção
 * — e o sintoma seria um imóvel que a busca acha e a ficha não reconhece.
 *
 * A conferência contra as 990 inscrições reais já carregadas está em
 * `inscricao:conferir`: 984 batem, e as 6 que não batem são divergências DA
 * EXPORTAÇÃO (a coluna `lote` discorda do lote embutido na própria inscrição),
 * não da fórmula.
 */
final class InscricaoImobiliaria
{
    /** Setor urbano — o único que o sistema trata hoje. */
    public const URBANO = '01';

    /** Quantos dígitos cada parte ocupa, na ordem. */
    private const TAMANHOS = [
        'setor'   => 2,
        'bairro'  => 3,
        'quadra'  => 3,
        'lote'    => 4,
        'variacao' => 3,
    ];

    /**
     * Monta a inscrição a partir das partes.
     *
     * Aceita "1", "001" ou 1 em qualquer uma delas: zero à esquerda é
     * formatação, não identidade — o GIS grava "1" e o cadastro, "001".
     *
     * @param  int|string|null  $bairro    código do bairro (cadastro_bairros)
     * @param  int|string|null  $quadra
     * @param  int|string|null  $lote
     * @param  int|string|null  $variacao  sufixo de desmembramento (0 = original)
     * @return string|null  15 dígitos, ou null se faltar bairro, quadra ou lote
     */
    public static function montar($bairro, $quadra, $lote, $variacao = 0, string $setor = self::URBANO): ?string
    {
        // Sem qualquer uma das três, não há inscrição: preencher com zero
        // inventaria o imóvel 0000 da quadra 000, que existe em outro lugar.
        if (! self::preenchido($bairro) || ! self::preenchido($quadra) || ! self::preenchido($lote)) {
            return null;
        }

        $parte = fn ($v, string $qual) => str_pad(
            (string) (int) preg_replace('/\D/', '', (string) $v),
            self::TAMANHOS[$qual],
            '0',
            STR_PAD_LEFT
        );

        $numero = $setor
            . $parte($bairro, 'bairro')
            . $parte($quadra, 'quadra')
            . $parte($lote, 'lote')
            . $parte($variacao ?? 0, 'variacao');

        // Um número maior que a casa dele não é uma inscrição comprida: é um
        // dado que não cabe no formato, e dizer isso é melhor que truncar.
        return strlen($numero) === 15 ? $numero : null;
    }

    /**
     * Os 15 dígitos com os pontos: 01.124.004.0020.000.
     *
     * @param  string|null  $inscricao
     */
    public static function formatar(?string $inscricao): ?string
    {
        $n = self::normalizar($inscricao);
        if ($n === null) {
            return null;
        }

        return implode('.', [
            substr($n, 0, 2), substr($n, 2, 3), substr($n, 5, 3),
            substr($n, 8, 4), substr($n, 12, 3),
        ]);
    }

    /**
     * Só os dígitos, e só se forem 15.
     *
     * É por aqui que passa tudo que vem de fora — o que o fiscal digita com
     * pontos, o que a exportação traz sem eles.
     *
     * @param  string|null  $inscricao
     */
    public static function normalizar(?string $inscricao): ?string
    {
        $n = preg_replace('/\D/', '', (string) $inscricao);

        return strlen($n) === 15 ? $n : null;
    }

    /**
     * Desmonta a inscrição nas partes que a compõem.
     *
     * Os números saem SEM zero à esquerda, que é como o desenho os guarda —
     * é assim que o resultado serve para procurar o lote.
     *
     * @return array{setor:string,bairro:int,quadra:int,lote:int,variacao:int}|null
     */
    public static function partes(?string $inscricao): ?array
    {
        $n = self::normalizar($inscricao);
        if ($n === null) {
            return null;
        }

        return [
            'setor'    => substr($n, 0, 2),
            'bairro'   => (int) substr($n, 2, 3),
            'quadra'   => (int) substr($n, 5, 3),
            'lote'     => (int) substr($n, 8, 4),
            'variacao' => (int) substr($n, 12, 3),
        ];
    }

    /** @param  int|string|null  $v */
    private static function preenchido($v): bool
    {
        return $v !== null && $v !== '' && preg_replace('/\D/', '', (string) $v) !== '';
    }
}
