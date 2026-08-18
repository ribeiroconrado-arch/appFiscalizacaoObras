<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Upf extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['valor' => 'float', 'vigencia_inicio' => 'date'];
    }

    /**
     * UPF vigente numa data — hoje, por padrão.
     *
     * Pega o registro do exercício com a maior vigência que já começou, não
     * simplesmente o do ano corrente: cobre o caso de decreto atualizando a
     * UPF no meio do ano, e documento antigo consultado depois continua
     * enxergando a UPF que valia na época dele.
     */
    public static function vigente(?\DateTimeInterface $data = null): ?self
    {
        $data ??= now();

        return static::query()
            ->where('vigencia_inicio', '<=', $data->format('Y-m-d'))
            ->orderByDesc('vigencia_inicio')
            ->first();
    }
}
