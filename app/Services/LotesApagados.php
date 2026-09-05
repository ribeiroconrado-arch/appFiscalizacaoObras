<?php

namespace App\Services;

use App\Models\Lote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guarda o desenho do lote antes de ele ser apagado, e o traz de volta.
 *
 * Ver a migração `lotes_apagados` para o porquê da tabela. Em resumo: a
 * auditoria da exclusão guarda todos os campos menos `geom`, e sem o desenho não
 * há o que restaurar.
 */
class LotesApagados
{
    /**
     * Copia o lote inteiro — com a geometria — para a tabela de apagados.
     *
     * Chamada ANTES do `delete()`, dentro da mesma transação: se a exclusão
     * falhar, a cópia não fica sobrando; se a cópia falhar, o lote não é
     * apagado. Uma das duas sozinha seria pior que nenhuma.
     */
    public function guardar(Lote $lote, ?string $motivo): void
    {
        // A geometria vem por SQL porque o escopo global `sem_geometria` a tira
        // de qualquer consulta do Eloquent — é justamente por isso que ela
        // nunca chegou à auditoria. Aqui ela é pedida explicitamente.
        $geom = DB::selectOne('SELECT ST_AsText(geom) AS wkt FROM lotes WHERE id = ?', [$lote->id]);

        if (! $geom?->wkt) {
            throw new RuntimeException(
                'O lote ' . $lote->id . ' não tem desenho no banco. '
                . 'Apagar sem guardar deixaria a exclusão sem volta.'
            );
        }

        DB::table('lotes_apagados')->insert([
            'lote_id'               => $lote->id,
            'bairro'                => $lote->bairro,
            'quadra'                => $lote->quadra,
            'numero_lote'           => $lote->numero_lote,
            'desmembramento'        => $lote->desmembramento ?? 0,
            'chave'                 => $lote->chave,
            'inscricao_imobiliaria' => $lote->inscricao_imobiliaria,
            'area_gis_m2'           => $lote->area_gis_m2,
            'frente_m'              => $lote->frente_m,
            'fundos_m'              => $lote->fundos_m,
            'lado_direito_m'        => $lote->lado_direito_m,
            'lado_esquerdo_m'       => $lote->lado_esquerdo_m,
            'area_matricula_m2'     => $lote->area_matricula_m2,
            'fonte'                 => $lote->fonte,
            'origem'                => $lote->origem,
            'geom'                  => DB::raw("ST_GeomFromText('{$geom->wkt}', 4326, 'axis-order=long-lat')"),
            'user_id'               => Auth::id(),
            'usuario_nome'          => Auth::user()?->name ?? 'sistema',
            'motivo'                => $motivo,
            'apagado_em'            => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    /**
     * Devolve o lote ao desenho, com ID NOVO.
     *
     * Reciclar o id antigo faria vistorias, documentos e protocolos órfãos — que
     * ficaram apontando para um número que deixou de existir — voltarem a
     * apontar para ALGO. E esse algo seria outro imóvel, com outro dono e outro
     * processo. Id novo é a única forma de a restauração não inventar vínculos.
     *
     * @return Lote o lote restaurado
     */
    public function restaurar(int $id): Lote
    {
        $a = DB::table('lotes_apagados')->where('id', $id)->first();

        if (! $a) {
            throw new RuntimeException('Este registro de exclusão não existe mais.');
        }
        if ($a->restaurado_em) {
            throw new RuntimeException(
                'Este lote já foi restaurado em ' . date('d/m/Y', strtotime($a->restaurado_em))
                . ', sob o número ' . $a->restaurado_como . '.'
            );
        }

        return DB::transaction(function () use ($a) {
            // INSERT CRU, e não `Lote::create`.
            //
            // `geom` é NOT NULL sem default, e o Eloquent não tem como levar
            // uma expressão SQL num atributo — o insert sai sem a coluna e o
            // MySQL recusa. É o mesmo caminho que DesenhoDeLote já usa para
            // criar lote desenhado: INSERT com a geometria dentro, e a
            // auditoria registrada à mão logo depois.
            $atributos = [
                'bairro'                => $a->bairro,
                'quadra'                => $a->quadra,
                'numero_lote'           => $a->numero_lote,
                'desmembramento'        => $a->desmembramento,
                'chave'                 => $a->chave,
                'inscricao_imobiliaria' => $a->inscricao_imobiliaria,
                'area_gis_m2'           => $a->area_gis_m2,
                'frente_m'              => $a->frente_m,
                'fundos_m'              => $a->fundos_m,
                'lado_direito_m'        => $a->lado_direito_m,
                'lado_esquerdo_m'       => $a->lado_esquerdo_m,
                'area_matricula_m2'     => $a->area_matricula_m2,
                'origem'                => $a->origem ?: 'importacao',
                // A origem diz de onde o polígono veio, e ele veio de onde
                // vinha antes. É a FONTE que registra o caminho de volta.
                'fonte'                 => 'Restaurado da exclusão de '
                                           . date('d/m/Y', strtotime($a->apagado_em)),
                'situacao'              => 'ativo',
                'created_at'            => now(),
                'updated_at'            => now(),
            ];

            $colunas = implode(', ', array_keys($atributos));
            $marcas  = implode(', ', array_fill(0, count($atributos), '?'));

            DB::insert(
                "INSERT INTO lotes ({$colunas}, geom)
                 SELECT {$marcas}, geom FROM lotes_apagados WHERE id = ?",
                [...array_values($atributos), $a->id]
            );
            $id = (int) DB::getPdo()->lastInsertId();

            // O INSERT foi cru, então o evento `created` não disparou. A trilha
            // é registrada à mão — `registrarAuditoria` é público na trait
            // exatamente para este caso. Sem isto, restaurar seria a única
            // operação do cadastro sem rastro, na tela feita para ter rastro.
            $lote = Lote::findOrFail($id);
            $lote->registrarAuditoria('restaurou', null, $atributos + [
                'apagado_em'  => $a->apagado_em,
                'era_o_lote'  => $a->lote_id,
            ]);

            DB::table('lotes_apagados')->where('id', $a->id)->update([
                'restaurado_em'   => now(),
                'restaurado_como' => $id,
                'updated_at'      => now(),
            ]);

            return $lote;
        });
    }
}
