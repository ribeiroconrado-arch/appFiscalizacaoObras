<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desvira os desenhos guardados com latitude e longitude trocadas.
 *
 * `LotesApagados::guardar` lia o polígono com `ST_AsText(geom)` — que, em
 * EPSG:4326, escreve LATITUDE primeiro — e o regravava com
 * `axis-order=long-lat`, que lê LONGITUDE primeiro. Todo lote apagado pela
 * tela ficou guardado espelhado, e o "desfazer" o devolvia assim para `lotes`:
 * em latitude −54, no Atlântico Sul, longe de Primavera do Leste (−15,5).
 *
 * Identifica o virado pela posição, e não por data: Primavera do Leste fica
 * perto de −15,5 de latitude, então latitude abaixo de −30 só existe no desenho
 * espelhado. As cópias feitas pela exclusão de importação (que copiam `geom`
 * direto, sem passar por texto) estão certas e não são tocadas.
 *
 * Vale para `lotes_apagados` e para os lotes que o "desfazer" já devolveu ao
 * mapa. `ST_SwapXY` é a própria inversa: o `down()` não desfaz, porque voltar
 * ao estado errado não tem utilidade.
 */
return new class extends Migration
{
    private const VIRADO = 'ST_Latitude(ST_PointN(ST_ExteriorRing(geom), 1)) < -30';

    public function up(): void
    {
        foreach (['lotes_apagados', 'lotes'] as $tabela) {
            DB::update("UPDATE {$tabela} SET geom = ST_SwapXY(geom) WHERE " . self::VIRADO);
        }
    }

    public function down(): void
    {
        // Sem volta de propósito — ver o comentário da classe.
    }
};
