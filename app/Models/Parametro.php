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
    ];

    /** Valor de uma chave, com cache — lido em toda emissão de PDF. */
    public static function get(string $chave, ?string $padrao = null): ?string
    {
        return Cache::rememberForever("parametro:$chave", function () use ($chave, $padrao) {
            return static::find($chave)?->valor ?? $padrao;
        });
    }

    public static function set(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
        Cache::forget("parametro:$chave");
    }
}
