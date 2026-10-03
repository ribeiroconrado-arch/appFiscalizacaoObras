<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Uma planilha do cadastro municipal recebida e processada.
 * Ver a migração `cargas_do_cadastro` e App\Cadastro\CargaDoCadastro.
 */
class CadastroCarga extends Model
{
    protected $table = 'cadastro_cargas';

    protected $guarded = [];

    /** Situações em que ainda há trabalho a fazer — e o arquivo precisa existir. */
    public const EM_ABERTO = ['na_fila', 'processando', 'aguardando_confirmacao', 'falhou'];

    protected function casts(): array
    {
        return [
            'bairros'      => 'array',
            'primeira'     => 'boolean',
            'iniciada_em'  => 'datetime',
            'gravacao_iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** A carga veio do JSON do app desktop, e não da planilha? */
    public function porJson(): bool
    {
        return str_ends_with(strtolower((string) $this->arquivo_nome), '.json');
    }

    /** Onde o arquivo fica enquanto a carga não termina (disco `private`, que nunca é servido). */
    public function caminhoDoArquivo(): string
    {
        return "cargas/{$this->id}." . ($this->porJson() ? 'json' : 'xlsx');
    }

    /**
     * Apaga o arquivo das cargas que não vão mais precisar dele: concluídas e,
     * depois de 7 dias, as que ficaram paradas ou falharam. A planilha traz CPF;
     * nada de deixá-la esquecida no disco. Chamado a cada envio, na listagem
     * da tela e pelo comando `cadastro:processar-cargas`.
     */
    public static function limparArquivosVencidos(): int
    {
        $disco = Storage::disk('private');
        $apagados = 0;

        $vencidas = self::query()
            ->where(fn ($q) => $q->where('status', 'concluida')
                ->orWhere('created_at', '<', now()->subDays(7)))
            ->get(['id']);

        foreach ($vencidas as $c) {
            if ($disco->exists($c->caminhoDoArquivo())) {
                $disco->delete($c->caminhoDoArquivo());
                $apagados++;
            }
        }

        return $apagados;
    }

    /** @return array<string,mixed> */
    public function resumo(): array
    {
        return [
            'id'           => $this->id,
            'arquivo'      => $this->arquivo_nome,
            'origem'       => $this->porJson() ? 'app' : 'planilha',
            'bytes'        => $this->arquivo_bytes,
            'usuario'      => $this->usuario?->name,
            'status'       => $this->status,
            'mensagem'     => $this->mensagem,
            'linhas_lidas' => $this->linhas_lidas,
            'novos'        => $this->novos,
            'alterados'    => $this->alterados,
            'iguais'       => $this->iguais,
            'ausentes'     => $this->ausentes,
            'reaparecidos' => $this->reaparecidos,
            'primeira'     => $this->primeira,
            'bairros'      => count($this->bairros ?? []),
            'enviada_em'   => $this->created_at?->toIso8601String(),
            'concluida_em' => $this->concluida_em?->toIso8601String(),
        ];
    }
}
