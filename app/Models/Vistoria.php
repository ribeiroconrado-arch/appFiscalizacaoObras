<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vistoria extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Sem cast para datetime com fuso: o valor é o horário LOCAL que o
            // fiscal viu na tela, gravado como está. Converter aqui reintroduz
            // o deslocamento de dia que a migration explica.
            'data_hora' => 'datetime',
            'latitude'  => 'float',
            'longitude' => 'float',
            'accuracy'  => 'float',
        ];
    }

    public const SITUACOES = [
        'regular'           => 'Regular',
        'irregular'         => 'Irregular',
        'em_acompanhamento' => 'Em acompanhamento',
        'nao_localizado'    => 'Não localizado',
    ];

    public function lote(): BelongsTo          { return $this->belongsTo(Lote::class); }
    public function obra(): BelongsTo          { return $this->belongsTo(Obra::class); }
    public function fiscal(): BelongsTo        { return $this->belongsTo(User::class, 'fiscal_id'); }
    public function evidencias(): HasMany      { return $this->hasMany(Evidencia::class); }

    public function irregularidades(): BelongsToMany
    {
        return $this->belongsToMany(Irregularidade::class, 'vistoria_irregularidades')
                    ->withPivot('observacao')
                    ->withTimestamps();
    }

    /** Rótulo da situação para exibição. */
    public function situacaoRotulo(): string
    {
        return self::SITUACOES[$this->situacao] ?? $this->situacao;
    }

    /**
     * Classe da tag "Modelo D" correspondente à situação.
     * Mantida no modelo, e não na view, para que dashboard, lista e ficha
     * pintem o mesmo status da mesma cor sem repetir o mapeamento.
     */
    public function situacaoBadge(): string
    {
        return match ($this->situacao) {
            'regular'           => 'bd-ok',
            'irregular'         => 'bd-in',
            'em_acompanhamento' => 'bd-pe',
            default             => 'bd-pe',
        };
    }
}
