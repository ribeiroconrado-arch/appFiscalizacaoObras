<?php

namespace App\Services;

use App\Models\Artigo;
use App\Cadastro\FonteDoCadastro;
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
    /**
     * O PRÓXIMO NÚMERO DE UMA SÉRIE, no exercício corrente.
     *
     * Estava dentro de `lavrar()`, servindo só aos documentos. A vistoria
     * passou a ser numerada também, e um segundo mecanismo de contagem é o tipo
     * de coisa que diverge no dia em que alguém corrigir um só — então o
     * contador saiu para cá e os dois chamam o mesmo.
     *
     * A linha é travada com `lockForUpdate()`: dois fiscais gravando no mesmo
     * segundo não podem receber o mesmo número. Quem chama é responsável por
     * estar dentro de uma transação — fora dela o lock não vale nada.
     *
     * @param  string $tipo a série (um tipo de `Documento::TIPOS`)
     * @return array{numero:int, exercicio:int}
     */
    public static function proximoNumero(string $tipo): array
    {
        $exercicio = (int) now()->format('Y');

        $contador = DB::table('documento_contadores')
            ->where('tipo', $tipo)
            ->where('exercicio', $exercicio)
            ->lockForUpdate()
            ->first();

        if (! $contador) {
            DB::table('documento_contadores')->insert([
                'tipo' => $tipo, 'exercicio' => $exercicio, 'ultimo' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $contador = DB::table('documento_contadores')
                ->where('tipo', $tipo)->where('exercicio', $exercicio)
                ->lockForUpdate()->first();
        }

        $numero = $contador->ultimo + 1;
        DB::table('documento_contadores')
            ->where('id', $contador->id)
            ->update(['ultimo' => $numero, 'updated_at' => now()]);

        return ['numero' => $numero, 'exercicio' => $exercicio];
    }

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

        // A última barreira: a peça só vira ato com artigo que serve a ela. O
        // rascunho pode ter sido gravado antes de o artigo ser reconfigurado.
        $this->conferirArtigosDoTipo($doc->tipo, $doc->artigos()->pluck('artigo_id')->filter()->all());

        // AUTO DE INFRAÇÃO NÃO LAVRA COM MULTA POR CALCULAR. Antes, artigo por
        // área sem a área saía com multa zero e a nota "não calculada" — um
        // auto lavrado sem o valor que ele existe para impor.
        if ($doc->tipo === 'auto_infracao' && ($faltam = $this->pendenciasDeMulta($doc))) {
            throw new RuntimeException('A multa não fecha: falta informar ' . implode('; ', $faltam) . '.');
        }

        return DB::transaction(function () use ($doc) {
            // A multa é refeita AGORA, com a UPF e as áreas do dia da lavratura:
            // o rascunho pode ter ficado dias aberto.
            if ($doc->tipo === 'auto_infracao') {
                $copias = $doc->artigos()->get();
                if (Artigo::whereIn('id', $copias->pluck('artigo_id')->filter())->count() === $copias->count()) {
                    $this->fixarArtigos($doc, $copias->pluck('artigo_id')->all(),
                        $copias->pluck('multiplicador', 'artigo_id')->filter(fn ($m) => $m !== null)->all());
                }
            }

            ['numero' => $numero, 'exercicio' => $exercicio] = self::proximoNumero($doc->tipo);

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

            // CARIMBO DE PROCEDÊNCIA — de quando é o dado cadastral que esta
            // peça usou, e de onde ele veio.
            //
            // Aqui e não na criação do rascunho: um rascunho fica dias em
            // aberto, e uma integração no meio do caminho mudaria o dado sob os
            // pés dele. Na lavratura o conteúdo congela — mesmo momento do
            // prazo de defesa e da rubrica, pela mesma razão.
            //
            // CÓPIA, e não referência: o cadastro municipal muda a cada carga
            // mensal, então apontar para ele faria o documento citar o dado de
            // hoje em vez do que ele usou. Guarda a carga (dá para refazer o
            // caminho pelo histórico) e o retrato do terreno naquele momento.
            // Nulo quando o imóvel não está no cadastro — e nulo é informação.
            $fonte = app(FonteDoCadastro::class);
            $lote = $doc->lote;
            $situacao = $lote ? $fonte->situacao($lote) : null;
            $retrato = $lote ? $fonte->consultar($lote) : null;
            $doc->cadastro_consultado_em = $situacao['em'] ?? null;
            $doc->cadastro_fonte         = ($situacao['em'] ?? null) ? $fonte->nome() : null;
            $doc->cadastro_carga_id      = $situacao['carga_id'] ?? null;
            // O retrato INTEIRO do que o formulário mostrou: o terreno, as
            // características e as unidades. Antes ia só o terreno, e a peça
            // reaberta exibia o resto pelo cadastro do dia — outro dado, sob o
            // mesmo título. Peça antiga tem o formato curto (só o terreno, sem
            // a chave `imovel`); quem lê trata os dois (DocumentoController::ficha).
            // O proprietário NÃO entra: é dado pessoal, e a peça já guarda o autuado.
            // A data em que a cópia foi tirada é a da própria lavratura.
            $doc->cadastro_retrato = $retrato ? [
                'imovel'          => $retrato->imovel,
                'caracteristicas' => $retrato->caracteristicas,
                'unidades'        => $retrato->unidades,
            ] : null;

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
    public function fixarArtigos(Documento $doc, array $artigoIds, array $multiplicadores = []): void
    {
        $doc->artigos()->delete();

        $artigos = Artigo::whereIn('id', $artigoIds)->get();
        $upf = Upf::vigente($doc->data_fato ?? now())?->valor;
        $total = 0.0;

        foreach ($artigos as $a) {
            $mult = $multiplicadores[$a->id] ?? null;
            $calc = $a->calcularMulta($doc->area_terreno_m2, $doc->area_construida_m2,
                $doc->alvara_valor, $mult !== null ? (float) $mult : null, $upf ? (float) $upf : null);

            DocumentoArtigo::create([
                'documento_id' => $doc->id,
                'artigo_id'    => $a->id,
                'numero'       => $a->numero,
                'conduta'      => $a->conduta,
                'sancao'       => $a->sancao,
                'base_multa'   => $a->base_multa,
                'multa_area'   => $a->multa_area,
                'multa_upf'    => $a->multa_upf,
                'multa_upf_m2' => $a->multa_upf_m2,
                'multa_faixas' => $a->base_multa === 'faixas' ? $a->multa_faixas : null,
                'area_m2'      => $calc['area_m2'],
                'area_usada'   => $calc['area_usada'],
                // O informado fica guardado mesmo fora do intervalo: é o que
                // a tela mostra de volta para o fiscal corrigir.
                'multiplicador' => $calc['multiplicador'] ?? ($mult !== null ? (float) $mult : null),
                'valor_upf'    => $calc['valor'],
                'valor_reais'  => $calc['valor_reais'],
                'memoria'      => $calc['memoria'],
            ]);
            $total += $calc['valor'];
        }

        // Só auto de infração acumula multa; notificação e termo não penalizam.
        // Sem multa a somar, o total volta a nulo: o artigo que multava pode
        // ter saído da peça desde a última gravação.
        if ($doc->tipo === 'auto_infracao') {
            $doc->valor_upf = $total > 0 ? round($total, 2) : null;
            $doc->upf_valor = $total > 0 ? $upf : null;
            $doc->save();
        }
    }

    /**
     * O que FALTA para a multa de cada artigo da peça fechar: área, valor do
     * alvará, multiplicador, UPF. Vazio = tudo calculado.
     *
     * @return list<string>
     */
    public function pendenciasDeMulta(Documento $doc): array
    {
        $upf = Upf::vigente($doc->data_fato ?? now())?->valor;
        $copias = $doc->artigos()->get()->keyBy('artigo_id');

        return Artigo::whereIn('id', $copias->keys()->filter())->get()
            ->map(fn (Artigo $a) => $a->calcularMulta($doc->area_terreno_m2, $doc->area_construida_m2,
                $doc->alvara_valor, $copias[$a->id]->multiplicador, $upf ? (float) $upf : null)['pendencia'])
            ->filter()->unique()->values()->all();
    }

    /**
     * Artigos sugeridos para a peça de uma vistoria: os que o fiscal CITOU nela
     * (`vistoria_artigos`, tipo citação), na ordem em que aparecem. Parecer
     * fica de fora: é a opinião do fiscal sobre o artigo, não o enquadramento.
     *
     * Antes a sugestão vinha do catálogo de irregularidades e ignorava o que o
     * fiscal tinha de fato citado em campo.
     *
     * @return \Illuminate\Support\Collection<int, Artigo>
     */
    /**
     * Recusa artigo que não serve ao tipo da peça (Artigo::serveA).
     *
     * @param  array<int,int>  $artigoIds
     * @throws RuntimeException dizendo quais artigos, e por quê
     */
    public function conferirArtigosDoTipo(string $tipo, array $artigoIds): void
    {
        if (! $artigoIds) {
            return;
        }
        $fora = Artigo::whereIn('id', $artigoIds)->get()
            ->reject(fn (Artigo $a) => $a->serveA($tipo))
            ->map(fn (Artigo $a) => $a->numero)->values()->all();
        if (! $fora) {
            return;
        }

        $lista = implode(', ', $fora);
        throw new RuntimeException(in_array($tipo, Documento::DE_EMBARGO, true)
            ? "Não cabe embargo por {$lista}. Peça de embargo só aceita artigo configurado para embargo (Parâmetros › Legislação)."
            : "{$lista} é exclusivo de embargo: só entra em Notificação de Embargo ou Auto de Embargo.");
    }

    /**
     * O que o fiscal deve saber antes de lavrar um AUTO DE EMBARGO.
     *
     * Artigo que só embarga APÓS PRAZO (LC 001/2023, art. 22, §5º e art. 32,
     * §2º) pede que o prazo tenha sido dado e vencido — o que se prova com
     * uma Notificação de Embargo lavrada para o imóvel. É AVISO, e não trava:
     * o prazo pode ter corrido por outro meio (notificação em papel, peça de
     * antes do sistema), e quem responde pelo ato é o fiscal.
     *
     * @return array<int,string>
     */
    public function avisosDeEmbargo(Documento $doc): array
    {
        if ($doc->tipo !== 'auto_embargo') {
            return [];
        }
        $comPrazo = Artigo::whereIn('id', $doc->artigos()->pluck('artigo_id')->filter())
            ->where('embargo_modo', 'apos_prazo')->get();
        if ($comPrazo->isEmpty()) {
            return [];
        }

        $vencida = $doc->lote_id && Documento::where('lote_id', $doc->lote_id)
            ->where('tipo', 'notificacao_embargo')
            ->whereNotIn('status', ['rascunho', 'anulado'])
            ->whereNotNull('prazo_ate')->where('prazo_ate', '<', now()->toDateString())
            ->exists();
        if ($vencida) {
            return [];
        }

        $artigos = $comPrazo->map(fn (Artigo $a) => $a->numero . ' (' . (int) $a->embargo_prazo_dias . ' dias)')->implode(', ');

        return ["{$artigos}: o embargo só cabe depois do prazo, e este imóvel não tem Notificação de Embargo "
            . 'com prazo vencido no sistema. Confira se o prazo foi dado e correu antes de lavrar.'];
    }

    /**
     * @param  string|null  $tipo  com ele, só os artigos que servem àquela peça
     */
    public function artigosSugeridos(int $vistoriaId, ?string $tipo = null)
    {
        $ids = DB::table('vistoria_artigos')
            ->where('vistoria_id', $vistoriaId)->where('tipo', 'citacao')
            ->orderBy('item_id')->orderBy('ordem')->orderBy('id')
            ->pluck('artigo_id')->unique()->values();

        $artigos = Artigo::query()->with('legislacao:id,numero,nome')->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $artigos->get($id))->filter()
            ->filter(fn (Artigo $a) => $tipo === null || $a->serveA($tipo))->values();
    }
}
