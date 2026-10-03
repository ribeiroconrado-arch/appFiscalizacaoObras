<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UM ITEM DO RELATÓRIO DE VISTORIA — um grupo, e não uma linha.
 *
 * Em campo o que se constata não vem separado: "muro sem recuo" é o artigo que
 * ele infringe, mais o que o fiscal escreveu, mais o que se exige, mais as fotos
 * que provam. O item junta isso, e é ele que se move
 * para cima e para baixo — os blocos caminham como um só.
 *
 * A ORDEM DENTRO DO ITEM é fixa e não se escolhe:
 *
 *   1. artigos           o enquadramento: só se atua no que está fora da lei,
 *                        e é o artigo que diz o que está
 *   2. texto livre       o que o fiscal viu, com as palavras dele
 *   3. exigências        o que se cobra, com prazo
 *   4. fotos             a prova
 *
 * É a ordem do raciocínio de uma peça: a infração, a narrativa, a
 * providência e a prova. Deixá-la à escolha faria cada relatório sair numa
 * ordem diferente, e quem lê vinte por semana perde o hábito de leitura.
 */
class VistoriaItem extends Model
{
    protected $table = 'vistoria_itens';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ordem' => 'integer'];
    }

    public function vistoria(): BelongsTo
    {
        return $this->belongsTo(Vistoria::class);
    }

    /** As fotos e anexos deste item, na ordem em que foram postos. */
    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class, 'item_id')->orderBy('ordem')->orderBy('id');
    }

    /** Os artigos citados aqui, com o que o fiscal escreveu sobre cada um. */
    public function artigos(): HasMany
    {
        return $this->hasMany(VistoriaArtigo::class, 'item_id')->orderBy('ordem')->orderBy('id');
    }

    public function exigencias(): HasMany
    {
        return $this->hasMany(VistoriaExigencia::class, 'item_id')->orderBy('ordem')->orderBy('id');
    }

    /** Item sem nada dentro não deveria existir — a tela não deixa criar um. */
    public function vazio(): bool
    {
        return ! $this->texto
            && $this->artigos->isEmpty()
            && $this->exigencias->isEmpty()
            && $this->evidencias->isEmpty();
    }
}
