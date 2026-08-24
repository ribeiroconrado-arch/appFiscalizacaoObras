<?php

namespace App\Cadastro;

use App\Models\Bci\BciImovel;
use App\Models\Lote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Grava na cópia local (bci_*) o que a fonte respondeu.
 *
 * Substitui inteiro, não faz mescla: o BCI é um RETRATO do imóvel hoje, e
 * mesclar deixaria na tela característica que o cadastro já removeu — dado
 * fantasma, que é pior do que dado velho, porque não se sabe que é velho.
 * A data em que o retrato foi tirado fica em `consultado_em`, e é ela que a
 * ficha mostra como "Últ. Integração".
 */
class SincronizaBci
{
    public function __construct(private FonteDoCadastro $fonte)
    {
    }

    /** Consulta a fonte e regrava a cópia. Devolve null se o cadastro não tem o imóvel. */
    public function atualizar(Lote $lote): ?BciImovel
    {
        $retrato = $this->fonte->consultar($lote);
        if (! $retrato) {
            return null;
        }

        return DB::transaction(function () use ($lote, $retrato) {
            $bci = BciImovel::updateOrCreate(
                ['lote_id' => $lote->id],
                $retrato->imovel + [
                    'consultado_em'  => now(),
                    'consultado_por' => Auth::user()?->name ?? 'sistema',
                ]
            );

            $bci->caracteristicas()->delete();
            $ordem = 0;
            foreach ($retrato->caracteristicas as $chave => $valor) {
                $bci->caracteristicas()->create([
                    'chave' => $chave,
                    'valor' => $valor,
                    'ordem' => $ordem++,
                ]);
            }

            $bci->unidades()->delete();
            foreach ($retrato->unidades as $u) {
                $bci->unidades()->create($u);
            }

            return $bci->fresh(['caracteristicas', 'unidades']);
        });
    }

    /** Por que a última consulta veio vazia — para a tela explicar, e não só encolher os ombros. */
    public function porQueVazio(Lote $lote): string
    {
        return $this->fonte->porQueVazio($lote);
    }
}
