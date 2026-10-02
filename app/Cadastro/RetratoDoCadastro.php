<?php

namespace App\Cadastro;

use App\Models\Lote;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A planilha de uma conferência anterior, GUARDADA (tabela cadastro_retratos).
 *
 * Implementa FonteDoCadastro para entrar na conferência pelo mesmo caminho da
 * planilha e do cadastro carregado: é o que deixa "Conferir de novo" — e a
 * reconferência automática depois de cada correção no mapa — trabalhar sem
 * anexar o Excel outra vez. Ver ConferenciaComCadastro::fonteDoPedido.
 */
class RetratoDoCadastro implements FonteDoCadastro
{
    public ?bool $temSituacao = null;

    /** @param list<array<string,mixed>> $linhas */
    private function __construct(private array $linhas, public readonly string $descricao, public readonly int $id) {}

    public static function carregar(int $id): self
    {
        $r = DB::table('cadastro_retratos')->find($id)
            ?? throw new RuntimeException('A planilha da última conferência não está mais guardada. Anexe-a de novo.');
        $f = new self(json_decode($r->linhas, true) ?: [], $r->fonte_descricao, (int) $r->id);
        $f->temSituacao = $r->tem_situacao === null ? null : (bool) $r->tem_situacao;

        return $f;
    }

    /**
     * Guarda o que a fonte disse do bairro e devolve o id do retrato.
     *
     * @param  list<array<string,mixed>>  $linhas
     */
    public static function guardar(string $codigoBairro, string $descricao, array $linhas, ?bool $temSituacao): int
    {
        return (int) DB::table('cadastro_retratos')->insertGetId([
            'codigo_bairro'   => $codigoBairro,
            'fonte_descricao' => mb_substr($descricao, 0, 200),
            'tem_situacao'    => $temSituacao,
            'linhas'          => json_encode($linhas, JSON_UNESCAPED_UNICODE),
            'user_id'         => auth()->id(),
            'created_at'      => now(),
        ]);
    }

    public function nome(): string
    {
        return 'planilha';
    }

    public function consultar(Lote $lote): ?RetratoBci
    {
        return null;
    }

    public function porQueVazio(Lote $lote): string
    {
        return 'A planilha guardada de uma conferência não alimenta a ficha do imóvel.';
    }

    public function imoveisDoBairro(string $codigoBairro): iterable
    {
        return $this->linhas;
    }
}
