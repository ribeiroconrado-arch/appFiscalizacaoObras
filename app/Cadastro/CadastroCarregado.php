<?php

namespace App\Cadastro;

use App\Models\Lote;
use Illuminate\Support\Facades\DB;

/**
 * Consulta o cadastro imobiliário carregado de uma exportação da prefeitura
 * (tabela `cadastro_externo_imoveis`, alimentada por `cadastro:carregar`).
 *
 * Enquanto não há acesso ao banco deles, esta classe FAZ O PAPEL do banco:
 * mesma pergunta, mesma resposta, mesmo caminho. O que a troca vai exigir, um
 * dia, é escrever outra implementação de FonteDoCadastro — nada além.
 *
 * ── A chave, e por que ela é o bairro e não a quadra ──
 *
 * Casa-se por bairro + quadra + lote, nesta ordem de importância. Só quadra e
 * lote NÃO identificam imóvel nenhum: nesta base, 656 pares (quadra, lote) do
 * Buritis existem também no Jardim Europa IV — casar sem o bairro amarraria a
 * ficha de um imóvel ao cadastro de outro, em bairro diferente da cidade.
 *
 * O bairro entra pelo CÓDIGO, que vem de `cadastro_bairros`, preenchido na
 * amarração feita pelo comando de carga. Sem amarração não há consulta, e isso
 * é de propósito.
 */
class CadastroCarregado implements FonteDoCadastro
{
    public function consultar(Lote $lote): ?RetratoBci
    {
        $linhas = $this->linhasDoLote($lote);
        if (! $linhas) {
            return null;
        }

        // Várias linhas para o mesmo lote são as várias construções dele. Os
        // campos do terreno vêm da primeira: elas repetem o mesmo terreno.
        $c = $linhas[0];

        $unidades = [];
        foreach ($linhas as $i => $l) {
            if ($l->unidade_ano === null && $l->unidade_area_m2 === null) {
                continue;   // lote sem construção: não inventa unidade vazia
            }
            $unidades[] = [
                'numero'            => (string) ($i + 1),
                'ano_construcao'    => $l->unidade_ano,
                'area_edificada_m2' => $l->unidade_area_m2,
                'pontos'            => $l->unidade_pontos,
                'padrao'            => $l->unidade_padrao,
            ];
        }

        return new RetratoBci(
            imovel: [
                'codigo_cadastro'       => $c->codigo_cadastro,
                'inscricao_alternativa' => $c->inscricao_alternativa,
                'logradouro'            => $c->logradouro,
                'numero_predial'        => $c->numero_predial,
                'complemento'           => $c->complemento,
                'area_terreno_m2'       => $c->area_terreno_m2,
                'area_edificada_m2'     => $c->area_edificada_m2,
                'testada_m'             => $c->testada_m,
                'medida_lado_direito'   => $c->medida_lado_direito,
                'medida_lado_esquerdo'  => $c->medida_lado_esquerdo,
                'medida_fundo'          => $c->medida_fundo,
                'fracao_ideal'          => $c->fracao_ideal,
                'isencao'               => $c->isencao,
                'setor'                 => $c->setor,
                'regiao_fiscal'         => $c->regiao_fiscal,
            ],
            caracteristicas: json_decode($c->caracteristicas ?? '[]', true) ?: [],
            unidades: $unidades,
        );
    }

    public function porQueVazio(Lote $lote): string
    {
        if (DB::table('cadastro_externo_imoveis')->limit(1)->doesntExist()) {
            return 'Nenhuma exportação do cadastro foi carregada ainda.';
        }

        $codigo = $this->codigoDoBairro($lote);
        if (! $codigo) {
            return "O bairro \"{$lote->bairro}\" ainda não foi ligado a um bairro do cadastro "
                 . 'da prefeitura, então não há como saber qual imóvel de lá é este daqui.';
        }

        if ($lote->quadra === null || $lote->numero_lote === null) {
            return 'Este lote está sem quadra ou sem número, e é por eles que o cadastro '
                 . 'identifica o imóvel. Corrija o lote no mapa e consulte de novo.';
        }

        return sprintf(
            'O cadastro carregado não tem imóvel na quadra %s, lote %s deste bairro.',
            $lote->quadra,
            $lote->numero_lote
        );
    }

    /** @return list<object> */
    private function linhasDoLote(Lote $lote): array
    {
        $codigo = $this->codigoDoBairro($lote);
        if (! $codigo || $lote->quadra === null || $lote->numero_lote === null) {
            return [];
        }

        // Zeros à esquerda são de formatação, não de identidade: o cadastro
        // grava "001"/"0001" e o GIS, "1"/"1". Comparar sem eles é o que faz
        // os dois mundos se reconhecerem.
        //
        // O CÓDIGO DO BAIRRO ESTAVA FORA DESSA REGRA, e era o pior dos três:
        // `cadastro_bairros` guarda "124" e a exportação da prefeitura,
        // "000124" — a comparação direta nunca casava. A aba BCI vinha vazia
        // mesmo com o bairro amarrado e o cadastro carregado, e vazia com a
        // MESMA mensagem de quem não tem cadastro nenhum, o que escondia o
        // defeito atrás de uma explicação plausível.
        return DB::table('cadastro_externo_imoveis')
            ->whereRaw("TRIM(LEADING '0' FROM codigo_bairro) = ?", [ltrim((string) $codigo, '0')])
            ->whereRaw("TRIM(LEADING '0' FROM quadra) = ?", [ltrim((string) $lote->quadra, '0')])
            ->whereRaw("TRIM(LEADING '0' FROM lote) = ?", [ltrim((string) $lote->numero_lote, '0')])
            ->orderBy('inscricao')
            ->get()
            ->all();
    }

    private function codigoDoBairro(Lote $lote): ?string
    {
        return DB::table('cadastro_bairros')->where('nome_gis', $lote->bairro)->value('codigo');
    }
}
