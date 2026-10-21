<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A denúncia de perturbação do sossego — módulo Posturas.
 *
 * Como no AppPOSTURAS, é o REGISTRO que o agente faz em campo, já com a
 * tratativa: não há fila de recebidas. A tela é a aba Denúncias
 * (DenunciaController, public/js/denuncias.js).
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

    /** As peças geradas a partir desta denúncia ("Gerar notificação"). */
    public function documentos(): HasMany { return $this->hasMany(Documento::class); }

    /**
     * Quem pode alterar: o autor ou o administrador — a regra do AppPOSTURAS
     * (abrirDen). Cancelada não se altera mais.
     *
     * A denúncia importada pode não ter o usuário ligado (o agente de lá não
     * tinha conta aqui): vale então o nome copiado, como valia lá.
     */
    public function podeEditar(User $u): bool
    {
        if ($this->cancelada() || ! $u->canEdit()) {
            return false;
        }

        return $u->isAdmin()
            || ($this->agente_id !== null ? $this->agente_id === $u->id
                : mb_strtoupper(trim((string) $this->agente_nome)) === mb_strtoupper(trim((string) $u->name)));
    }

    /** "RUA MARINGÁ, 300" — como a denúncia aparece na lista e no balão. */
    public function endereco(): string
    {
        return trim(($this->logradouro ?? '') . ($this->numero ? ', ' . $this->numero : '')) ?: '—';
    }

    /**
     * CPF ou CNPJ escondido para quem não pode vê-lo — a view
     * `denuncia_publica` do Posturas, aqui na saída do controller. Ficam à
     * mostra os dígitos do meio, que bastam para conferir com o documento em
     * mãos e não servem sozinhos para nada.
     */
    public static function mascarar(?string $documento): ?string
    {
        $n = preg_replace('/\D/', '', (string) $documento);
        if ($n === '') {
            return null;
        }

        return match (strlen($n)) {
            11 => '***.' . substr($n, 3, 3) . '.' . substr($n, 6, 3) . '-**',
            14 => '**.' . substr($n, 2, 3) . '.' . substr($n, 5, 3) . '/' . substr($n, 8, 4) . '-**',
            default => '***',
        };
    }

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
