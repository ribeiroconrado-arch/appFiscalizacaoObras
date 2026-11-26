<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * código; um tipo novo entra aqui, em POR_MODULO e no mapa de ícones de
     * sinalizacoes.js. Ver a migração 2026_11_24_000200.
     */
    public const TIPOS = [
        // Obras
        'obra_iniciada'      => 'Obra iniciada',
        'invasao_recuo'      => 'Invasão de recuo',
        'entulho'            => 'Entulho na calçada',
        'asseio_via'         => 'Asseio da via',
        // Posturas
        'obstrucao_calcada'  => 'Obstrução de calçada',
        'veiculo_abandonado' => 'Veículo abandonado',
        'aguas_servidas'     => 'Águas servidas',
        'publicidade'        => 'Publicidade irregular',
        // Os dois
        'risco'              => 'Risco',
        'outro'              => 'Outro',
        // Criado pela vistoria ("voltar em tal data"); não entra no balão.
        'lembrete'           => 'Lembrete',
    ];

    /**
     * O que o balão oferece em cada módulo: SEIS, sempre — é o que cabe nele
     * (decisão de 24/11/2026). A sinalização é do módulo em que foi criada.
     */
    public const POR_MODULO = [
        'obras'    => ['obra_iniciada', 'invasao_recuo', 'entulho', 'asseio_via', 'risco', 'outro'],
        'posturas' => ['obstrucao_calcada', 'veiculo_abandonado', 'aguas_servidas', 'publicidade', 'risco', 'outro'],
    ];

    /** Os tipos que o balão de `$modulo` oferece, na ordem do balão, com o rótulo. */
    public static function tiposDo(string $modulo): array
    {
        $out = [];
        foreach (self::POR_MODULO[$modulo] ?? self::POR_MODULO['obras'] as $k) { $out[$k] = self::TIPOS[$k]; }
        return $out;
    }

    protected function casts(): array
    {
        return ['lembrar_em' => 'date', 'resolvida_em' => 'datetime', 'lat' => 'float', 'lng' => 'float'];
    }

    /** Sinalização na via, sem imóvel. */
    public function naRua(): bool
    {
        return $this->lote_id === null;
    }

    public function scopeDoModulo(Builder $q, string $modulo): Builder
    {
        return $q->where('modulo', $modulo);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Os avisos SOMADOS a esta: outras pessoas que sinalizaram a mesma coisa no imóvel. */
    public function reforcos(): HasMany
    {
        return $this->hasMany(SinalizacaoReforco::class)->orderBy('id');
    }

    /** Quantas PESSOAS avisaram: quem abriu, mais quem somou depois. */
    public function avisos(): int
    {
        return 1 + $this->reforcos->pluck('user_id')->filter()->unique()
            ->reject(fn ($id) => (int) $id === (int) $this->user_id)->count();
    }

    public function resolvedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvida_por');
    }

    public function vistoria(): BelongsTo
    {
        return $this->belongsTo(Vistoria::class);
    }

    public function vgVistoria(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Vg\VgVistoria::class, 'vg_vistoria_id');
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
     * qualquer outro usuário vê só as que ele mesmo criou, e aquelas a que
     * SOMOU o aviso dele (User::veTodasSinalizacoes).
     */
    public function scopeVisiveisPara(Builder $q, User $u): Builder
    {
        return $u->veTodasSinalizacoes() ? $q : $q->where(fn (Builder $w) => $w
            ->where('user_id', $u->id)
            ->orWhereHas('reforcos', fn (Builder $r) => $r->where('user_id', $u->id)));
    }

    public function rotulo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
