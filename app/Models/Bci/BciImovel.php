<?php

namespace App\Models\Bci;

use App\Models\Lote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cópia local do BCI de um lote (ver a migration para o porquê da cópia).
 *
 * Este model NÃO decide nada sobre o imóvel — ele guarda o que a prefeitura
 * respondeu. A única leitura de sentido que faz é `ativo()`, e ela está aqui
 * porque a regra ("Isenção diferente de Inativo é imóvel ativo") é do cadastro,
 * não da tela.
 */
class BciImovel extends Model
{
    protected $table = 'bci_imoveis';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'consultado_em'        => 'datetime',
            'area_terreno_m2'      => 'float',
            'area_edificada_m2'    => 'float',
            'testada_m'            => 'float',
            'medida_lado_direito'  => 'float',
            'medida_lado_esquerdo' => 'float',
            'medida_fundo'         => 'float',
            'fracao_ideal'         => 'float',
        ];
    }

    public function lote(): BelongsTo             { return $this->belongsTo(Lote::class); }
    public function proprietarios(): HasMany      { return $this->hasMany(BciProprietario::class, 'bci_imovel_id'); }
    public function unidades(): HasMany           { return $this->hasMany(BciUnidade::class, 'bci_imovel_id'); }

    public function caracteristicas(): HasMany
    {
        return $this->hasMany(BciCaracteristica::class, 'bci_imovel_id')->orderBy('ordem')->orderBy('chave');
    }

    /**
     * O imóvel está ativo no cadastro?
     *
     * A prefeitura não tem um campo "situação": quem responde é a Isenção.
     * Qualquer valor que não seja "Inativo" — Normal, Isento, e o que mais
     * vier — é imóvel ativo. Sem isenção nenhuma gravada, o cadastro não
     * disse nada, e `null` é a resposta honesta: a ficha mostra o que sabe
     * do lote, não uma suposição.
     */
    public function ativo(): ?bool
    {
        return $this->isencao === null || $this->isencao === ''
            ? null
            : mb_strtolower(trim($this->isencao)) !== 'inativo';
    }
}
