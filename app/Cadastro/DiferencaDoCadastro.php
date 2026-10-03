<?php

namespace App\Cadastro;

/**
 * O que mudou num imóvel entre duas cargas do cadastro.
 *
 * Regra pura, sem banco: recebe o registro antigo (como está na tabela) e o
 * novo (como veio da planilha, já canônico) e devolve só os campos que mudaram.
 * Os dois lados passam pela MESMA forma canônica antes de comparar — o banco
 * devolve "350.00" e a planilha "350", e isso não é mudança.
 */
final class DiferencaDoCadastro
{
    /** Campos que não são dado do imóvel: controle da carga. */
    private const IGNORADOS = [
        'id', 'hash', 'arquivo_origem', 'importado_em', 'created_at', 'updated_at',
        'vista_na_carga_id', 'alterado_na_carga_id', 'ausente_desde_carga_id',
        'fracao_ideal', 'unidade_padrao',
    ];

    /**
     * @param  array<string,mixed>  $antes
     * @param  array<string,?string>  $depois
     * @return array<string, array{0:?string, 1:?string}>  campo => [antes, depois]
     */
    public static function campos(array $antes, array $depois): array
    {
        $mudou = [];
        foreach ($depois as $campo => $novo) {
            if (in_array($campo, self::IGNORADOS, true)) {
                continue;
            }
            $velho = $campo === 'caracteristicas'
                ? self::caracteristicas($antes[$campo] ?? null)
                : ColunasDaExportacao::canonico($campo, $antes[$campo] ?? null);
            $novo = $campo === 'caracteristicas' ? self::caracteristicas($novo) : $novo;

            if ($velho !== $novo) {
                $mudou[$campo] = [$velho, $novo];
            }
        }

        return $mudou;
    }

    /**
     * Proprietários como texto comparável: mesma ordem, mesmos campos.
     *
     * @param  list<array{nome:?string, documento:?string, endereco:?string}>  $donos
     */
    public static function proprietarios(array $donos): ?string
    {
        if (! $donos) {
            return null;
        }

        return json_encode(array_map(fn ($d) => [
            'nome'      => $d['nome'] ?? null,
            'documento' => $d['documento'] ?? null,
            'endereco'  => $d['endereco'] ?? null,
        ], array_values($donos)), JSON_UNESCAPED_UNICODE);
    }

    /**
     * A impressão digital do imóvel: o registro canônico mais os proprietários.
     * Igual = nada mudou, e a carga não grava nada.
     *
     * @param  array<string,?string>  $registro
     * @param  list<array>  $donos
     */
    public static function hash(array $registro, array $donos): string
    {
        ksort($registro);
        $registro['caracteristicas'] = self::caracteristicas($registro['caracteristicas'] ?? null);

        return sha1(json_encode([$registro, self::proprietarios($donos)], JSON_UNESCAPED_UNICODE));
    }

    /** JSON de características normalizado (o MySQL reordena e reespaça o JSON). */
    private static function caracteristicas(mixed $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }
        $a = is_array($json) ? $json : json_decode((string) $json, true);
        if (! is_array($a) || ! $a) {
            return null;
        }
        ksort($a);

        return json_encode($a, JSON_UNESCAPED_UNICODE);
    }
}
