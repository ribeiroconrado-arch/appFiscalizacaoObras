<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Documento extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_fato'          => 'datetime',
            'data_lavratura'     => 'datetime',
            'anulado_em'         => 'datetime',
            'prazo_ate'          => 'date',
            'defesa_ate'         => 'date',
            'valor_upf'          => 'float',
            'area_terreno_m2'    => 'float',
            'area_construida_m2' => 'float',
            'upf_valor'          => 'float',
        ];
    }

    /** Rótulo e sigla de cada tipo. A sigla compõe o número (NOT 2026/0231). */
    public const TIPOS = [
        'vistoria'            => ['Vistoria',                'VIS'],
        'notificacao'         => ['Notificação',             'NOT'],
        'notificacao_embargo' => ['Notificação de Embargo',  'NE'],
        // Embargo antes de infração: em obras, primeiro se PARA a obra e
        // depois se apura a penalidade. A ordem do menu segue a ordem do
        // trabalho, não a do alfabeto.
        'auto_embargo'        => ['Auto de Embargo',         'AE'],
        'auto_infracao'       => ['Auto de Infração',        'AI'],
    ];

    /*
     * O Termo de Advertência saiu da lista: em obras a fiscalização trabalha
     * com quatro peças — vistoria, notificação, auto de infração e auto de
     * embargo. O valor continua aceito pela coluna (enum da migração), então
     * documento histórico nenhum quebra; ele apenas não é mais oferecido.
     */

    /**
     * Tipos que NÃO impõem sanção e portanto dispensam fundamentação legal.
     *
     * A vistoria documental existe para o imóvel regular: o fiscal esteve lá,
     * constatou conformidade, e isso precisa deixar registro — sem artigo,
     * sem prazo, sem multa. Tratá-la como os demais obrigaria a inventar um
     * enquadramento onde não há infração.
     */
    public const SEM_SANCAO = ['vistoria'];

    /** Tipos cujo prazo é de DEFESA (dias úteis, vindo da lei). */
    public const COM_DEFESA = ['auto_infracao', 'auto_embargo'];

    /**
     * Tipos cujo prazo é de CUMPRIMENTO (dias corridos, por documento).
     *
     * A Notificação de Embargo entra aqui: ela ADVERTE sobre a paralisação
     * iminente e dá prazo para regularizar. Quem embarga de fato é o Auto de
     * Embargo, e esse tem prazo de defesa.
     */
    public const COM_CUMPRIMENTO = ['notificacao', 'notificacao_embargo'];

    public function lote(): BelongsTo       { return $this->belongsTo(Lote::class); }
    public function vistoria(): BelongsTo   { return $this->belongsTo(Vistoria::class); }
    public function legislacao(): BelongsTo { return $this->belongsTo(Legislacao::class); }
    public function agente(): BelongsTo     { return $this->belongsTo(User::class, 'agente_id'); }
    public function origem(): BelongsTo     { return $this->belongsTo(Documento::class, 'origem_id'); }
    public function derivados(): HasMany    { return $this->hasMany(Documento::class, 'origem_id'); }
    public function artigos(): HasMany      { return $this->hasMany(DocumentoArtigo::class); }
    public function anuladoPor(): BelongsTo { return $this->belongsTo(User::class, 'anulado_por'); }

    /**
     * O que este documento aceita que ESTE usuário faça — o menu "Opções" do
     * AppPOSTURAS (`_opcoesDisponiveis`), decidido no servidor.
     *
     * Fica no model, e não no JavaScript, porque é o servidor que recusa a
     * ação de verdade: um menu que oferece o que a regra depois nega ensina o
     * usuário a esbarrar em erro. As chaves devolvidas são as mesmas que o
     * front usa para montar o menu e que os endpoints conferem antes de agir.
     *
     * @return array<int,string>
     */
    public function opcoesPara(User $u): array
    {
        // Imprimir é o piso: qualquer documento visível pode ser impresso, em
        // qualquer estado. Rascunho sai com marca d'água, anulado também —
        // recusar a impressão de um anulado impediria juntá-lo ao processo.
        // 'imprimir_a4' saiu: era o MESMO layout do 'pdf', por outro motor —
        // duas linhas no menu para a mesma escolha de quem lê. A rota de
        // impressão em A4 continua de pé (é a mesma da bobina), só deixou de
        // ser oferecida como se fosse outra coisa.
        $opcoes = ['pdf', 'imprimir_termica'];

        $autor = $this->agente_id === $u->id;

        if ($this->status === 'rascunho') {
            if ($autor) {
                $opcoes[] = 'lavrar';
                $opcoes[] = 'excluir';
            }
            return $opcoes;
        }

        // Anular: ato do autor, ou do administrador quando o autor já não
        // responde pelo documento (afastamento, desligamento). Documento já
        // anulado não se anula de novo.
        if (in_array($this->status, ['lavrado', 'atendido'], true) && ($autor || $u->isAdmin())) {
            $opcoes[] = 'anular';
        }

        return $opcoes;
    }

    public function rotuloTipo(): string { return self::TIPOS[$this->tipo][0] ?? $this->tipo; }
    public function sigla(): string      { return self::TIPOS[$this->tipo][1] ?? '?'; }

    /** "NOT 2026/0231" — ou "Sem número" enquanto rascunho. */
    public function numeroFormatado(): string
    {
        if (! $this->numero) {
            return 'Sem número';
        }
        return sprintf('%s %d/%04d', $this->sigla(), $this->exercicio, $this->numero);
    }

    public function exigeFundamentacao(): bool
    {
        return ! in_array($this->tipo, self::SEM_SANCAO, true);
    }

    public function podeSerEditado(): bool
    {
        return $this->status === 'rascunho';
    }

    /**
     * Situação do prazo, para a tag da lista.
     *
     * Devolve [texto, classe] ou null quando o documento não tem prazo — o que
     * é o caso da vistoria documental e de tudo que já foi atendido ou anulado.
     */
    public function situacaoPrazo(): ?array
    {
        if (in_array($this->status, ['atendido', 'anulado', 'cancelado'], true)) {
            return null;
        }
        $limite = $this->defesa_ate ?? $this->prazo_ate;
        if (! $limite) {
            return null;
        }

        $dias = (int) now()->startOfDay()->diffInDays($limite->startOfDay(), false);
        $rot  = in_array($this->tipo, self::COM_DEFESA, true) ? 'Defesa' : 'Prazo';

        return match (true) {
            $dias < 0  => [$rot . ' venceu há ' . abs($dias) . ' dia' . (abs($dias) > 1 ? 's' : ''), 'bd-er'],
            $dias === 0 => [$rot . ' vence hoje', 'bd-er'],
            $dias <= 3 => [$rot . ' vence em ' . $dias . ' dia' . ($dias > 1 ? 's' : ''), 'bd-al'],
            default    => [$rot . ' até ' . $limite->format('d/m'), 'bd-ok'],
        };
    }

    /** Classe da tag "Modelo D" para o status. */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'rascunho'  => ['Rascunho', 'bd-in'],
            'lavrado'   => ['Lavrado', 'bd-al'],
            'atendido'  => ['Atendido', 'bd-ok'],
            'anulado'   => ['Anulado', 'bd-cx'],
            'cancelado' => ['Cancelado', 'bd-cx'],
            default     => [$this->status, 'bd-in'],
        };
    }
}
