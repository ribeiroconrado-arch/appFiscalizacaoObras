<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A Ordem de Serviço: o que a coordenação determina a quem vai a campo.
 *
 * O sistema registrava o que o fiscal FEZ; não havia onde dizer o que ele DEVE
 * fazer. A distribuição do trabalho vivia no papel e no grupo de mensagens —
 * e o que fica de fora do sistema não entra em relatório, não cobra prazo e
 * não responde "quem estava incumbido disto?".
 */
class OrdemServico extends Model
{
    use RegistraAuditoria;

    protected $table = 'ordens_servico';

    protected $guarded = [];

    /** Cada um só alcança OS dos módulos que tem — ver Documento::booted. */
    protected static function booted(): void
    {
        static::addGlobalScope('modulos_do_usuario', function ($q) {
            if ($u = auth()->user()) {
                $q->whereIn($q->getModel()->qualifyColumn('modulo'), $u->modulos());
            }
        });
    }

    /** Só as do módulo em que o usuário está trabalhando (App\Support\Modulo). */
    public function scopeDoModulo(\Illuminate\Database\Eloquent\Builder $q, ?string $modulo = null): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where($this->qualifyColumn('modulo'), $modulo ?? \App\Support\Modulo::ativo());
    }

    /**
     * Contínua x específica não é rótulo: muda o que significa "concluída".
     * A específica termina quando a tarefa é cumprida; a contínua termina
     * quando o período acaba ou quando a coordenação encerra.
     */
    public const NATUREZAS = [
        'especifica' => 'Serviço específico',
        'continua'   => 'Serviço contínuo',
    ];

    /**
     * Como o trabalho se marca no tempo.
     *
     * 'periodo' é uma janela contínua; 'dias' são datas soltas, cada uma com o
     * seu horário. Guardar as duas como um par de datas obrigaria a inventar
     * um "todos os dias, das 8h às 18h" que ninguém combinou.
     */
    public const REGIMES = [
        'periodo' => 'Por período',
        'dias'    => 'Em dias marcados',
    ];

    public const SITUACOES = [
        'aberta'       => ['texto' => 'Aberta',       'classe' => 'bd-al'],
        'em_andamento' => ['texto' => 'Em andamento', 'classe' => 'bd-in'],
        'concluida'    => ['texto' => 'Concluída',    'classe' => 'bd-ok'],
        'cancelada'    => ['texto' => 'Cancelada',    'classe' => 'bd-fim'],
    ];

    public const PRIORIDADES = ['normal' => 'Normal', 'alta' => 'Alta'];

    protected function casts(): array
    {
        return [
            'inicio'       => 'date',
            'fim'          => 'date',
            'encerrada_em' => 'datetime',
            'assinada_em'  => 'datetime',
        ];
    }

    /**
     * Os designados, com a CIÊNCIA de cada um.
     *
     * `withPivot` é obrigatório: sem declarar as colunas, o Eloquent grava
     * (updateExistingPivot escreve direto no banco) mas não LÊ — e a via
     * impressa sairia sem a assinatura de quem acabou de assinar, sem erro
     * nenhum para avisar.
     */
    public function fiscais(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'os_fiscais')
            ->withPivot(['ciencia_em', 'assinatura'])
            ->withTimestamps();
    }

    /** Os dias marcados, em ordem cronológica — que é a ordem em que se cumpre. */
    public function jornadas(): HasMany
    {
        return $this->hasMany(OsJornada::class)->orderBy('data')->orderBy('hora_inicio');
    }

    public function emitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    public function lote(): BelongsTo      { return $this->belongsTo(Lote::class); }
    public function protocolo(): BelongsTo { return $this->belongsTo(Protocolo::class); }

    /**
     * O próximo número do ano, em transação.
     *
     * `lockForUpdate` porque duas OS emitidas no mesmo segundo por dois
     * coordenadores pegariam a mesma sequência — e número repetido numa peça
     * que se cita em ofício é defeito que só aparece depois, na hora errada.
     */
    /**
     * "OS 0012/2026" — sigla, número e ano, como os outros documentos.
     *
     * A coluna `numero` guarda "2026/0012" (é a chave única de sempre, e as
     * já emitidas continuam com ela); quem MOSTRA o número usa isto.
     */
    public function numeroFormatado(): string
    {
        return $this->sequencia && $this->ano
            ? sprintf('OS %04d/%d', $this->sequencia, $this->ano)
            : 'OS ' . $this->numero;
    }

    public static function proximoNumero(?int $ano = null): array
    {
        $ano ??= (int) date('Y');

        // Sem o escopo de módulos: o número é único na tabela inteira (os dois
        // módulos dividem a série), e o máximo tem de enxergar todas as OS.
        $ultima = static::withoutGlobalScope('modulos_do_usuario')
            ->where('ano', $ano)->lockForUpdate()->max('sequencia') ?? 0;
        $seq = $ultima + 1;

        return ['ano' => $ano, 'sequencia' => $seq,
                'numero' => sprintf('%d/%04d', $ano, $seq)];
    }

    /**
     * Quando a ordem acontece, em uma linha.
     *
     * O texto muda com o regime porque as duas coisas se leem diferente: um
     * período é um intervalo; dias marcados são uma agenda.
     */
    public function quandoRotulo(): string
    {
        if ($this->regime === 'dias') {
            $n = $this->jornadas()->count();
            if ($n === 0) { return 'sem dias marcados'; }
            $primeira = $this->jornadas()->first();
            return $n === 1
                ? $primeira->rotulo()
                : "{$n} dias marcados, a partir de " . $primeira->data->format('d/m/Y');
        }

        $de = $this->inicio?->format('d/m/Y');
        $ate = $this->fim?->format('d/m/Y');

        if ($de && $ate) { return "de {$de} a {$ate}"; }
        if ($de)         { return "a partir de {$de}"; }
        if ($ate)        { return "até {$ate}"; }

        return 'sem prazo fixado';
    }

    /** A OS está vencida? Só faz sentido enquanto ela ainda está em curso. */
    public function vencida(): bool
    {
        if (in_array($this->situacao, ['concluida', 'cancelada'], true)) { return false; }

        $limite = $this->regime === 'dias'
            ? $this->jornadas()->max('data')
            : $this->fim?->toDateString();

        return $limite !== null && $limite < date('Y-m-d');
    }

    public function situacaoTag(): array
    {
        // Vencida não é situação gravada: é leitura do relógio sobre a
        // situação gravada. Guardá-la exigiria alguém correndo o banco todo
        // dia para virar o estado — e um dia esse alguém falha.
        if ($this->vencida()) {
            return ['texto' => 'Prazo vencido', 'classe' => 'bd-er'];
        }

        return self::SITUACOES[$this->situacao] ?? ['texto' => $this->situacao, 'classe' => 'bd-in'];
    }
}
