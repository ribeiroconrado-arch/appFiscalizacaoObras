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
     * GRAVAR: o rascunho ganha número e passa a "gravado".
     *
     * O CICLO DA PEÇA (decisão da fiscalização, 10/10/2026 — o mesmo do
     * AppPOSTURAS):
     *
     *   rascunho  sem número   edita e EXCLUI
     *   gravado   com número   edita e CANCELA (com motivo)
     *   lavrado   com número   não edita; CANCELA com motivo e a senha de quem cancela
     *   cancelado / defendido  encerrados; o número continua na série
     *
     * O número nasce AQUI, e não no rascunho: rascunho se apaga, e número
     * apagado é buraco na série — questionamento certo em defesa. A partir do
     * número a peça não se exclui mais: cancela-se, e o cancelado fica na
     * série com quem, quando e por quê.
     *
     * A linha do contador é travada com `lockForUpdate()` dentro da transação:
     * dois fiscais gravando no mesmo segundo não podem receber o mesmo número.
     */
    public function gravar(Documento $doc): Documento
    {
        if ($doc->status !== 'rascunho') {
            throw new RuntimeException('Só rascunho pode ser gravado.');
        }

        return DB::transaction(function () use ($doc) {
            if (! $doc->numero) {
                ['numero' => $numero, 'exercicio' => $exercicio] = self::proximoNumero($doc->tipo);
                $doc->numero    = $numero;
                $doc->exercicio = $exercicio;
            }
            $doc->status = 'gravado';
            $doc->save();

            return $doc;
        });
    }

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

    /**
     * @param  array{assinatura_autuado?: ?string, recusa?: bool, testemunha_nome?: ?string, assinatura_testemunha?: ?string}  $ato
     *         o que foi colhido NA HORA da lavratura: a assinatura do autuado,
     *         ou, se ele se recusou, a testemunha (nome e assinatura). Quem
     *         exige que venha é a tela de lavratura (DocumentoController::
     *         lavrar); aqui só se guarda.
     */
    public function lavrar(Documento $doc, array $ato = []): Documento
    {
        // Lavra-se a peça GRAVADA. O rascunho também passa: é gravado e
        // lavrado de uma vez, e recebe o número logo abaixo.
        if (! $doc->naoLavrado()) {
            throw new RuntimeException('Este documento já foi lavrado ou encerrado.');
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

        return DB::transaction(function () use ($doc, $ato) {
            // AS ASSINATURAS DO ATO. Com recusa, a do autuado não existe: quem
            // assina é a testemunha, e o registro da recusa vai para o Termo
            // de Recusa da peça impressa.
            if ($ato) {
                $recusa = (bool) ($ato['recusa'] ?? false);
                $doc->assinatura_autuado    = $recusa ? null : ($ato['assinatura_autuado'] ?? null);
                $doc->testemunha_nome       = $recusa ? ($ato['testemunha_nome'] ?? null) : null;
                $doc->assinatura_testemunha = $recusa ? ($ato['assinatura_testemunha'] ?? null) : null;
                $doc->recusa_assinatura     = $recusa
                    ? mb_substr('Recusou-se a assinar. Testemunha: ' . ($ato['testemunha_nome'] ?? '—') . '.', 0, 160)
                    : null;
            }

            // A multa é refeita AGORA, com a UPF e as áreas do dia da lavratura:
            // o rascunho pode ter ficado dias aberto.
            if ($doc->tipo === 'auto_infracao') {
                $copias = $doc->artigos()->get();
                if (Artigo::whereIn('id', $copias->pluck('artigo_id')->filter())->count() === $copias->count()) {
                    $this->fixarArtigos($doc, $copias->pluck('artigo_id')->all(),
                        $copias->pluck('multiplicador', 'artigo_id')->filter(fn ($m) => $m !== null)->all());
                }
            }

            // O número vem do Gravar (ver `gravar`). Só o rascunho lavrado
            // direto chega aqui sem ele.
            if (! $doc->numero) {
                ['numero' => $numero, 'exercicio' => $exercicio] = self::proximoNumero($doc->tipo);
                $doc->numero    = $numero;
                $doc->exercicio = $exercicio;
            }
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
                $doc->alvara_valor, $mult !== null ? (float) $mult : null, $upf ? (float) $upf : null,
                $doc->fatorReincidencia());

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
                'fator_reincidencia' => $calc['fator'] > 1 ? $calc['fator'] : null,
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

        throw new RuntimeException(implode(', ', $fora) . ' não se aplica a ' . (Documento::TIPOS[$tipo][0] ?? $tipo)
            . '. As peças de cada artigo são marcadas em Parâmetros › Legislação › Artigos.');
    }

    /**
     * O que o fiscal deve saber antes de lavrar um AUTO DE EMBARGO.
     *
     * A prática é notificar antes: a Notificação de Embargo dá o prazo, e o
     * auto vem depois de ele vencer. É AVISO, e não trava — o art. 121-A
     * manda embargar de imediato, e o prazo pode ter corrido por outro meio
     * (notificação em papel, peça de antes do sistema). Quem responde pelo
     * ato é o fiscal.
     *
     * @return array<int,string>
     */
    public function avisosDeEmbargo(Documento $doc): array
    {
        if ($doc->tipo !== 'auto_embargo' || ! $doc->lote_id) {
            return [];
        }

        $vencida = Documento::where('lote_id', $doc->lote_id)
            ->where('tipo', 'notificacao_embargo')
            ->whereNotIn('status', Documento::SEM_VALOR_DE_ATO)
            ->whereNotNull('prazo_ate')->where('prazo_ate', '<', now()->toDateString())
            ->exists();

        return $vencida ? [] : ['Este imóvel não tem Notificação de Embargo com prazo vencido no sistema. '
            . 'Confira se o prazo foi dado e correu antes de lavrar o Auto de Embargo.'];
    }

    /**
     * REINCIDÊNCIA: amarra o auto ao auto anterior e conta o elo da cadeia.
     *
     * Só Auto de Infração é reincidência, e só de outro Auto de Infração
     * LAVRADO e não anulado — reincidir num rascunho ou num ato desfeito não
     * existe. O nível é o do anterior mais um; é ele que dobra a multa
     * (Documento::fatorReincidencia).
     */
    public function vincularReincidencia(Documento $doc, ?int $anteriorId): void
    {
        if (! $anteriorId) {
            $doc->reincidencia_de_id = null;
            $doc->reincidencia_nivel = 0;

            return;
        }
        if ($doc->tipo !== 'auto_infracao') {
            throw new RuntimeException('Só Auto de Infração pode ser lavrado como reincidência.');
        }
        $anterior = Documento::find($anteriorId);
        if (! $anterior || $anterior->id === $doc->id || $anterior->tipo !== 'auto_infracao'
            || in_array($anterior->status, Documento::SEM_VALOR_DE_ATO, true)) {
            throw new RuntimeException('A reincidência tem de apontar para um Auto de Infração lavrado e não anulado.');
        }
        $doc->reincidencia_de_id = $anterior->id;
        $doc->reincidencia_nivel = min((int) $anterior->reincidencia_nivel + 1, 6);
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
