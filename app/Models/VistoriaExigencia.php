<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma providência exigida do administrado, constatada na vistoria.
 *
 * Existe como LINHA, e não como parágrafo dentro das observações, porque é
 * assim que ela é usada depois: a notificação imprime a lista, o prazo de cada
 * item conta a partir da ciência, e o retorno da fiscalização confere item a
 * item. Guardada como texto corrido, cada uma dessas etapas exigiria alguém
 * reler e reinterpretar o que o fiscal escreveu.
 */
class VistoriaExigencia extends Model
{
    protected $table = 'vistoria_exigencias';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prazo_dias' => 'integer', 'ordem' => 'integer'];
    }

    public function vistoria(): BelongsTo
    {
        return $this->belongsTo(Vistoria::class);
    }

    /** "Apresentar o alvará — prazo de 15 dias" */
    public function rotulo(): string
    {
        return $this->texto . ($this->prazo_dias ? " — prazo de {$this->prazo_dias} dias" : '');
    }
}
