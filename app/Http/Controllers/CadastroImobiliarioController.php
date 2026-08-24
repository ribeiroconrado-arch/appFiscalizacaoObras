<?php

namespace App\Http\Controllers;

use App\Models\Bci\BciImovel;
use App\Models\Bci\BciProprietario;
use App\Models\Bci\BciUnidade;
use App\Cadastro\SincronizaBci;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;

/**
 * A aba "Cadastro imobiliário" da ficha do imóvel.
 *
 * Lê a CÓPIA LOCAL do BCI, nunca o banco da prefeitura — a consulta lá é um
 * ato explícito (ver App\Cadastro), porque depende da rede deles estar de pé.
 *
 * Este endpoint é chamado quando a aba é aberta, e não junto da ficha: o mapa
 * carrega até 3.000 lotes de uma vez, e enriquecer todos seria pagar por um
 * dado que quase ninguém vai olhar.
 */
class CadastroImobiliarioController extends Controller
{
    public function __construct(private SincronizaBci $sincroniza)
    {
    }

    public function mostrar(Lote $lote): JsonResponse
    {
        return response()->json($this->retrato($lote));
    }

    /**
     * Consulta o cadastro AGORA e regrava a cópia local.
     *
     * É um ato explícito, e não algo que a ficha faz sozinha ao abrir: a
     * consulta depende do cadastro da prefeitura estar de pé, e o fiscal em
     * campo precisa que a ficha abra mesmo quando ele não está.
     */
    public function atualizar(Lote $lote): JsonResponse
    {
        $bci = $this->sincroniza->atualizar($lote);

        return response()->json($this->retrato($lote) + [
            'atualizado' => (bool) $bci,
        ]);
    }

    /** @return array<string,mixed> */
    private function retrato(Lote $lote): array
    {
        $bci = BciImovel::with(['proprietarios', 'caracteristicas', 'unidades'])
            ->where('lote_id', $lote->id)->first();

        if (! $bci) {
            // Vazio EXPLICADO, não campos em branco: quem abre precisa saber se
            // o imóvel não tem cadastro, se o bairro não foi amarrado, ou se o
            // sistema simplesmente ainda não perguntou. São três situações
            // diferentes, com três providências diferentes.
            return [
                'tem'    => false,
                'motivo' => $this->sincroniza->porQueVazio($lote),
            ];
        }

        return [
            'tem'           => true,
            'consultado_em' => $bci->consultado_em?->toIso8601String(),
            'imovel'        => [
                'codigo_cadastro'       => $bci->codigo_cadastro,
                'inscricao_alternativa' => $bci->inscricao_alternativa,
                'isencao'               => $bci->isencao,
                'ativo'                 => $bci->ativo(),
                'area_terreno_m2'       => $bci->area_terreno_m2,
                'area_edificada_m2'     => $bci->area_edificada_m2,
                'fracao_ideal'          => $bci->fracao_ideal,
                'testada_m'             => $bci->testada_m,
                'medida_lado_direito'   => $bci->medida_lado_direito,
                'medida_lado_esquerdo'  => $bci->medida_lado_esquerdo,
                'medida_fundo'          => $bci->medida_fundo,
                'setor'                 => $bci->setor,
                'regiao_fiscal'         => $bci->regiao_fiscal,
                'complemento'           => $bci->complemento,
            ],
            'proprietarios' => $bci->proprietarios->map(fn (BciProprietario $p) => [
                'nome'      => $p->nome,
                'documento' => $p->documento,
                'endereco'  => $p->enderecoLinha() ?: null,
            ]),
            // Chave/valor na ordem em que o BCI as traz: a lista muda de
            // município para município, e a tela desenha o que vier.
            'caracteristicas' => $bci->caracteristicas->map(fn ($c) => [
                'chave' => $c->chave,
                'valor' => $c->valor,
            ]),
            'unidades' => $bci->unidades->map(fn (BciUnidade $u) => [
                'numero' => $u->numero,
                'ano'    => $u->ano_construcao,
                'area'   => $u->area_edificada_m2,
                // Sem padrão gravado, mostra os pontos: é deles que o padrão
                // sai no cadastro, e um campo com o número bruto informa mais
                // do que um travessão.
                'padrao' => $u->padrao ?: ($u->pontos ? $u->pontos . ' pts' : null),
            ]),
        ];
    }
}
