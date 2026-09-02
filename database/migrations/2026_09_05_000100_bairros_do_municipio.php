<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OS BAIRROS DO MUNICÍPIO, COMO O CADASTRO OS CONHECE.
 *
 * Até aqui o bairro era uma STRING DIGITADA em cada lote — foi por isso que o
 * mesmo bairro entrou grafado de mais de um jeito. Esta carga traz a lista
 * oficial da prefeitura (125 bairros, código 1 a 125), que passa a ser a fonte
 * do combobox no cadastro de lote.
 *
 * ── Por que `nome_gis` passa a aceitar nulo ──
 *
 * `cadastro_bairros` nasceu como a PONTE entre o nome do desenho e o código do
 * cadastro, e `nome_gis` era obrigatório. Só que a lista da prefeitura tem os
 * 125 bairros do município, e o GIS hoje conhece dois — os demais entrariam
 * com um nome de desenho inventado só para satisfazer a coluna, e nome
 * inventado é pior do que campo vazio: ele CASA com alguma coisa um dia.
 *
 * Com nulo, o bairro existe no cadastro desde já e a ponte é amarrada quando o
 * DWG daquele bairro for convertido. O índice único continua valendo para os
 * nomes preenchidos: no MySQL, único não impede vários nulos.
 *
 * ── O que é carregado como veio ──
 *
 * Os nomes entram EXATAMENTE como na planilha, inclusive onde há erro de
 * digitação evidente ("JARDIMLUCIANA", "JARDIM UNIVERSITARIO lI"). Corrigir em
 * silêncio faria a lista do sistema divergir da lista de origem, e a divergência
 * apareceria muito depois, na conferência de um código. A aba Bairros em
 * Parâmetros existe para arrumar isso com o nome de quem arrumou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cadastro_bairros', function (Blueprint $t) {
            $t->string('nome_gis', 160)->nullable()->change();
        });

        $agora = now();

        foreach ($this->lista() as [$codigo, $nome]) {
            // Pelo CÓDIGO, e não pelo nome: recarregar esta lista com um nome
            // corrigido tem de atualizar a linha, não criar uma segunda.
            DB::table('cadastro_bairros')->updateOrInsert(
                ['codigo' => (string) $codigo],
                ['nome_cadastro' => $nome, 'updated_at' => $agora, 'created_at' => $agora],
            );
        }

        $this->amarrarNoDesenho();
    }

    /**
     * Liga cada bairro do cadastro ao nome que o desenho usa, quando os dois
     * nomes são o MESMO texto ignorando acento, caixa e pontuação.
     *
     * Só o casamento exato é feito aqui. Aproximação — "Residencial Buritis V"
     * contra "RESIDENCIAL BURITIS PRIMAVERA V - PRIME" — parece o mesmo bairro
     * e pode não ser; amarrar por semelhança escreveria um vínculo que ninguém
     * conferiu no lugar onde o sistema vai buscar o código do cadastro. Esses
     * ficam para a aba Bairros, onde a escolha tem dono.
     */
    private function amarrarNoDesenho(): void
    {
        $doDesenho = DB::table('lotes')->distinct()->pluck('bairro')->filter();
        if ($doDesenho->isEmpty()) { return; }

        $porChave = [];
        foreach (DB::table('cadastro_bairros')->get() as $b) {
            $porChave[$this->chave($b->nome_cadastro ?? '')] = $b->id;
        }

        foreach ($doDesenho as $nome) {
            $id = $porChave[$this->chave($nome)] ?? null;
            if ($id) {
                DB::table('cadastro_bairros')->where('id', $id)->update(['nome_gis' => $nome]);
            }
        }
    }

    /** Texto comparável: sem acento, sem caixa, sem pontuação, sem espaço dobrado. */
    private function chave(string $s): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
        $s = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', mb_strtoupper($s)));
    }

    public function down(): void
    {
        DB::table('cadastro_bairros')->delete();

        Schema::table('cadastro_bairros', function (Blueprint $t) {
            $t->string('nome_gis', 160)->nullable(false)->change();
        });
    }

    /** @return array<array{0:int,1:string}> código e nome, como na planilha da prefeitura. */
    private function lista(): array
    {
        return [
            [  1, "CIDADE PRIMAVERA I"],
            [  2, "CIDADE PRIMAVERA II"],
            [  3, "PARQUE CASTELANDIA"],
            [  4, "PARQUE CASTELANDIA II"],
            [  5, "JARDIM RIVA"],
            [  6, "PARQUE ELDORADO"],
            [  7, "COHAB TANCREDO NEVES"],
            [  8, "CHACARAS NOVA ESPERANCA"],
            [  9, "CONDOMINIO RESIDENCIAL PRIMAVERA"],
            [ 10, "CONDOMINIO RESIDENCIAL CASTELANDIA"],
            [ 11, "CONDOMINIO RESIDENCIAL PIONEIRO"],
            [ 12, "PARQUE INDUSTRIAL"],
            [ 13, "CONDOMINIO CIDADE JARDIM"],
            [ 14, "CONDOMINIO RESIDENCIAL SERRANO"],
            [ 15, "COHAB JAYME V. DE CAMPOS"],
            [ 16, "CIDADE SATELITE PRIMAVERA III"],
            [ 17, "PARQUE CASTELANDIA III"],
            [ 18, "PARQUE CASTELANDIA IV"],
            [ 19, "JARDIM SERRA DAS FLORES"],
            [ 20, "PARQUE GNOATO"],
            [ 21, "CONJUNTO RESIDENCIAL SÃO JOSÉ"],
            [ 22, "CONDOMÍNIO RESIDENCIAL PLANALTO"],
            [ 23, "JARDIM VOLTA GRANDE"],
            [ 24, "VILA POPULAR"],
            [ 25, "CONJUNTO RESIDENCIAL SÃO CRISTÓVÃO"],
            [ 26, "PARQUE SANTA CLARA"],
            [ 27, "JARDIM RIVA II"],
            [ 28, "CONDOMINIO RESIDENCIAL TUIUIU"],
            [ 29, "DISTRITO INDUSTRIAL"],
            [ 30, "JARDIM PONCHO VERDE"],
            [ 31, "PARQUE CASTELANDIA V"],
            [ 32, "CIDADE PRIMAVERA IV"],
            [ 33, "JARDIM PROGRESSO"],
            [ 34, "JARDIM UNIVERSITÁRIO"],
            [ 35, "JARDIM PROGRESSO II"],
            [ 36, "CONDOMINIO RESIDENCIAL CRISTO REI"],
            [ 37, "LOTEAMENTO VITÓRIA"],
            [ 38, "CHACARAS FONTANA"],
            [ 39, "CONDOMÍNIO RESIDENCIAL VITÓRIA"],
            [ 40, "JARDIM BELA VISTA"],
            [ 41, "RESIDENCIAL FIRENZZE"],
            [ 42, "JOSE DE ALENCAR G. DA SILVA (DISTRITO INDUSTRIAL II)"],
            [ 43, "JARDIM MARINGA"],
            [ 44, "JARDIMLUCIANA"],
            [ 45, "JARDIM VENEZA"],
            [ 46, "JARDIM ITALIA"],
            [ 47, "JARDIM PONCHO VERDE II"],
            [ 48, "JARDIM MILANO"],
            [ 49, "PARQUE CASTELANDIA VI"],
            [ 50, "JARDIM UNIVERSITARIO lI"],
            [ 51, "JARDIM ESPERANÇA"],
            [ 52, "CHACARA SOSSEGO"],
            [ 53, "RESIDENCIAL SANTA CLARA II"],
            [ 54, "JARDIM DAS AMÉRICAS"],
            [ 55, "CONDOMÍNIO RESIDENCIAL VILLA ROMANA"],
            [ 56, "JARDIM DAS AMERICAS II"],
            [ 57, "JARDIM DAS AMERICAS III"],
            [ 58, "JARDIM DAS AMERICAS IV"],
            [ 59, "CHACARAS PERÍMETRO URBANO"],
            [ 60, "RESIDENCIAL BURITIS PRIMAVERA"],
            [ 61, "JARDIM PONCHO VERDE III"],
            [ 62, "JARDIM LUCIANA II"],
            [ 63, "RESIDENCIAL BURITIS PRIMAVERA II"],
            [ 64, "CONDOMÍNIO RESIDENCIAL PORTO SEGURO"],
            [ 65, "JARDIM PARQUE DAS AGUAS"],
            [ 66, "RESIDENCIAL PADRE ONESTO COSTA"],
            [ 67, "RESIDENCIAL BURITIS PRIMAVERA III"],
            [ 68, "CONDOMÍNIO RES. ATLÂNTICO SUL"],
            [ 69, "CONDOMINIO RESIDENCIAL VILLA PADOVA"],
            [ 70, "CONDOMINIO RESIDENCIAL VILLA VENETO"],
            [ 71, "LOTEAMENTO RESIDENCIAL GUTERRES"],
            [ 72, "LOTEAMENTO BELVEDERE"],
            [ 73, "RESIDENCIAL BURITIS PRIMAVERA II EXPANSÃO"],
            [ 74, "PARQUE IMPERIAL A"],
            [ 75, "PARQUE IMPERIAL B"],
            [ 76, "PARQUE IMPERIAL C"],
            [ 77, "LOTEAMENTO TRÊS AMÉRICAS"],
            [ 78, "LOTEAMENTO JARDIM VITÓRIA II"],
            [ 79, "ZONA RURAL"],
            [ 80, "ZONA RURAL - ASSENTAMENTO NOVO PROGRESSO"],
            [ 81, "JARDIM FLORENÇA"],
            [ 82, "JARDIM DAS AMÉRICAS V"],
            [ 83, "JARDIM DAS AMÉRICAS VI"],
            [ 84, "JARDIM DAS AMÉRICAS VII"],
            [ 85, "LOTEAMENTO VERTENTE DAS ÁGUAS"],
            [ 86, "RESIDENCIAL BURITIS PRIMAVERA IV"],
            [ 87, "DISTRITO INDUSTRIAL IV - 'ADEVINO CASTELLI"],
            [ 88, "JARDIM DAS AMÉRICAS VIII"],
            [ 89, "CONDOMINIO RESERVA DA MATA"],
            [ 90, "RESIDENCIAL BURITIS PRIMAVERA V - PRIME"],
            [ 91, "LOTEAMENTO SANTA FELICIDADE"],
            [ 92, "JARDIM EUROPA"],
            [ 93, "JARDIM CALIFORNIA"],
            [ 94, "JARDIM DAS AMÉRICAS IX"],
            [ 95, "ZONA DE EXPANSÃO URBANA INDUSTRIAL - FS"],
            [ 96, "JARDIM EUROPA II"],
            [ 97, "RESIDENCIAL BURITIS UNIVERSITÁRIO I"],
            [ 98, "LOTEAMENTO SANTA FELICIDADE II"],
            [ 99, "LOTEAMENTO RESERVA BELAS ARTES"],
            [100, "JARDIM EUROPA III"],
            [101, "MT 130"],
            [102, "CONDOMINIO SPLENDORE RESORT RESIDENCE"],
            [103, "ZONA DE EXPANSÃO URBANA INDUSTRIAL - LONGPIN"],
            [104, "SANTA CLARA III"],
            [105, "JARDIM EUROPA IV"],
            [106, "RESIDENCIAL BURITIS UNIVERSITÁRIO II"],
            [107, "TERRAZ CONDOMINIO CLUBE"],
            [108, "RESIDENCIAL JARDIM DOS IPES I"],
            [109, "LOTEAMENTO VILLA GRAMADO"],
            [110, "LOTEAMENTO JARDIM LUCIANA 1ª AMPLIAÇÃO"],
            [111, "LOTEAMENTO JARDIM PARAISO"],
            [112, "RESIDENCIAL IPE FLORIDO 01"],
            [113, "VILA UNIÃO"],
            [114, "JARDIM DAS AMÉRICAS X"],
            [115, "LOTEAMENTO JARDIM DOS IPES II"],
            [116, "LOTEAMENTO BELVEDERE II"],
            [117, "LOTEAMENTO SANTA FELICIDADE III"],
            [118, "LOTEAMENTO VERTENTES DAS AGUAS II"],
            [119, "RESIDENCIAL IPE FLORIDO 02"],
            [120, "RESIDENCIAL IPE FLORIDO 03"],
            [121, "LOTEAMENTO JARDIM DOS IPES IV"],
            [122, "LOTEAMENTO PORTAL BUSINESS"],
            [123, "RESIDENCIAL CANAÃ"],
            [124, "RESIDENCIAL BURITIS PRIMAVERA VI"],
            [125, "LOTEAMENTO JARDIM DOS IPES III"],
        ];
    }
};
