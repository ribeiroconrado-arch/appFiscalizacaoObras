<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um ATO do bairro (18/11/2026): a lei ou o decreto que aprovou o loteamento,
 * ou uma das ampliações, etapas, regularizações, retificações, alterações e a
 * revogação que vieram depois. O Jardim Riva é um bairro só com quatro
 * decretos. Editados em Parâmetros › Bairros.
 */
class BairroAto extends Model
{
    use RegistraAuditoria;

    protected $table = 'bairro_atos';

    protected $fillable = ['cadastro_bairro_id', 'tipo', 'ato', 'data', 'area_m2', 'observacao'];

    protected $casts = ['data' => 'date', 'area_m2' => 'float'];

    /** Os tipos, na ordem do seletor, com o rótulo da tela. */
    public const TIPOS = [
        'aprovacao'     => 'Aprovação',
        'ampliacao'     => 'Ampliação',
        'etapa'         => 'Etapa',
        'regularizacao' => 'Regularização',
        'retificacao'   => 'Retificação',
        'alteracao'     => 'Alteração',
        'revogacao'     => 'Revogação',
    ];

    public function bairro(): BelongsTo
    {
        return $this->belongsTo(CadastroBairro::class, 'cadastro_bairro_id');
    }

    /** O que a trilha de auditoria mostra no lugar do id. */
    protected function descricaoAuditoria(): ?string
    {
        return (self::TIPOS[$this->tipo] ?? $this->tipo) . ' · ' . $this->ato;
    }

    /** Para a tela e o balão. @return array<string,mixed> */
    public function paraTela(): array
    {
        return [
            'id'         => $this->id,
            'tipo'       => $this->tipo,
            'tipo_rotulo' => self::TIPOS[$this->tipo] ?? $this->tipo,
            'ato'        => $this->ato,
            'data'       => $this->data?->format('Y-m-d'),
            'area_m2'    => $this->area_m2,
            'observacao' => $this->observacao,
        ];
    }
}
