<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Artigo citado num documento — CÓPIA do texto no momento da lavratura.
 *
 * Não é redundância com a tabela `artigos`: se a lei for alterada depois, o
 * documento já emitido tem de continuar exibindo o que foi imputado na época.
 * Um auto de infração é peça de processo administrativo; seu conteúdo não
 * pode mudar sozinho porque alguém editou o cadastro da lei meses depois.
 */
class DocumentoArtigo extends Model
{
    protected $table = 'documento_artigos';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'multa_upf'    => 'float',
            'multa_upf_m2' => 'float',
            'area_m2'      => 'float',
            'valor_upf'    => 'float',
        ];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function artigo(): BelongsTo
    {
        return $this->belongsTo(Artigo::class);
    }
}
