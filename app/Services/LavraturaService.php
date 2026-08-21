<?php

namespace App\Services;

use App\Models\Artigo;
use App\Models\Documento;
use App\Models\DocumentoArtigo;
use App\Models\Feriado;
use App\Models\Upf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Regras de lavratura: numeração, prazos e congelamento da fundamentação.
 *
 * Ficam num serviço, e não no controller, porque são as regras que decidem se
 * um documento é válido no processo administrativo — e vão ser chamadas de
 * mais de um lugar (tela, geração em lote, importação futura).
 */
class LavraturaService
{
    /**
     * Atribui número ao documento e o marca como lavrado.
     *
     * O número só nasce AQUI, nunca na criação: numerar rascunho queima
     * sequência e deixa buraco na série. Numa série de autos de infração, um
     * número faltando é questionamento certo em defesa administrativa.
     *
     * A linha do contador é travada com `lockForUpdate()` dentro da transação:
     * dois fiscais lavrando no mesmo segundo não podem receber o mesmo número.
     */
    public function lavrar(Documento $doc): Documento
    {
        if ($doc->status !== 'rascunho') {
            throw new RuntimeException('Só rascunho pode ser lavrado.');
        }

        // O imóvel é dispensado na CRIAÇÃO — o fiscal começa a peça com o que
        // tem em mãos — mas não aqui. Na lavratura o documento vira ato, e ato
        // de fiscalização de obras sem imóvel identificado não tem contra o
        // que valer: não há o que notificar, embargar ou cobrar.
        if (! $doc->lote_id) {
            throw new RuntimeException(
                'Documento sem imóvel identificado não pode ser lavrado. '
                . 'Informe o imóvel na aba Imóvel/Origem.'
            );
        }

        if ($doc->exigeFundamentacao() && $doc->artigos()->count() === 0) {
            throw new RuntimeException(
                'Documento sem fundamentação legal não pode ser lavrado. '
                . 'Vincule ao menos um artigo.'
            );
        }

        return DB::transaction(function () use ($doc) {
            $exercicio = (int) now()->format('Y');

            $contador = DB::table('documento_contadores')
                ->where('tipo', $doc->tipo)
                ->where('exercicio', $exercicio)
                ->lockForUpdate()
                ->first();

            if (! $contador) {
                DB::table('documento_contadores')->insert([
                    'tipo' => $doc->tipo, 'exercicio' => $exercicio, 'ultimo' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $contador = DB::table('documento_contadores')
                    ->where('tipo', $doc->tipo)->where('exercicio', $exercicio)
                    ->lockForUpdate()->first();
            }

            $numero = $contador->ultimo + 1;
            DB::table('documento_contadores')
                ->where('id', $contador->id)
                ->update(['ultimo' => $numero, 'updated_at' => now()]);

            $doc->numero         = $numero;
            $doc->exercicio      = $exercicio;
            $doc->status         = 'lavrado';
            $doc->data_lavratura = now();

            // Rubrica do agente, copiada do perfil dele para dentro do
            // documento. É cópia e não referência: se ele redesenhar a
            // assinatura depois, os documentos já lavrados continuam
            // exibindo a que valia no dia — como qualquer papel assinado.
            if (! $doc->assinatura_agente) {
                $doc->assinatura_agente = $doc->agente()->first()?->assinatura;
            }

            // Prazos são calculados e CONGELADOS na lavratura. Recalcular ao
            // reabrir renovaria o prazo de um documento antigo — o autuado
            // ganharia tempo toda vez que alguém abrisse a tela.
            $this->calcularPrazos($doc);

            $doc->save();

            return $doc;
        });
    }

    /**
     * Define `prazo_ate` (cumprimento, dias corridos) ou `defesa_ate`
     * (defesa, dias úteis) conforme o tipo.
     */
    public function calcularPrazos(Documento $doc): void
    {
        $base = CarbonImmutable::parse($doc->data_lavratura ?? now());

        if (in_array($doc->tipo, Documento::COM_DEFESA, true)) {
            // Prazo de defesa vem da LEI e conta em dias ÚTEIS.
            $dias = $doc->legislacao?->prazo_defesa_dias ?? 5;
            $doc->defesa_ate = $this->somarDiasUteis($base, $dias);
            $doc->prazo_ate  = null;
            $doc->prazo_dias = null;
            return;
        }

        if (in_array($doc->tipo, Documento::COM_CUMPRIMENTO, true)) {
            // Prazo de cumprimento é por documento e conta em dias corridos.
            $dias = $doc->prazo_dias ?? $doc->legislacao?->prazo_cumprimento_dias ?? 10;
            $doc->prazo_dias = $dias;
            $doc->prazo_ate  = $dias === 0 ? $base->toDateString() : $base->addDays($dias)->toDateString();
            $doc->defesa_ate = null;
            return;
        }

        // Vistoria documental não tem prazo nenhum.
        $doc->prazo_ate = $doc->defesa_ate = $doc->prazo_dias = null;
    }

    /**
     * Soma dias ÚTEIS, pulando sábado, domingo e feriado.
     *
     * Prazo de defesa em dias corridos é erro clássico e caro: encurta o prazo
     * real do autuado e vicia o processo.
     */
    public function somarDiasUteis(CarbonImmutable $inicio, int $dias): string
    {
        // Intervalo generoso (+1 ano) para cobrir o caso de o prazo atravessar
        // a virada do exercício — um feriado de janeiro do ano seguinte
        // precisa estar na lista mesmo que a lavratura seja em dezembro.
        $feriados = array_flip(Feriado::datasEntre((int) $inicio->format('Y'), (int) $inicio->format('Y') + 1));
        $d = $inicio;
        $restantes = max($dias, 0);

        while ($restantes > 0) {
            $d = $d->addDay();
            if ($d->isWeekend() || isset($feriados[$d->toDateString()])) {
                continue;
            }
            $restantes--;
        }

        return $d->toDateString();
    }

    /**
     * Copia os artigos para dentro do documento, com o texto vigente hoje.
     *
     * A multa é CALCULADA e CONGELADA aqui — não só o valor fixo do artigo.
     * A maioria das infrações do Código de Obras é proporcional à área
     * (construída ou do terreno), então o mesmo artigo pode gerar valores
     * bem diferentes conforme o imóvel; recalcular puxaria a área errada se
     * o cadastro do lote mudasse depois da lavratura.
     *
     * @param  list<int>  $artigoIds
     */
    public function fixarArtigos(Documento $doc, array $artigoIds): void
    {
        $doc->artigos()->delete();

        $artigos = Artigo::whereIn('id', $artigoIds)->get();
        $total = 0.0;

        foreach ($artigos as $a) {
            $calc = $a->calcularMulta($doc->area_terreno_m2, $doc->area_construida_m2);

            DocumentoArtigo::create([
                'documento_id' => $doc->id,
                'artigo_id'    => $a->id,
                'numero'       => $a->numero,
                'conduta'      => $a->conduta,
                'sancao'       => $a->sancao,
                'base_multa'   => $a->base_multa,
                'multa_upf'    => $a->multa_upf,
                'multa_upf_m2' => $a->multa_upf_m2,
                'area_m2'      => $a->base_multa === 'area_terreno' ? $doc->area_terreno_m2
                                 : ($a->base_multa === 'area_construida' ? $doc->area_construida_m2 : null),
                'valor_upf'    => $calc['valor'],
            ]);
            $total += $calc['valor'];
        }

        // Só auto de infração acumula multa; notificação e termo não penalizam.
        if ($doc->tipo === 'auto_infracao' && $total > 0) {
            $doc->valor_upf = round($total, 2);
            $doc->upf_valor = Upf::vigente($doc->data_fato ?? now())?->valor;
            $doc->save();
        }
    }

    /**
     * Artigos sugeridos para uma vistoria, a partir das irregularidades
     * constatadas. É o coração do motor de legislação do §18.
     *
     * @return \Illuminate\Support\Collection<int, Artigo>
     */
    public function artigosSugeridos(int $vistoriaId)
    {
        return Artigo::query()
            ->ativos()
            ->with('legislacao:id,numero,nome')
            ->whereHas('irregularidades', function ($q) use ($vistoriaId) {
                $q->whereIn('irregularidades.id', function ($sub) use ($vistoriaId) {
                    $sub->select('irregularidade_id')
                        ->from('vistoria_irregularidades')
                        ->where('vistoria_id', $vistoriaId);
                });
            })
            ->get();
    }
}
