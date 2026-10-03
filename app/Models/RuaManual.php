<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * Um trecho de rua informado pelo curador: o nome de um lado de quadra que o
 * voto dos lotes não decidiu (ou decidiu errado), ou uma rua sem lote de
 * frente, desenhada à mão. Vale por cima do trecho gerado com que coincide
 * (App\Cadastro\TrechosDeRua) e o "Gerar" nunca o apaga.
 */
class RuaManual extends Model
{
    use RegistraAuditoria;

    protected $table = 'ruas_manuais';

    protected $fillable = ['bairro', 'nome', 'oculto', 'de_lat', 'de_lon', 'ate_lat', 'ate_lon', 'user_id'];

    protected $casts = ['oculto' => 'boolean'];

    /** O que a trilha de auditoria mostra no lugar do id. */
    protected function descricaoAuditoria(): ?string
    {
        return 'Nome de rua em ' . $this->bairro . ': ' . ($this->oculto ? '(oculto)' : ($this->nome ?? '—'));
    }
}
