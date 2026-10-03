<?php

namespace App\Http\Controllers;

use App\Cadastro\ColunasDaExportacao;
use App\Cadastro\FonteDoCadastro;
use App\Cadastro\ProprietariosVisiveis;
use App\Cadastro\RetratoBci;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A aba "Cadastro imobiliário" da ficha do imóvel.
 *
 * Lê o CADASTRO MUNICIPAL AO VIVO — a tabela que a carga mensal da planilha
 * mantém (ver App\Cadastro\CargaDoCadastro). Não há mais cópia por lote nem
 * botão "Atualizar": o dado é o da última carga, e a data dela aparece como
 * "Últ. integração".
 *
 * Este endpoint é chamado quando a ficha abre a aba (e pelo cabeçalho da
 * ficha), e não junto do mapa: enriquecer milhares de lotes de uma vez seria
 * pagar por um dado que quase ninguém vai olhar.
 */
class CadastroImobiliarioController extends Controller
{
    public function __construct(private FonteDoCadastro $fonte)
    {
    }

    public function mostrar(Lote $lote): JsonResponse
    {
        $donos = ProprietariosVisiveis::para(request()->user(), $this->fonte->proprietarios($lote));

        $situacao = $this->fonte->situacao($lote);
        unset($situacao['inscricoes']);

        return response()->json(
            $this->retrato($lote)
            + ['integracao' => $situacao]
            + ['fiscalizacao' => $this->fiscalizacao($lote)]
            + ($donos === null ? [] : ['proprietarios' => $donos])
        );
    }

    /**
     * PUT /api/imoveis/{lote}/progressividade
     *
     * O único dado desta aba que NÃO vem do cadastro municipal: é lançado pela
     * fiscalização e mora no lote (a carga mensal não o toca). `null` desfaz o
     * lançamento — "não informado" é diferente de "não tem". Quem lançou e
     * quando ficam na trilha de auditoria do Lote.
     */
    public function progressividade(Request $r, Lote $lote): JsonResponse
    {
        abort_unless($r->user()->canEdit(), 403, 'Só a fiscalização lança a progressividade.');

        $d = $r->validate(['tem_progressividade' => ['present', 'nullable', 'boolean']]);
        $lote->update(['tem_progressividade' => $d['tem_progressividade'] === null
            ? null : (bool) $d['tem_progressividade']]);

        return response()->json(['fiscalizacao' => $this->fiscalizacao($lote)]);
    }

    /** @return array{tem_progressividade:?bool} */
    private function fiscalizacao(Lote $lote): array
    {
        return ['tem_progressividade' => $lote->tem_progressividade];
    }

    /** @return array<string,mixed> */
    private function retrato(Lote $lote): array
    {
        $r = $this->fonte->consultar($lote);

        if (! $r) {
            // Vazio EXPLICADO, não campos em branco: quem abre precisa saber se
            // o imóvel não está no cadastro, se o bairro não foi amarrado ou se
            // o lote não tem quadra/número — cada caso tem uma providência.
            return [
                'tem'    => false,
                'motivo' => $this->fonte->porQueVazio($lote),
            ];
        }

        $i = $r->imovel;
        $num = fn ($v) => $v === null || $v === '' ? null : (float) $v;

        return [
            'tem'    => true,
            'imovel' => [
                'codigo_cadastro'       => $i['codigo_cadastro'] ?? null,
                'inscricao_alternativa' => $i['inscricao_alternativa'] ?? null,
                'isencao'               => $i['isencao'] ?? null,
                'ativo'                 => RetratoBci::isencaoAtiva($i['isencao'] ?? null),
                'area_terreno_m2'       => $num($i['area_terreno_m2'] ?? null),
                'area_edificada_m2'     => $num($i['area_edificada_m2'] ?? null),
                'fracao_ideal'          => $num($i['fracao_ideal'] ?? null),
                'testada_m'             => $num($i['testada_m'] ?? null),
                'medida_lado_direito'   => $num($i['medida_lado_direito'] ?? null),
                'medida_lado_esquerdo'  => $num($i['medida_lado_esquerdo'] ?? null),
                'medida_fundo'          => $num($i['medida_fundo'] ?? null),
                'setor'                 => $i['setor'] ?? null,
                'regiao_fiscal'         => $i['regiao_fiscal'] ?? null,
                'complemento'           => $i['complemento'] ?? null,
                'logradouro'            => $i['logradouro'] ?? null,
                'numero_predial'        => $i['numero_predial'] ?? null,
                'nome_bairro'           => $i['nome_bairro'] ?? null,
            ],
            // Na ordem das colunas da exportação: a coluna JSON do MySQL
            // reordena as chaves, e a ficha leria "AGUA" antes de "OCUPACAO".
            // Chave que a lista não conhece vai para o fim, na ordem que veio.
            'caracteristicas' => collect($r->caracteristicas)
                ->map(fn ($valor, $chave) => ['chave' => $chave, 'valor' => $valor])
                ->sortBy(fn ($c) => array_search($c['chave'], ColunasDaExportacao::CARACTERISTICAS, true) === false
                    ? PHP_INT_MAX : array_search($c['chave'], ColunasDaExportacao::CARACTERISTICAS, true))
                ->values(),
            'unidades' => array_map(fn (array $u) => [
                'numero' => $u['numero'],
                'ano'    => $u['ano_construcao'] !== null ? (int) $u['ano_construcao'] : null,
                'area'   => $num($u['area_edificada_m2']),
                // Sem padrão gravado, mostra os pontos: é deles que o padrão
                // sai no cadastro, e o número bruto informa mais que um travessão.
                'padrao' => $u['padrao'] ?: ($u['pontos'] ? $u['pontos'] . ' pts' : null),
            ], $r->unidades),
        ];
    }
}
