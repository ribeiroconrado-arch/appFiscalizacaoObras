<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'matricula', 'password', 'perfil', 'tipo_usuario', 'ativo', 'assinatura', 'curador_cadastral'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use RegistraAuditoria;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Níveis de acesso, do mais amplo para o mais restrito. */
    public const PERFIS = ['admin', 'comum', 'viewer'];

    /** Cargos. Só `agente` pode ter perfil acima de `viewer`. */
    public const CARGOS = ['agente', 'coordenador', 'secretario'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ultimo_acesso_em'  => 'datetime',
            'password'          => 'hashed',
            'ativo'             => 'boolean',
        ];
    }

    // ── Autorização ──────────────────────────────────────────────
    // Mesmos nomes de `session.js` do AppPOSTURAS, de propósito: quem já mexeu
    // naquele código reconhece a semântica sem precisar reler.

    // Todas leem `perfilEfetivo()`, nunca a coluna `perfil` crua — senão a
    // trava do cargo (logo abaixo) seria decorativa.

    /** Acesso total: usuários, parâmetros, legislação, auditoria. */
    public function isAdmin(): bool
    {
        return $this->ativo && $this->perfilEfetivo() === 'admin';
    }

    /** Só consulta. Nenhuma escrita, em nenhum módulo. */
    public function isViewer(): bool
    {
        return ! $this->ativo || $this->perfilEfetivo() === 'viewer';
    }

    /** Pode criar e alterar registros (vistorias, obras, documentos). */
    /**
     * Pode corrigir a BASE CADASTRAL direto no mapa?
     *
     * Poder transversal, e não um degrau acima de administrador: administrar o
     * sistema (usuários, legislação, UPF) e responder pelo cadastro são
     * responsabilidades diferentes, e podem estar em pessoas diferentes.
     *
     * Exige `canEdit` junto porque visualizador não escreve nada, marcado ou
     * não — a permissão acrescenta poder a quem já opera, nunca cria acesso do
     * nada.
     */
    /** As ordens de serviço em que este usuário foi designado. */
    public function ordensServico(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(OrdemServico::class, 'os_fiscais')->withTimestamps();
    }

    public function podeCurarCadastro(): bool
    {
        return $this->canEdit() && (bool) $this->curador_cadastral;
    }

    public function canEdit(): bool
    {
        return $this->ativo && in_array($this->perfilEfetivo(), ['admin', 'comum'], true);
    }

    /**
     * Só quem é agente de fiscalização lavra documento — coordenador e
     * secretário acompanham, não autuam. Espelha `podeCadastrarAutos()`.
     */
    public function podeLavrarDocumento(): bool
    {
        return $this->canEdit() && $this->tipo_usuario === 'agente';
    }

    /**
     * Perfil efetivo, aplicando a regra do cargo.
     *
     * A regra "só agente pode ser admin/comum" existe no AppPOSTURAS apenas no
     * formulário de usuários. Repeti-la aqui é o que impede que uma alteração
     * feita direto no banco, ou por uma tela futura que esqueça a validação,
     * conceda escrita a quem não deveria ter.
     */
    public function perfilEfetivo(): string
    {
        if ($this->tipo_usuario !== 'agente' && $this->perfil !== 'viewer') {
            return 'viewer';
        }
        return $this->perfil;
    }

    /**
     * Duas letras para o avatar: iniciais do primeiro e do último nome.
     *
     * Ignora as partículas ("de", "dos", "da"), senão "João da Silva" viraria
     * "JD" — que não identifica ninguém numa lista de servidores.
     */
    public function iniciais(): string
    {
        $partes = array_values(array_filter(
            preg_split('/\s+/', trim($this->name)),
            fn ($p) => ! in_array(mb_strtolower($p), ['de', 'da', 'do', 'das', 'dos', 'e'], true)
        ));

        if (! $partes) {
            return '?';
        }

        $primeira = mb_substr($partes[0], 0, 1);
        $ultima = count($partes) > 1 ? mb_substr(end($partes), 0, 1) : '';

        return mb_strtoupper($primeira . $ultima);
    }

    /** Rótulo do perfil para exibição. */
    public function perfilRotulo(): string
    {
        return match ($this->perfilEfetivo()) {
            'admin'  => 'Administrador',
            'comum'  => 'Comum',
            default  => 'Visualizador',
        };
    }
}
