<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * O equipamento que mede o ruído no auto da Lei 723/2002. A peça cita o
 * aparelho e a calibração: medição com equipamento vencido não se sustenta.
 */
class Decibelimetro extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data_calibracao' => 'date', 'ativo' => 'boolean'];
    }

    /** Até quando vale a calibração — nula se faltar a data ou a validade. */
    public function calibradoAte(): ?CarbonInterface
    {
        return $this->data_calibracao && $this->validade_calibracao_meses
            ? $this->data_calibracao->copy()->addMonths($this->validade_calibracao_meses)
            : null;
    }

    public function calibracaoVencida(?CarbonInterface $em = null): bool
    {
        $ate = $this->calibradoAte();

        return $ate !== null && $ate->lt(($em ?? now())->copy()->startOfDay());
    }

    /** "Instrutherm DEC-460 · série 0921" */
    public function rotulo(): string
    {
        return trim(implode(' ', array_filter([$this->fabricante, $this->modelo])))
            . ($this->serie ? ' · série ' . $this->serie : '')
            ?: ($this->identificacao ?: $this->tipo);
    }
}
