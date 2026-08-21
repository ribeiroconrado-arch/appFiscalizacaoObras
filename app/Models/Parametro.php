<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Parametro extends Model
{
    protected $primaryKey = 'chave';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    /**
     * Chaves conhecidas, com valor padrão e grupo — o que a tela de
     * Parâmetros > Geral edita. Só o que está aqui aparece na tela; uma
     * chave nova precisa ser somada a esta lista, não digitada livremente.
     */
    public const CHAVES = [
        'orgao_nome'      => ['Secretaria Municipal de Obras', 'geral', 'Nome do órgão, impresso nos documentos'],
        'orgao_secretaria'=> ['Prefeitura Municipal de Primavera do Leste', 'geral', 'Entidade, no rodapé dos documentos'],
        'orgao_endereco'  => ['', 'geral', 'Endereço para correspondência'],
        'orgao_telefone'  => ['', 'geral', 'Telefone de contato'],
        'orgao_cnpj'      => ['', 'geral', 'CNPJ da prefeitura'],

        // Cabeçalho institucional dos documentos impressos. No AppPOSTURAS
        // isso é constante no código (_CAB_OFICIAL/_RODAPE_OFICIAL); aqui é
        // cadastrável, porque quem reorganiza secretaria é a prefeitura, não
        // o desenvolvedor — e uma reestruturação não pode exigir deploy.
        'orgao_departamento' => ['Departamento de Fiscalização', 'impressao', 'Linha 2 do cabeçalho impresso'],
        'orgao_divisao'      => ['Divisão de Fiscalização de Obras', 'impressao', 'Linha 3 do cabeçalho impresso'],
        'orgao_municipio'    => ['Primavera do Leste - MT', 'impressao', 'Município, no cabeçalho impresso'],
        'impressao_selo'     => ['FISCALIZAÇÃO DE OBRAS', 'impressao', 'Selo à direita do cabeçalho impresso'],
        'rodape_protocolo'   => ['', 'impressao', 'Protocolo Central — rodapé do documento'],
        'rodape_ouvidoria'   => ['', 'impressao', 'Ouvidoria Municipal — rodapé do documento'],

        // Texto fixo do Termo de Recusa. Institucional e igual em qualquer
        // lei (fala do ato de recusar, não do fato) — por isso é parâmetro
        // do órgão e não campo da legislação.
        'brasao_url' => ['', 'impressao', 'Brasão do município (enviado em Parâmetros → Órgão)'],
        'termo_recusa' => [
            'Declaro que o(a) autuado(a), a que se refere o presente documento, aqui devidamente lavrado, '
            . 'recusou-se a assiná-lo, e para dar o devido efeito legal averbei sua recusa, a fim de evitar que '
            . 'seja alegado o desconhecimento de tal ato, ficando de posse do(a) mesmo(a) a segunda via deste documento.',
            'impressao',
            'Declaração lida na recusa de assinatura',
        ],
    ];

    /**
     * Valor de uma chave, com cache — lido em toda emissão de documento.
     *
     * Chave sem registro (ou registrada em branco) cai para o padrão de
     * fábrica declarado em {@link CHAVES}. Sem isso, uma chave nova sairia
     * vazia no papel até alguém abrir Parâmetros e salvá-la — e o documento
     * é justamente onde a falta aparece pior.
     */
    public static function get(string $chave, ?string $padrao = null): ?string
    {
        $valor = Cache::rememberForever("parametro:$chave", fn () => static::find($chave)?->valor);

        return ($valor === null || $valor === '')
            ? ($padrao ?? (self::CHAVES[$chave][0] ?? null))
            : $valor;
    }

    public static function set(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
        Cache::forget("parametro:$chave");
    }
}
