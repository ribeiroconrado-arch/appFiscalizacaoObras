<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A denúncia de perturbação do sossego — módulo Posturas.
 *
 * Como no AppPOSTURAS, é o REGISTRO que o agente faz em campo, já com a
 * tratativa: não há fila de recebidas. A tela vem na Fase 3; aqui está o que a
 * importação e a reincidência precisam.
 */
class Denuncia extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];

    /**
     * As tratativas do Posturas, na ordem do formulário de lá. Vazio é
     * Pendente; Cancelado só nasce do cancelamento.
     */
    public const TRATATIVAS = [
        'Orientado',
        'Auto de Infração',
        'Auto de Infração e Apreensão',
        'Auto de Infração e TCO',
        'Improcedente (Som não localizado)',
        'Nível Sonoro dentro do Permitido',
        'Notificado',
        'Termo de Advertência',
        'Outros',
    ];

    public const CANCELADO = 'Cancelado';

    public const LOCAIS = ['Local Exato', 'Próximo', 'Ao Lado', 'Em Frente', 'Aos Fundos'];

    public const FONTES = ['Residência', 'Empresa', 'Veículo'];

    protected function casts(): array
    {
        return [
            'data_hora'    => 'datetime',
            'cancelado_em' => 'datetime',
            'logs'         => 'array',
            'lat'          => 'float',
            'lng'          => 'float',
            'gps_precisao' => 'float',
        ];
    }

    public function agente(): BelongsTo { return $this->belongsTo(User::class, 'agente_id'); }
    public function lote(): BelongsTo   { return $this->belongsTo(Lote::class); }

    public function cancelada(): bool
    {
        return $this->tratativa === self::CANCELADO;
    }

    public function pendente(): bool
    {
        return $this->tratativa === null || $this->tratativa === '';
    }

    /**
     * As denúncias anteriores no MESMO endereço — o alerta de reincidência do
     * Posturas (verificarReincidencia): mesma rua e, se houver, mesmo número;
     * canceladas não contam.
     */
    public function scopeNoEndereco(Builder $q, ?string $logradouro, ?string $numero = null): Builder
    {
        return $q->where('logradouro', mb_strtoupper(trim((string) $logradouro)))
            ->when($numero !== null && trim($numero) !== '', fn ($b) => $b->where('numero', trim($numero)))
            ->where(fn ($b) => $b->whereNull('tratativa')->orWhere('tratativa', '<>', self::CANCELADO))
            ->orderByDesc('data_hora');
    }
}
