<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UM ITEM DO RELATÓRIO DE VISTORIA — um grupo, e não uma linha.
 *
 * Em campo o que se constata não vem separado: "muro sem recuo" é uma
 * irregularidade, mais o que o fiscal escreveu sobre ela, mais os artigos que a
 * enquadram, mais as fotos que a provam. O item junta isso, e é ele que se move
 * para cima e para baixo — os blocos caminham como um só.
 *
 * A ORDEM DENTRO DO ITEM é fixa e não se escolhe:
 *
 *   1. irregularidades   o que a lei chama de infração
 *   2. texto livre       o que o fiscal viu, com as palavras dele
 *   3. artigos           o enquadramento
 *   4. exigências        o que se cobra, com prazo
 *   5. fotos             a prova
 *
 * É a ordem do raciocínio de uma peça: o fato, a narrativa, a lei, a
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

    /**
     * As irregularidades deste item.
     *
     * O vínculo mora na MESMA tabela que liga irregularidade e vistoria — ela
     * ganhou `item_id`. Uma tabela nova duplicaria a verdade: a vistoria
     * continua "tendo" a irregularidade (é o que a regra da situação e a
     * sugestão de artigos leem), e agora se sabe também em que item ela foi
     * constatada.
     */
    public function irregularidades(): BelongsToMany
    {
        return $this->belongsToMany(
            Irregularidade::class,
            'vistoria_irregularidades',
            'item_id',
            'irregularidade_id'
        )->withPivot('observacao');
    }

    /** Item sem nada dentro não deveria existir — a tela não deixa criar um. */
    public function vazio(): bool
    {
        return ! $this->texto
            && $this->irregularidades->isEmpty()
            && $this->artigos->isEmpty()
            && $this->exigencias->isEmpty()
            && $this->evidencias->isEmpty();
    }
}
