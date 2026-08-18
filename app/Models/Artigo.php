<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Artigo extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ativo'         => 'boolean',
            'multa_upf'     => 'float',
            'multa_upf_m2'  => 'float',
            'multa_min_upf' => 'float',
            'multa_max_upf' => 'float',
        ];
    }

    /** Rótulo curto de cada base de cálculo, para exibir na lista de artigos. */
    public const BASES_MULTA = [
        'fixa'            => 'Valor fixo',
        'area_construida' => 'Por m² construído',
        'area_terreno'    => 'Por m² de terreno',
        'sem_multa'       => 'Sem multa',
    ];

    /**
     * Calcula a multa deste artigo para uma área informada.
     *
     * Centralizado aqui — e não no controller ou no front — porque é regra
     * jurídica: o mesmo cálculo tem de valer na sugestão ao fiscal, na
     * lavratura e em qualquer relatório futuro. Divergir entre esses pontos
     * é o tipo de inconsistência que uma defesa administrativa explora.
     *
     * @return array{valor: float, memoria: string}
     */
    public function calcularMulta(?float $areaTerreno, ?float $areaConstruida): array
    {
        $area = match ($this->base_multa) {
            'area_terreno'    => $areaTerreno,
            'area_construida' => $areaConstruida,
            default           => null,
        };

        if ($this->base_multa === 'sem_multa') {
            return ['valor' => 0.0, 'memoria' => 'Sem multa — só embasa notificação/embargo.'];
        }

        if ($this->base_multa === 'fixa') {
            $valor = (float) ($this->multa_upf ?? 0);
            return ['valor' => $valor, 'memoria' => sprintf('%.2f UPF (valor fixo do artigo)', $valor)];
        }

        // Área por medir: o cálculo não pode fingir um valor, senão a multa
        // sai errada silenciosamente. Melhor recusar e pedir a área.
        if ($area === null) {
            return ['valor' => 0.0, 'memoria' => 'Área não informada — multa não calculada.'];
        }

        $bruto = (float) ($this->multa_upf_m2 ?? 0) * $area;
        $valor = $bruto;
        $memoria = sprintf('%.4f UPF/m² × %.2f m² = %.2f UPF', (float) $this->multa_upf_m2, $area, $bruto);

        if ($this->multa_min_upf !== null && $valor < (float) $this->multa_min_upf) {
            $valor = (float) $this->multa_min_upf;
            $memoria .= sprintf(' → piso de %.2f UPF aplicado', $valor);
        } elseif ($this->multa_max_upf !== null && $valor > (float) $this->multa_max_upf) {
            $valor = (float) $this->multa_max_upf;
            $memoria .= sprintf(' → teto de %.2f UPF aplicado', $valor);
        }

        return ['valor' => round($valor, 2), 'memoria' => $memoria];
    }

    public function legislacao(): BelongsTo
    {
        return $this->belongsTo(Legislacao::class);
    }

    /**
     * Irregularidades que este artigo enquadra.
     *
     * É esta relação que faz o motor de legislação funcionar: marcada a
     * irregularidade na vistoria, o sistema já sabe qual dispositivo citar,
     * em vez de o fiscal procurar artigo na lei impressa.
     */
    public function irregularidades(): BelongsToMany
    {
        return $this->belongsToMany(Irregularidade::class, 'artigo_irregularidade')->withTimestamps();
    }

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->where('ativo', true)->orderBy('numero');
    }

    public function rotulo(): string
    {
        return $this->apelido ?: $this->numero;
    }
}
