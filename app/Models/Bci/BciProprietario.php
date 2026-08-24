<?php

namespace App\Models\Bci;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BciProprietario extends Model
{
    protected $table = 'bci_proprietarios';

    protected $guarded = [];

    public function imovel(): BelongsTo { return $this->belongsTo(BciImovel::class, 'bci_imovel_id'); }

    /** Endereço do proprietário em uma linha, como se lê num ofício. */
    public function enderecoLinha(): string
    {
        $via = implode(', ', array_filter([$this->endereco_logradouro, $this->endereco_numero]));
        $cidade = implode('/', array_filter([$this->endereco_cidade, $this->endereco_uf]));

        return implode(' — ', array_filter([
            $via,
            implode(', ', array_filter([$this->endereco_bairro, $cidade])),
        ]));
    }
}
