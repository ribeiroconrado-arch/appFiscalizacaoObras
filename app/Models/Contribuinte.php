<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * Quem é notificado ou autuado, pelo CPF ou CNPJ — o cadastro do Posturas.
 * Serve ao autocomplete do autuado nos dois módulos.
 */
class Contribuinte extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];
}
