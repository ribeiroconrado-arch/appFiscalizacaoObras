<?php

namespace App\Cadastro;

use Illuminate\Support\Facades\DB;

/**
 * A REFERÊNCIA que o app desktop do cadastro usa para saber o que mudou.
 *
 * O app roda no PC da prefeitura, lê a planilha mensal e gera um JSON só com
 * os imóveis novos ou alterados (ver ferramentas/cadastro-desktop). Para isso
 * ele precisa saber o que o sistema JÁ TEM — mas sem uma cópia da base no PC,
 * que seria uma segunda cópia de CPF fora do servidor. A referência resolve:
 * por imóvel, só a inscrição, o código de conferência (o `hash` que a carga
 * mensal já grava), o bairro e se está ausente. Nenhum dado pessoal.
 *
 * A `conferencia` é a impressão digital da referência inteira. O JSON gerado
 * a devolve, e a carga só é aplicada se o banco ainda estiver no MESMO estado:
 * JSON repetido, gerado sobre referência velha ou fora de ordem é recusado —
 * aplicá-lo contaria como "igual" um imóvel que mudou no meio do caminho.
 */
final class ReferenciaDoCadastro
{
    public const FORMATO = 'fiscobras-cadastro-referencia';

    public const VERSAO = 1;

    /** A última carga concluída — é sobre ela que a referência foi tirada. */
    public static function baseCargaId(): ?int
    {
        $id = DB::table('cadastro_cargas')->where('status', 'concluida')->max('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Os imóveis como o app os recebe: [inscrição, hash|null, bairro, ausente 0/1],
     * em ordem de inscrição (comparada em PHP, não pela collation do banco).
     *
     * @return list<array{0:string, 1:?string, 2:string, 3:int}>
     */
    public static function imoveis(): array
    {
        $lista = [];
        foreach (DB::table('cadastro_externo_imoveis')
                     ->select('inscricao', 'hash', 'codigo_bairro', 'ausente_desde_carga_id')
                     ->cursor() as $l) {
            $lista[] = [(string) $l->inscricao, $l->hash, ltrim((string) $l->codigo_bairro, '0'),
                $l->ausente_desde_carga_id === null ? 0 : 1];
        }
        usort($lista, fn ($a, $b) => strcmp($a[0], $b[0]));

        return $lista;
    }

    /** @param list<array> $imoveis o que `imoveis()` devolveu */
    public static function conferencia(?int $base, array $imoveis): string
    {
        $ctx = hash_init('sha256');
        hash_update($ctx, 'base:' . ($base ?? 0) . "\n");
        foreach ($imoveis as [$insc, $hash, $bairro, $ausente]) {
            hash_update($ctx, "{$insc}\t" . ($hash ?? '') . "\t{$bairro}\t{$ausente}\n");
        }

        return hash_final($ctx);
    }

    /** @return array<string,mixed> o arquivo que o administrador baixa */
    public static function gerar(): array
    {
        $base = self::baseCargaId();
        $imoveis = self::imoveis();

        return [
            'formato'       => self::FORMATO,
            'versao'        => self::VERSAO,
            'gerada_em'     => now()->toIso8601String(),
            'base_carga_id' => $base,
            'conferencia'   => self::conferencia($base, $imoveis),
            'total'         => count($imoveis),
            'imoveis'       => $imoveis,
        ];
    }

    /** A conferência do banco AGORA — comparada com a que o JSON trouxe. */
    public static function conferenciaAtual(): string
    {
        return self::conferencia(self::baseCargaId(), self::imoveis());
    }
}
