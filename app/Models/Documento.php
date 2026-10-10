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
            'cadastro_consultado_em' => 'datetime',
            'cadastro_retrato'       => 'array',
            'autuado_endereco_partes' => 'array',
            'imovel_endereco_partes'  => 'array',
            'anulado_em'         => 'datetime',
            'prazo_ate'          => 'date',
            'defesa_ate'         => 'date',
            'defesa'             => 'array',
            'valor_upf'          => 'float',
            'area_terreno_m2'    => 'float',
            'area_construida_m2' => 'float',
            'alvara_valor'       => 'float',
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

    /**
     * As peças de EMBARGO. Só aceitam artigo configurado para embargo
     * (Artigo::serveA) — e artigo exclusivo de embargo só aparece nelas.
     */
    public const DE_EMBARGO = ['notificacao_embargo', 'auto_embargo'];

    public function lote(): BelongsTo       { return $this->belongsTo(Lote::class); }
    public function vistoria(): BelongsTo   { return $this->belongsTo(Vistoria::class); }
    public function legislacao(): BelongsTo { return $this->belongsTo(Legislacao::class); }
    public function agente(): BelongsTo     { return $this->belongsTo(User::class, 'agente_id'); }
    public function origem(): BelongsTo     { return $this->belongsTo(Documento::class, 'origem_id'); }
    public function derivados(): HasMany    { return $this->hasMany(Documento::class, 'origem_id'); }
    /** Os anexos próprios da peça, na ordem em que saem na via impressa. */
    public function anexos(): HasMany
    {
        return $this->hasMany(DocumentoAnexo::class)->orderBy('ordem')->orderBy('id');
    }

    /**
     * Estados em que a peça NÃO VALE COMO ATO: ainda não foi lavrada, ou
     * deixou de valer. Uma peça assim não serve de origem a outra, não conta
     * como auto anterior para reincidência e não gera custa.
     */
    public const SEM_VALOR_DE_ATO = ['rascunho', 'gravado', 'anulado', 'cancelado', 'defendido'];

    /** Encerrada sem efeito: cancelada (ou anulada, o nome antigo) ou defendida. */
    public const ENCERRADOS = ['anulado', 'cancelado', 'defendido'];

    // ── A DEFESA (DocumentoDefesaController) ─────────────────────

    /** Só os autos têm defesa — são os tipos com prazo de defesa. */
    public function admiteDefesa(): bool
    {
        return in_array($this->tipo, self::COM_DEFESA, true);
    }

    /** A defesa já foi julgada? Julgada, não se altera mais. */
    public function defesaJulgada(): bool
    {
        return ! empty($this->defesa['resultado']);
    }

    /**
     * Quem REGISTRA O PROTOCOLO da defesa: quem lavrou o auto, ou o
     * administrador — com o auto lavrado, ou já em defesa (para corrigir o
     * registro) e ainda sem julgamento.
     */
    public function podeProtocolarDefesa(User $u): bool
    {
        return $this->admiteDefesa()
            && in_array($this->status, ['lavrado', 'em_defesa'], true)
            && ! $this->defesaJulgada()
            && ($this->agente_id === $u->id || $u->isAdmin());
    }

    /** Quem JULGA: só o administrador, e só com a defesa protocolada. */
    public function podeJulgarDefesa(User $u): bool
    {
        return $this->status === 'em_defesa' && ! $this->defesaJulgada() && $u->isAdmin();
    }

    /**
     * A defesa como a tela a mostra: datas em dia/mês/ano, os endereços dos
     * arquivos e o que este usuário pode fazer. Nulo em peça que não tem defesa.
     *
     * @return array<string,mixed>|null
     */
    public function defesaParaTela(User $u): ?array
    {
        if (! $this->admiteDefesa()) {
            return null;
        }
        $d = $this->defesa ?? [];
        $br = fn (?string $data) => $data ? \Carbon\Carbon::parse($data)->format('d/m/Y') : null;
        $arq = fn (string $chave, string $qual) => empty($d[$chave]) ? null : [
            'nome' => $d[$chave]['nome'] ?? 'arquivo',
            'pdf'  => ($d[$chave]['mime'] ?? '') === 'application/pdf',
            'url'  => route('documento.defesa.arquivo', [$this, $qual]),
        ];

        return [
            'protocolo'         => $d['protocolo'] ?? null,
            'data_protocolo'    => $d['data_protocolo'] ?? null,
            'data_protocolo_br' => $br($d['data_protocolo'] ?? null),
            'intempestiva'      => (bool) ($d['intempestiva'] ?? false),
            'prazo_ate'         => $this->defesa_ate?->format('d/m/Y'),
            'registrado'        => empty($d['registrado_nome']) ? null
                : $d['registrado_nome'] . ' em ' . \Carbon\Carbon::parse($d['registrado_em'])->format('d/m/Y H:i'),
            'anexo'             => $arq('anexo', 'defesa'),
            'resultado'         => $d['resultado'] ?? null,
            'data_resultado_br' => $br($d['data_resultado'] ?? null),
            'parecer'           => $d['parecer'] ?? null,
            'julgado'           => empty($d['julgado_nome']) ? null
                : $d['julgado_nome'] . ' em ' . \Carbon\Carbon::parse($d['julgado_em'])->format('d/m/Y H:i'),
            'julgamento_anexo'  => $arq('julgamento_anexo', 'julgamento'),
            'pode_protocolar'   => $this->podeProtocolarDefesa($u),
            'pode_julgar'       => $this->podeJulgarDefesa($u),
        ];
    }

    /** Rascunho ou gravada: ainda se edita, ainda não foi assinada. */
    public function naoLavrado(): bool
    {
        return in_array($this->status, ['rascunho', 'gravado'], true);
    }

    public function encerrado(): bool
    {
        return in_array($this->status, self::ENCERRADOS, true);
    }

    /**
     * Quem JUNTA anexo. Antes da lavratura, o autor. Com a peça lavrada a
     * juntada continua aberta — ao autor e ao administrador —, e o anexo sai
     * marcado como "juntado depois". Peça encerrada não recebe mais nada.
     * (Excluir é outra regra, mais estreita: DocumentoAnexo::podeSerExcluidoPor.)
     */
    public function podeJuntarAnexo(User $u): bool
    {
        if ($this->encerrado()) {
            return false;
        }

        return $this->agente_id === $u->id || (! $this->naoLavrado() && $u->isAdmin());
    }

    /** A ordem de serviço que determinou a notificação (origem_motivo = ordem_servico). */
    public function origemOs(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'origem_os_id'); }

    /**
     * O QUE LEVOU À NOTIFICAÇÃO — a origem das peças que começam a cadeia.
     * (O auto nasce de outra peça: ver ORIGENS e `origem_id`.)
     */
    public const MOTIVOS_DE_ORIGEM = [
        'direta'        => 'Direta — vistoria em campo',
        'ordem_servico' => 'Ordem de serviço',
        'ouvidoria'     => 'Denúncia da ouvidoria',
    ];

    /** Só as notificações têm motivo de origem; os autos têm peça de origem. */
    public function temMotivoDeOrigem(): bool
    {
        return in_array($this->tipo, self::COM_CUMPRIMENTO, true);
    }

    /**
     * A origem como sai no topo da peça impressa: "DIRETA", "ORDEM DE SERVIÇO
     * Nº 12/2026", "OUVIDORIA Nº 4471/2026" — ou, nos autos, a peça anterior.
     */
    public function origemTexto(): string
    {
        if ($this->origem) {
            return mb_strtoupper($this->origem->rotuloTipo()) . ' Nº ' . $this->origem->numeroFormatado();
        }
        if ($this->origem_motivo === 'ordem_servico') {
            return 'ORDEM DE SERVIÇO' . ($this->origemOs ? ' Nº ' . $this->origemOs->numero : '');
        }
        if ($this->origem_motivo === 'ouvidoria') {
            return 'OUVIDORIA' . ($this->origem_referencia ? ' Nº ' . $this->origem_referencia : '');
        }

        return 'DIRETA';
    }

    /**
     * Quem pode mexer na origem de uma notificação JÁ LAVRADA: o autor, ou o
     * administrador. Peça anulada não se altera mais.
     */
    public function podeEditarOrigem(User $u): bool
    {
        return $this->temMotivoDeOrigem()
            && in_array($this->status, ['lavrado', 'em_defesa', 'atendido'], true)
            && ($this->agente_id === $u->id || $u->isAdmin());
    }

    /** O auto de infração anterior, de que este é reincidência. */
    public function reincidenciaDe(): BelongsTo { return $this->belongsTo(Documento::class, 'reincidencia_de_id'); }

    /** Por quanto a reincidência multiplica a multa: dobra a cada elo (1, 2, 4, 8…). */
    public function fatorReincidencia(): int
    {
        return 2 ** min((int) $this->reincidencia_nivel, 6);
    }
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

        // RASCUNHO: sem número, só do autor — grava ou exclui.
        if ($this->status === 'rascunho') {
            if ($autor) {
                $opcoes[] = 'excluir';
            }
            return $opcoes;
        }

        // GRAVADO: tem número. Não se exclui mais: o autor lavra ou cancela.
        if ($this->status === 'gravado') {
            if ($autor) {
                $opcoes[] = 'lavrar';
            }
            if ($autor || $u->isAdmin()) {
                $opcoes[] = 'cancelar';
            }
            return $opcoes;
        }

        // DEFESA: aparece no auto que pode recebê-la agora, e em todo auto que
        // já tem uma registrada — para quem só vai consultar a decisão.
        if ($this->admiteDefesa() && (! empty($this->defesa) || $this->podeProtocolarDefesa($u))) {
            $opcoes[] = 'defesa';
        }

        // LAVRADO (ou em defesa): cancelar é ato do autor, ou do administrador
        // quando o autor já não responde pelo documento (afastamento,
        // desligamento) — com motivo e a senha de quem cancela. Encerrado não
        // se cancela de novo.
        if (in_array($this->status, ['lavrado', 'em_defesa', 'atendido'], true) && ($autor || $u->isAdmin())) {
            $opcoes[] = 'cancelar';
        }

        return $opcoes;
    }

    public function rotuloTipo(): string { return self::TIPOS[$this->tipo][0] ?? $this->tipo; }
    public function sigla(): string      { return self::TIPOS[$this->tipo][1] ?? '?'; }

    /**
     * Como OUTRA peça se refere a esta, já com o artigo: "a Notificação de
     * Embargo nº 12/2026", "o Auto de Embargo nº 3/2026". É o que o marcador
     * {origem} escreve no texto de ciência.
     */
    public function referencia(): string
    {
        $artigo = str_starts_with($this->tipo, 'auto') ? 'o' : 'a';

        return $artigo . ' ' . $this->rotuloTipo() . ' nº ' . $this->numeroFormatado();
    }

    /**
     * De quais tipos uma peça pode NASCER. O auto de infração vem de uma
     * notificação ou de um embargo descumprido; o auto de embargo, de uma
     * notificação. Notificação é o começo: não tem origem.
     */
    public const ORIGENS = [
        'auto_infracao' => ['notificacao', 'notificacao_embargo', 'auto_embargo'],
        'auto_embargo'  => ['notificacao', 'notificacao_embargo'],
    ];

    /** "NOT 2026/0231" — ou "Sem número" no rascunho, que ainda não foi gravado. */
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

    /** Rascunho e gravado se editam; lavrado, não. */
    public function podeSerEditado(): bool
    {
        return $this->naoLavrado();
    }

    /**
     * Situação do prazo, para a tag da lista.
     *
     * Devolve [texto, classe] ou null quando o documento não tem prazo — o que
     * é o caso da vistoria documental e de tudo que já foi atendido ou anulado.
     */
    public function situacaoPrazo(): ?array
    {
        if ($this->status === 'atendido' || $this->encerrado()) {
            return null;
        }
        // Com a defesa protocolada, o prazo de defesa já cumpriu o papel dele.
        if ($this->status === 'em_defesa' || $this->defesaJulgada()) {
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
            'gravado'   => ['Gravado', 'bd-in'],
            // Defesa indeferida: a peça voltou a lavrada, agora apta à cobrança.
            'lavrado'   => ($this->defesa['resultado'] ?? null) === 'indeferida' ? ['Lavrado · apto', 'bd-al'] : ['Lavrado', 'bd-al'],
            'em_defesa' => ['Em defesa', 'bd-in'],
            'atendido'  => ['Atendido', 'bd-ok'],
            'anulado'   => ['Cancelado', 'bd-cx'],   // nome antigo do cancelamento
            'cancelado' => ['Cancelado', 'bd-cx'],
            'defendido' => ['Defendido', 'bd-ok'],
            default     => [$this->status, 'bd-in'],
        };
    }
}
