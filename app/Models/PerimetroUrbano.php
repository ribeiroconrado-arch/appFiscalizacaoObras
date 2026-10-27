<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma versão do perímetro urbano — rascunho, vigente ou anterior.
 * Ver App\Services\PerimetrosUrbanos.
 *
 * A geometria (`geom`) não passa pelo Eloquent: é escrita e lida por SQL
 * (ST_GeomFromGeoJSON / ST_AsGeoJSON), e fica fora da trilha de auditoria —
 * milhares de vértices binários não dizem nada a quem lê o Histórico.
 */
class PerimetroUrbano extends Model
{
    use RegistraAuditoria;

    protected $table = 'perimetros_urbanos';
    protected $guarded = [];
    protected $hidden = ['geom'];

    public const SITUACOES = [
        'rascunho' => 'Rascunho · não publicado',
        'vigente'  => 'Vigente',
        'anterior' => 'Anterior',
    ];

    protected function casts(): array
    {
        return [
            'comparacao'    => 'array',
            'lei_data'      => 'date',
            'vigente_desde' => 'date',
            'vigente_ate'   => 'date',
            'publicado_em'  => 'datetime',
            'area_m2'       => 'float',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function publicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }

    /** "Lei nº 1.234/2026", ou o nome do arquivo se a lei não foi informada. */
    public function rotulo(): string
    {
        return $this->lei_numero
            ? 'Lei nº ' . $this->lei_numero . ($this->lei_data ? ' de ' . $this->lei_data->format('d/m/Y') : '')
            : $this->arquivo_nome;
    }

    protected function descricaoAuditoria(): string
    {
        return 'Perímetro urbano · ' . $this->rotulo();
    }
}
