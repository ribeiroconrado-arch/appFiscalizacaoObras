<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um bairro importado pela tela: o arquivo, quem enviou, e em que pé está —
 * rascunho (carregado, ainda não salvo), em revisão, publicado ou excluído.
 * Ver App\Services\ImportacaoDeBairro.
 */
class ImportacaoLote extends Model
{
    protected $table = 'importacoes_lotes';
    protected $guarded = [];

    public const SITUACOES = [
        'rascunho'  => 'Rascunho · não salva',
        'revisao'   => 'Salva · não publicada',
        'publicada' => 'Publicada',
        'excluida'  => 'Excluída',
    ];

    protected function casts(): array
    {
        return [
            'conferencia'          => 'array',
            'conferencia_cadastro' => 'array',
            'conferido_em'         => 'datetime',
            'salvo_em'             => 'datetime',
            'publicado_em'         => 'datetime',
            'excluido_em'          => 'datetime',
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

    public function excluidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluido_por');
    }

    public function emRevisao(): bool
    {
        return $this->status === 'revisao';
    }

    public function emRascunho(): bool
    {
        return $this->status === 'rascunho';
    }

    /** Rascunho ou em revisão: os lotes ainda não são de todos. */
    public function emAndamento(): bool
    {
        return in_array($this->status, ['rascunho', 'revisao'], true);
    }

    /** Rascunho é de quem carregou — e do administrador, que pode descartá-lo. */
    public function visivelPara(User $u): bool
    {
        return ! $this->emRascunho() || $u->isAdmin() || (int) $this->user_id === (int) $u->id;
    }
}
