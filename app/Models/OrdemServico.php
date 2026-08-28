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
        'cancelada'    => ['texto' => 'Cancelada',    'classe' => 'bd-cx'],
    ];

    public const PRIORIDADES = ['normal' => 'Normal', 'alta' => 'Alta'];

    protected function casts(): array
    {
        return [
            'inicio'       => 'date',
            'fim'          => 'date',
            'encerrada_em' => 'datetime',
        ];
    }

    public function fiscais(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'os_fiscais')->withTimestamps();
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
    public static function proximoNumero(?int $ano = null): array
    {
        $ano ??= (int) date('Y');

        $ultima = static::where('ano', $ano)->lockForUpdate()->max('sequencia') ?? 0;
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
            return ['texto' => 'Prazo vencido', 'classe' => 'bd-cr'];
        }

        return self::SITUACOES[$this->situacao] ?? ['texto' => $this->situacao, 'classe' => 'bd-in'];
    }
}
