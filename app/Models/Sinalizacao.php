<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso rápido sobre um imóvel, ou lembrete de revistoria.
 * Ver a migração 2026_10_02_000100_sinalizacoes.
 */
class Sinalizacao extends Model
{
    protected $table = 'sinalizacoes';
    protected $guarded = [];

    /**
     * Os tipos e o rótulo de cada um. A tela desenha o ícone pelo mesmo
     * código; um tipo novo entra aqui e no mapa de ícones de sinalizacoes.js.
     */
    public const TIPOS = [
        'obra_sem_placa' => 'Obra sem placa',
        'entulho'        => 'Entulho na calçada',
        'avanco'         => 'Avanço / muro',
        'risco'          => 'Risco',
        'obra_iniciada'  => 'Obra iniciada',
        'pedir_vistoria' => 'Pedir vistoria',
        'lembrete'       => 'Lembrete',
        'outro'          => 'Outro',
    ];

    protected function casts(): array
    {
        return ['lembrar_em' => 'date', 'resolvida_em' => 'datetime'];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvida_por');
    }

    public function vistoria(): BelongsTo
    {
        return $this->belongsTo(Vistoria::class);
    }

    /**
     * Abertas e JÁ VALENDO: o lembrete só aparece a partir da data dele.
     * Antes disso ele existe, mas não pede nada de ninguém.
     */
    public function scopePendentes(Builder $q): Builder
    {
        return $q->where('status', 'aberta')
            ->where(fn ($w) => $w->whereNull('lembrar_em')->orWhere('lembrar_em', '<=', now()->toDateString()));
    }

    /**
     * O que cada um enxerga: agente de fiscalização e administrador veem todas;
     * qualquer outro usuário vê só as que ele mesmo criou (User::veTodasSinalizacoes).
     */
    public function scopeVisiveisPara(Builder $q, User $u): Builder
    {
        return $u->veTodasSinalizacoes() ? $q : $q->where('user_id', $u->id);
    }

    public function rotulo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
