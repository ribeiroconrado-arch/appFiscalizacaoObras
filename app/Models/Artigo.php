<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
            'termos'        => 'array',
            'multa_faixas'  => 'array',
            'multa_mult_min' => 'float',
            'multa_mult_max' => 'float',
        ];
    }

    /**
     * COMO a multa é calculada. A outra pergunta — sobre QUAL área — é
     * `multa_area`, e só existe para `por_m2` e `faixas`.
     */
    public const BASES_MULTA = [
        'sem_multa'       => 'Sem multa',
        'fixa'            => 'Valor fixo',
        'por_m2'          => 'Por m²',
        'faixas'          => 'Por faixa de área',
        'multiplo_alvara' => 'Múltiplo do valor do alvará',
    ];

    /**
     * Sobre QUAL área. `construida_ou_terreno` é o caso da escavação: havendo
     * obra, vale a área dela; não havendo, a do terreno.
     */
    public const AREAS_MULTA = [
        'construida'            => 'Área construída',
        'terreno'               => 'Área do terreno',
        'construida_ou_terreno' => 'Área construída; sem obra, a do terreno',
    ];

    /** As bases que dependem de área. */
    public const BASES_POR_AREA = ['por_m2', 'faixas'];

    /**
     * O artigo sustenta EMBARGO?
     *
     *   nao        não cabe embargo — é o caso da maioria;
     *   cabe       embarga e também serve à notificação e ao auto de infração
     *              (LC 001/2023, art. 32, §2º: "sob pena de embargo e/ou multa");
     *   exclusivo  só existe para embargar: aparece na Notificação de Embargo e
     *              no Auto de Embargo, e em nenhuma outra peça (art. 22, §§4º e 5º).
     */
    public const EMBARGO = [
        'nao'       => 'Não cabe embargo',
        'cabe'      => 'Cabe embargo',
        'exclusivo' => 'Exclusivo de embargo',
    ];

    /**
     * QUANDO o embargo acontece. A lei separa os dois casos, e é isso que
     * decide entre lavrar direto o Auto de Embargo ou dar antes a Notificação
     * de Embargo, com prazo.
     */
    public const EMBARGO_MODOS = [
        'imediato'   => 'Imediato',
        'apos_prazo' => 'Após prazo',
    ];

    /**
     * Este artigo pode fundamentar uma peça deste tipo?
     *
     * A REGRA ÚNICA: a tela filtra a lista por ela (documentos.js repete o
     * teste só para não oferecer o que o servidor recusaria), e o servidor a
     * impõe ao gravar e ao lavrar.
     */
    public function serveA(string $tipoDeDocumento): bool
    {
        return in_array($tipoDeDocumento, Documento::DE_EMBARGO, true)
            ? in_array($this->embargo, ['cabe', 'exclusivo'], true)
            : $this->embargo !== 'exclusivo';
    }

    /** "embarga de imediato", "embarga após 5 dias" — ou null se não embarga. */
    public function rotuloEmbargo(): ?string
    {
        if (! in_array($this->embargo, ['cabe', 'exclusivo'], true)) {
            return null;
        }

        return $this->embargo_modo === 'apos_prazo'
            ? 'embarga após ' . (int) $this->embargo_prazo_dias . ' dia(s)'
            : 'embarga de imediato';
    }

    /**
     * Calcula a multa deste artigo.
     *
     * Centralizado aqui — e não no controller ou no front — porque é regra
     * jurídica: o mesmo cálculo tem de valer na prévia que o fiscal vê, na
     * lavratura e em qualquer relatório futuro. A tela não refaz a conta: pede
     * a este método (POST /api/multas/simular).
     *
     * `pendencia` diz o que FALTA para calcular (área, valor do alvará,
     * multiplicador, UPF). Com pendência o valor é zero — e o Auto de Infração
     * não lavra: multa zero por dado faltando é multa errada.
     *
     * @return array{valor: float, valor_reais: ?float, area_usada: ?string, area_m2: ?float,
     *               multiplicador: ?float, memoria: string, pendencia: ?string}
     */
    public function calcularMulta(
        ?float $areaTerreno,
        ?float $areaConstruida,
        ?float $alvaraValor = null,
        ?float $multiplicador = null,
        ?float $upfValor = null,
    ): array {
        $r = ['valor' => 0.0, 'valor_reais' => null, 'area_usada' => null, 'area_m2' => null,
            'multiplicador' => null, 'memoria' => '', 'pendencia' => null];
        $n = fn (float $v, int $casas = 2) => number_format($v, $casas, ',', '.');

        if ($this->base_multa === 'sem_multa') {
            return ['memoria' => 'Sem multa — só embasa notificação/embargo.'] + $r;
        }

        if ($this->base_multa === 'fixa') {
            $valor = (float) ($this->multa_upf ?? 0);

            return ['valor' => $valor, 'memoria' => $n($valor) . ' UPF (valor fixo do artigo)'] + $r;
        }

        if ($this->base_multa === 'multiplo_alvara') {
            $min = (float) ($this->multa_mult_min ?? 1);
            $max = (float) ($this->multa_mult_max ?? $min);
            // Multiplicador fixo (3×) não se pergunta; intervalo (1 a 10×), sim.
            $mult = abs($max - $min) < 0.005 ? $min : $multiplicador;
            $faixa = $n($min) . ' a ' . $n($max) . '× o valor do alvará';

            if ($mult === null) {
                return ['memoria' => 'Multiplicador não informado (' . $faixa . ').',
                    'pendencia' => 'o multiplicador do ' . $this->numero . ' (' . $faixa . ')'] + $r;
            }
            if ($mult < $min - 0.005 || $mult > $max + 0.005) {
                return ['multiplicador' => $mult, 'memoria' => 'Multiplicador fora do intervalo (' . $faixa . ').',
                    'pendencia' => 'um multiplicador de ' . $faixa . ' para o ' . $this->numero] + $r;
            }
            if (! $alvaraValor) {
                return ['multiplicador' => $mult, 'memoria' => 'Valor do alvará não informado.',
                    'pendencia' => 'o valor do alvará'] + $r;
            }

            $reais = round($mult * $alvaraValor, 2);
            $memoria = $n($mult) . ' × R$ ' . $n($alvaraValor) . ' (valor do alvará) = R$ ' . $n($reais);
            if (! $upfValor) {
                return ['multiplicador' => $mult, 'valor_reais' => $reais, 'memoria' => $memoria,
                    'pendencia' => 'o valor da UPF do exercício (Parâmetros › UPF)'] + $r;
            }
            $valor = round($reais / $upfValor, 2);

            return ['valor' => $valor, 'valor_reais' => $reais, 'multiplicador' => $mult,
                'memoria' => $memoria . ' = ' . $n($valor) . ' UPF'] + $r;
        }

        // ── Por área: primeiro QUAL área, depois a conta ──
        [$usada, $area, $prefixo] = $this->areaDaMulta($areaTerreno, $areaConstruida);
        $nomes = ['construida' => 'área construída', 'terreno' => 'área do terreno'];

        // Área por medir: o cálculo não pode fingir um valor, senão a multa
        // sai errada silenciosamente. Melhor recusar e pedir a área.
        if ($area === null) {
            $qual = $this->multa_area === 'construida_ou_terreno' ? 'a área construída ou a do terreno' : 'a ' . $nomes[$usada];

            return ['area_usada' => $usada, 'memoria' => 'Área não informada — multa não calculada.',
                'pendencia' => $qual] + $r;
        }
        $r = ['area_usada' => $usada, 'area_m2' => $area] + $r;
        $deQue = $usada === 'construida' ? 'obra' : 'terreno';

        if ($this->base_multa === 'faixas') {
            $anterior = null;
            foreach ($this->multa_faixas ?? [] as $faixa) {
                $ate = $faixa['ate_m2'] ?? null;
                if ($ate === null || $area <= (float) $ate) {
                    $rotulo = $ate === null ? 'acima de ' . $n((float) $anterior) . ' m²'
                        : ($anterior === null ? 'até ' . $n((float) $ate) . ' m²'
                            : 'acima de ' . $n((float) $anterior) . ' até ' . $n((float) $ate) . ' m²');
                    $valor = (float) ($faixa['upf'] ?? 0);

                    return ['valor' => round($valor, 2), 'memoria' => $prefixo . 'Faixa ' . $rotulo . ' ('
                        . $deQue . ' de ' . $n($area) . ' m²) = ' . $n($valor) . ' UPF'] + $r;
                }
                $anterior = $ate;
            }

            return ['memoria' => 'Artigo sem faixa para ' . $n($area) . ' m².',
                'pendencia' => 'as faixas de multa do ' . $this->numero . ' (Parâmetros › Legislação)'] + $r;
        }

        $bruto = (float) ($this->multa_upf_m2 ?? 0) * $area;
        $valor = $bruto;
        $memoria = $prefixo . $n((float) $this->multa_upf_m2, 4) . ' UPF/m² × ' . $n($area) . ' m² = ' . $n($bruto) . ' UPF';

        if ($this->multa_min_upf !== null && $valor < (float) $this->multa_min_upf) {
            $valor = (float) $this->multa_min_upf;
            $memoria .= ' → piso de ' . $n($valor) . ' UPF aplicado';
        } elseif ($this->multa_max_upf !== null && $valor > (float) $this->multa_max_upf) {
            $valor = (float) $this->multa_max_upf;
            $memoria .= ' → teto de ' . $n($valor) . ' UPF aplicado';
        }

        return ['valor' => round($valor, 2), 'memoria' => $memoria] + $r;
    }

    /**
     * Qual área este artigo usa, com o que a peça tem.
     *
     * No "obra, senão terreno" a decisão é automática: área construída maior
     * que zero é obra. E a memória DIZ que caiu no terreno, e por quê.
     *
     * @return array{0: string, 1: ?float, 2: string} [qual, área ou null, prefixo da memória]
     */
    private function areaDaMulta(?float $areaTerreno, ?float $areaConstruida): array
    {
        $tem = fn (?float $a) => $a !== null && $a > 0 ? $a : null;

        if ($this->multa_area === 'terreno') {
            return ['terreno', $tem($areaTerreno), ''];
        }
        if ($this->multa_area === 'construida_ou_terreno' && $tem($areaConstruida) === null) {
            return ['terreno', $tem($areaTerreno), 'Sem área construída informada — calculado sobre o terreno: '];
        }

        return ['construida', $tem($areaConstruida), ''];
    }

    /** "50,00 UPF", "0,20 UPF/m² · obra ou terreno", "7 faixas · terreno", "1 a 10× o alvará". */
    public function rotuloMulta(): string
    {
        $n = fn (?float $v, int $casas = 2) => rtrim(rtrim(number_format((float) $v, $casas, ',', '.'), '0'), ',');
        $area = ['construida' => 'obra', 'terreno' => 'terreno', 'construida_ou_terreno' => 'obra ou terreno'][$this->multa_area] ?? 'obra';

        return match ($this->base_multa) {
            'sem_multa'       => 'sem multa',
            'fixa'            => $n($this->multa_upf) . ' UPF',
            'por_m2'          => $n($this->multa_upf_m2, 4) . ' UPF/m² · ' . $area,
            'faixas'          => count($this->multa_faixas ?? []) . ' faixa(s) · ' . $area,
            'multiplo_alvara' => (abs((float) $this->multa_mult_max - (float) $this->multa_mult_min) < 0.005
                ? $n($this->multa_mult_min) : $n($this->multa_mult_min) . ' a ' . $n($this->multa_mult_max)) . '× o alvará',
            default           => (string) $this->base_multa,
        };
    }

    public function legislacao(): BelongsTo
    {
        return $this->belongsTo(Legislacao::class);
    }

    /**
     * O artigo que trata do que o fiscal viu, pelo que ele digita.
     *
     * Os TERMOS DE BUSCA são o vocabulário de campo do artigo ("escavação",
     * "terraplenagem", "movimento de terra"): o fiscal procura o problema, e
     * não o número do dispositivo. Casa também com número, apelido e conduta.
     * Sem acento e sem caixa: em campo ninguém digita "escavação" com cedilha.
     *
     * Devolve o que casou (para a lista mostrar POR QUE o artigo apareceu),
     * ou null. A ordem diz a força: termo, apelido, número, conduta.
     */
    public function casaCom(string $busca): ?string
    {
        // "art. 27", "artigo 27" → "27": o fiscal escreve como está na lei.
        $q = preg_replace('/^art(?:igo)?\.?\s*(?=\d)/', '', self::normalizar($busca));
        if ($q === '') {
            return null;
        }
        foreach ($this->termos ?? [] as $t) {
            if (str_contains(self::normalizar($t), $q)) {
                return $t;
            }
        }
        foreach (['apelido', 'numero', 'conduta'] as $campo) {
            if ($this->{$campo} !== null && str_contains(self::normalizar((string) $this->{$campo}), $q)) {
                return $campo === 'conduta' ? 'conduta' : (string) $this->{$campo};
            }
        }

        return null;
    }

    /** Texto comparável: sem acento, minúsculo, espaços simples. */
    public static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($texto))));
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
