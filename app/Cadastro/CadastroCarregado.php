<?php

namespace App\Cadastro;

use App\Models\Lote;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;

/**
 * Consulta o cadastro imobiliário carregado de uma exportação da prefeitura
 * (tabela `cadastro_externo_imoveis`, alimentada por `cadastro:carregar`).
 *
 * Enquanto não há acesso ao banco deles, esta classe FAZ O PAPEL do banco:
 * mesma pergunta, mesma resposta, mesmo caminho. O que a troca vai exigir, um
 * dia, é escrever outra implementação de FonteDoCadastro — nada além.
 *
 * ── A chave é a INSCRIÇÃO (17/11/2026) ──
 *
 * Casa-se SEMPRE pela inscrição imobiliária do lote (ver linhasDoLote), a
 * mesma chave da conferência com o cadastro. Ela já carrega o bairro: só
 * quadra e lote NÃO identificam imóvel nenhum — nesta base, 656 pares
 * (quadra, lote) do Buritis existem também no Jardim Europa IV.
 *
 * O bairro entra na inscrição pelo CÓDIGO, que vem de `cadastro_bairros`,
 * preenchido na amarração feita pelo comando de carga. Sem amarração não há
 * inscrição montada, e então não há consulta — de propósito.
 */
class CadastroCarregado implements FonteDoCadastro
{
    public function nome(): string
    {
        return 'exportacao';
    }

    public function consultar(Lote $lote): ?RetratoBci
    {
        $linhas = $this->linhasDoLote($lote);
        if (! $linhas) {
            return null;
        }
        // Lote unificado no cadastro: se só parte das inscrições saiu da
        // planilha, o retrato é o das que continuam. Todas ausentes: mostra o
        // que se sabia — a ficha marca "fora do cadastro desde…".
        $presentes = array_values(array_filter($linhas, fn ($l) => ($l->ausente_desde_carga_id ?? null) === null));
        $linhas = $presentes ?: $linhas;

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
                // O bairro COMO O CADASTRO O CHAMA: é com ele, e não com o do
                // sistema, que se confere se a ficha puxou o imóvel certo.
                'nome_bairro'           => $c->nome_bairro,
                // Com o tipo (RUA, AV.…) que a exportação às vezes omite.
                'logradouro'            => TipoDoLogradouro::completar($c->logradouro, $c->nome_bairro, $c->codigo_bairro),
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
            return 'Nenhuma planilha do cadastro municipal foi carregada ainda (Parâmetros → Cadastro municipal).';
        }

        $codigo = $this->codigoDoBairro($lote);
        if (! $codigo) {
            return "O bairro \"{$lote->bairro}\" ainda não foi ligado a um bairro do cadastro "
                 . 'da prefeitura, então não há como saber qual imóvel de lá é este daqui.';
        }

        $insc = InscricaoImobiliaria::normalizar((new BairrosDoDesenho())->inscricaoDe($lote));
        if ($insc === null) {
            return 'Este lote está sem quadra ou sem número, e sem eles a inscrição imobiliária '
                 . 'não se monta — é por ela que o cadastro identifica o imóvel. Corrija o lote no mapa.';
        }

        return sprintf('O cadastro carregado não tem a inscrição %s.', InscricaoImobiliaria::formatar($insc));
    }

    /**
     * As linhas do cadastro do lote — SEMPRE PELA INSCRIÇÃO (17/11/2026).
     *
     * Antes era por bairro + quadra + número, e isso falhava no desmembrado
     * com letra: no mapa o lote é "9B", no cadastro é o lote 9 com a variação
     * 001, e o balão dizia "sem imóvel no cadastro" de um imóvel que está lá
     * (Q 31 · 9B do Parque Eldorado), enquanto a conferência — que já casava
     * pela inscrição — dizia que ele casou. E o lote 9 sem letra trazia as
     * linhas 000 e 001 juntas, os donos dos dois como se fossem um só.
     *
     * A inscrição do lote é a de BairrosDoDesenho::inscricaoDe: a informada à
     * mão (a da unidade de fração ideal, por exemplo) ou a montada com o
     * código do bairro, a quadra, o número e a variação. A coluna `inscricao`
     * do cadastro é única e indexada; a busca vai pelos 15 dígitos e pela
     * forma com pontos, que é como cargas antigas podem tê-la gravado.
     *
     * @return list<object>
     */
    private function linhasDoLote(Lote $lote): array
    {
        $insc = InscricaoImobiliaria::normalizar((new BairrosDoDesenho())->inscricaoDe($lote));
        if ($insc === null) {
            return [];
        }

        return DB::table('cadastro_externo_imoveis')
            ->whereIn('inscricao', array_unique([$insc, InscricaoImobiliaria::formatar($insc)]))
            ->orderBy('inscricao')
            ->get()
            ->all();
    }

    public function situacao(Lote $lote): array
    {
        $linhas = $this->linhasDoLote($lote);
        $vazio = ['inscricoes' => array_column($linhas, 'inscricao'), 'carga_id' => null, 'em' => null, 'alterado_em' => null, 'ausente_desde' => null];
        if (! $linhas) {
            return $vazio;
        }

        $presentes = array_filter($linhas, fn ($l) => ($l->ausente_desde_carga_id ?? null) === null);
        $vista = max(array_map(fn ($l) => (int) ($l->vista_na_carga_id ?? 0), $linhas)) ?: null;
        $alterada = max(array_map(fn ($l) => (int) ($l->alterado_na_carga_id ?? 0), $linhas)) ?: null;
        // Ausente só quando NENHUMA inscrição do lote veio: num lote unificado
        // no cadastro, uma inscrição sumir não tira o imóvel do cadastro.
        $ausente = $presentes ? null
            : min(array_map(fn ($l) => (int) $l->ausente_desde_carga_id, $linhas));

        $datas = DB::table('cadastro_cargas')->whereIn('id', array_filter([$vista, $alterada, $ausente]))
            ->get(['id', 'concluida_em', 'created_at'])
            ->mapWithKeys(fn ($c) => [$c->id => \Illuminate\Support\Carbon::parse($c->concluida_em ?? $c->created_at)->toIso8601String()]);

        return [
            'inscricoes'    => $vazio['inscricoes'],
            'carga_id'      => $vista,
            'em'            => $vista ? ($datas[$vista] ?? null) : null,
            'alterado_em'   => $alterada ? ($datas[$alterada] ?? null) : null,
            'ausente_desde' => $ausente ? ($datas[$ausente] ?? null) : null,
        ];
    }

    public function proprietarios(Lote $lote): array
    {
        $inscricoes = array_column($this->linhasDoLote($lote), 'inscricao');
        if (! $inscricoes) {
            return [];
        }

        // Unificado no cadastro = várias inscrições no mesmo lote, e o mesmo
        // dono em cada uma. Junta pela dupla nome+documento.
        $donos = [];
        foreach (DB::table('cadastro_proprietarios')->whereIn('inscricao', $inscricoes)
                     ->orderBy('inscricao')->orderBy('ordem')->get() as $p) {
            $donos[mb_strtolower($p->nome . '|' . $p->documento)] ??= [
                'nome'      => $p->nome,
                'documento' => $p->documento,
                'endereco'  => $p->endereco,
            ];
        }

        return array_values($donos);
    }

    public function imoveisDoBairro(string $codigoBairro): iterable
    {
        // Mesma regra de zeros de `linhasDoLote`: o cadastro grava "000124",
        // a amarração de Parâmetros guarda "124".
        $linhas = DB::table('cadastro_externo_imoveis')
            ->whereRaw("TRIM(LEADING '0' FROM codigo_bairro) = ?", [ltrim($codigoBairro, '0')])
            ->orderBy('inscricao')
            ->cursor();

        foreach ($linhas as $l) {
            yield [
                'inscricao'       => preg_replace('/\D/', '', (string) $l->inscricao),
                'quadra'          => $l->quadra ?? (string) (InscricaoImobiliaria::partes($l->inscricao)['quadra'] ?? ''),
                'lote'            => $l->lote ?? (string) (InscricaoImobiliaria::partes($l->inscricao)['lote'] ?? ''),
                'isencao'         => $l->isencao,
                'area_terreno_m2' => $l->area_terreno_m2 !== null ? (float) $l->area_terreno_m2 : null,
                'endereco'        => trim((TipoDoLogradouro::completar($l->logradouro, $l->nome_bairro, $l->codigo_bairro) ?? '') . ' ' . ($l->numero_predial ?? '')) ?: null,
            ];
        }
    }

    private function codigoDoBairro(Lote $lote): ?string
    {
        return DB::table('cadastro_bairros')->where('nome_gis', $lote->bairro)->value('codigo');
    }
}
