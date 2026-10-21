<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * O bairro como aparece no ENDEREÇO (denúncia, autuado): a lista do Posturas,
 * por nome. Não confundir com CadastroBairro, que amarra o nome do desenho dos
 * lotes ao código do cadastro imobiliário.
 */
class BairroEndereco extends Model
{
    protected $table = 'bairros_endereco';

    protected $guarded = [];
}
