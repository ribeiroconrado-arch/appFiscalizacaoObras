<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item de LEI dentro do relatório da vistoria.
 *
 * Nasceu como simples tabela de ligação (vistoria ↔ artigo) e virou item
 * próprio quando o relatório passou a ser uma lista montada pelo fiscal. Dois
 * tipos, e a diferença não é de forma:
 *
 *   citacao   o que se constatou em relação ao dispositivo — vira FATO na peça;
 *   parecer   a conclusão do fiscal sobre ele — vira FUNDAMENTAÇÃO.
 *
 * Guardar os dois no mesmo saco obrigaria quem lê meses depois a adivinhar se
 * aquela linha descreve o que foi visto ou o que o fiscal concluiu.
 */
class VistoriaArtigo extends Model
{
    protected $table = 'vistoria_artigos';

    protected $guarded = [];

    public const TIPOS = [
        'citacao' => 'Artigo citado',
        'parecer' => 'Parecer sobre o artigo',
    ];

    protected function casts(): array
    {
        return ['ordem' => 'integer'];
    }

    public function vistoria(): BelongsTo
    {
        return $this->belongsTo(Vistoria::class);
    }

    public function artigo(): BelongsTo
    {
        return $this->belongsTo(Artigo::class);
    }

    /** "Art. 55 — parecer: a obra atende ao recuo exigido" */
    public function rotulo(): string
    {
        $prefixo = $this->artigo?->numero ?? 'Artigo';
        $meio = $this->tipo === 'parecer' ? ' — parecer: ' : ' — ';

        return $this->observacao
            ? $prefixo . $meio . $this->observacao
            : $prefixo;
    }
}
