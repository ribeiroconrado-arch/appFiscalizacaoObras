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
 * por imóvel, a inscrição, o código de conferência (o `hash` que a carga
 * mensal já grava), o bairro, se está ausente e os dados do IMÓVEL (endereço,
 * medidas, setor — para manter o que a planilha não traz). Nada do
 * proprietário: nenhum nome, CPF ou endereço de correspondência.
 *
 * A `conferencia` é a impressão digital da referência inteira. O JSON gerado
 * a devolve, e a carga só é aplicada se o banco ainda estiver no MESMO estado:
 * JSON repetido, gerado sobre referência velha ou fora de ordem é recusado —
 * aplicá-lo contaria como "igual" um imóvel que mudou no meio do caminho.
 */
final class ReferenciaDoCadastro
{
    public const FORMATO = 'fiscobras-cadastro-referencia';

    /**
     * 2 (04/11/2026): cada imóvel leva também os DADOS DO IMÓVEL (`campos()`),
     * para o app manter o que a planilha não traz — sem eles, coluna ausente
     * virava campo apagado. Continua sem nada do proprietário.
     */
    public const VERSAO = 2;

    /**
     * Os campos do imóvel que vão na referência: tudo o que entra no código de
     * conferência, menos a inscrição (que já é a chave). Nome e CPF ficam fora:
     * estão no proprietário, que não vai.
     *
     * @return list<string>
     */
    public static function campos(): array
    {
        return array_merge(array_values(array_diff(array_values(ColunasDaExportacao::CAMPOS), ['inscricao'])),
            ['logradouro', 'caracteristicas']);
    }

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
     * Com `$comDados`, um quinto item: os valores de `campos()`, na forma
     * canônica, na mesma ordem. A conferência não os usa.
     *
     * @return list<array{0:string, 1:?string, 2:string, 3:int, 4?:list<?string>}>
     */
    public static function imoveis(bool $comDados = false): array
    {
        $campos = self::campos();
        $lista = [];
        foreach (DB::table('cadastro_externo_imoveis')
                     ->select($comDados ? ['inscricao', 'hash', 'ausente_desde_carga_id', ...$campos]
                         : ['inscricao', 'hash', 'codigo_bairro', 'ausente_desde_carga_id'])
                     ->cursor() as $l) {
            $item = [(string) $l->inscricao, $l->hash, ltrim((string) $l->codigo_bairro, '0'),
                $l->ausente_desde_carga_id === null ? 0 : 1];
            if ($comDados) {
                $item[] = array_map(fn ($c) => $c === 'caracteristicas' ? self::caracteristicas($l->caracteristicas)
                    : ColunasDaExportacao::canonico($c, $l->{$c}), $campos);
            }
            $lista[] = $item;
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
        $imoveis = self::imoveis(true);

        return [
            'formato'       => self::FORMATO,
            'versao'        => self::VERSAO,
            'gerada_em'     => now()->toIso8601String(),
            'base_carga_id' => $base,
            'conferencia'   => self::conferencia($base, $imoveis),
            'total'         => count($imoveis),
            'campos'        => self::campos(),
            'imoveis'       => $imoveis,
        ];
    }

    /** Características como o código de conferência as vê (DiferencaDoCadastro::caracteristicas). */
    private static function caracteristicas(?string $json): ?string
    {
        $a = $json ? json_decode($json, true) : null;
        if (! is_array($a) || ! $a) {
            return null;
        }
        ksort($a);

        return json_encode($a, JSON_UNESCAPED_UNICODE);
    }

    /** A conferência do banco AGORA — comparada com a que o JSON trouxe. */
    public static function conferenciaAtual(): string
    {
        return self::conferencia(self::baseCargaId(), self::imoveis());
    }
}
