<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Protocolo extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['protocolado_em' => 'date', 'prazo_resposta' => 'date'];
    }

    public const TIPOS = [
        'habite_se'        => 'Habite-se',
        'vistoria_calcada' => 'Vistoria de calçada',
        'contestacao_area' => 'Contestação de área',
        'renovacao_alvara' => 'Renovação de alvará de construção',
        'desmembramento'   => 'Desmembramento (dividir lote)',
        // Separado do desmembramento porque o ATO executado e outro:
        // N->1 contra 1->N. Sem o tipo, o botao de executar teria de
        // perguntar ao operador qual dos dois fazer — que e exatamente a
        // ambiguidade que produz o ato errado.
        'unificacao'       => 'Unificação / remembramento (juntar lotes)',
        'outro'            => 'Outro',
    ];

    public const SITUACOES = [
        'aguardando_vistoria' => ['Aguardando vistoria', 'bd-al'],
        'em_analise'          => ['Em análise', 'bd-in'],
        'deferido'            => ['Deferido', 'bd-ok'],
        'indeferido'          => ['Indeferido', 'bd-er'],
        'arquivado'           => ['Arquivado', 'bd-cx'],
    ];

    public function lote(): BelongsTo        { return $this->belongsTo(Lote::class); }
    public function responsavel(): BelongsTo { return $this->belongsTo(User::class, 'responsavel_id'); }
    public function vistoria(): BelongsTo    { return $this->belongsTo(Vistoria::class); }

    public function rotuloTipo(): string { return self::TIPOS[$this->tipo] ?? $this->tipo; }

    /** @return array{0:string,1:string} */
    public function situacaoBadge(): array
    {
        return self::SITUACOES[$this->situacao] ?? [$this->situacao, 'bd-in'];
    }

    /**
     * Situação do prazo de resposta.
     *
     * Aqui o prazo corre CONTRA A ADMINISTRAÇÃO — o oposto de um documento
     * fiscal. Por isso o vencimento é sinalizado mesmo quando ninguém está
     * cobrando: passar do prazo pode configurar silêncio administrativo.
     *
     * @return array{0:string,1:string}|null
     */
    public function situacaoPrazo(): ?array
    {
        if (! $this->prazo_resposta || in_array($this->situacao, ['deferido', 'indeferido', 'arquivado'], true)) {
            return null;
        }

        $dias = (int) now()->startOfDay()->diffInDays($this->prazo_resposta->startOfDay(), false);

        return match (true) {
            $dias < 0   => ['Resposta atrasada ' . abs($dias) . 'd', 'bd-er'],
            $dias === 0 => ['Responder hoje', 'bd-er'],
            $dias <= 3  => ['Responder em ' . $dias . 'd', 'bd-al'],
            default     => ['Prazo até ' . $this->prazo_resposta->format('d/m'), 'bd-ok'],
        };
    }
}
