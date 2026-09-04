<?php

namespace App\Console\Commands;

use App\Support\GeometriaPlana;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Confere a base de lotes e recusa importação que tenha entrado torta.
 *
 * Existe porque a primeira importação passou com dois defeitos que ninguém viu
 * na hora, e só apareceram meses depois, no uso:
 *
 *   1. LOTES SUPRIMIDOS — o extrator descartava polígono acima de 5.000 m²
 *      ("gleba inteira"), e com isso comeu a Quadra 05 inteira do Jardim
 *      Europa IV, que é um lote único de 12.008 m². A Quadra 35 sumiu pelo
 *      mesmo motivo. Não houve erro, nem aviso: o dado simplesmente não veio.
 *
 *   2. QUADRA TROCADA — a quadra era herdada do RÓTULO MAIS PRÓXIMO do
 *      centroide do lote. Num quarteirão grande o rótulo fica longe, e o lote
 *      do outro lado da rua acaba mais perto dele do que do rótulo certo.
 *
 * Nenhum dos dois quebra a importação: ela termina "com sucesso". Por isso a
 * conferência precisa ser um passo próprio, que olhe o RESULTADO e grite.
 *
 * Cada verificação abaixo detecta um defeito que já aconteceu de verdade.
 */
class ConferirBaseGis extends Command
{
    protected $signature = 'gis:conferir
                            {--bairro= : limita a um bairro}
                            {--estrito : devolve erro (exit 1) se houver qualquer achado}';

    protected $description = 'Confere a base de lotes: buracos na numeração, duplicidade e áreas suspeitas';

    /** Acima disto o polígono provavelmente é gleba, não lote. Só avisa. */
    private const AREA_SUSPEITA = 20000.0;

    /** Abaixo disto provavelmente é resto de hachura ou símbolo. */
    private const AREA_MINIMA = 60.0;

    /**
     * Sobreposição tolerada entre dois lotes vizinhos, em m².
     *
     * Não é folga de conveniência: é o resíduo de digitalização. Dois lotes
     * desenhados por pessoas diferentes, ou o mesmo lote redesenhado, deixam
     * frestas e cavalgamentos de fração de metro na divisa comum — medi 0,85 m²
     * entre dois lotes reais do Jardim Europa. Meio metro quadrado é menos do
     * que qualquer imprecisão cadastral relevante e mais do que o ruído de
     * ponto flutuante em coordenada geográfica.
     */
    private const SOBREPOSICAO_TOLERADA_M2 = 0.5;

    public function handle(): int
    {
        $bairro = $this->option('bairro');
        $achados = 0;

        $bairros = DB::table('lotes')
            ->when($bairro, fn ($q) => $q->where('bairro', $bairro))
            ->distinct()->orderBy('bairro')->pluck('bairro');

        if ($bairros->isEmpty()) {
            $this->error('Nenhum lote encontrado.');
            return self::FAILURE;
        }

        foreach ($bairros as $b) {
            $this->newLine();
            $this->info("── {$b} ──");
            $achados += $this->buracosDeQuadra($b);
            $achados += $this->quadraDeLoteUnico($b);
            $achados += $this->buracosDeLote($b);
            $achados += $this->duplicidade($b);
            $achados += $this->areasSuspeitas($b);
            $achados += $this->semNumero($b);
            $achados += $this->identidadeRessuscitada($b);
            $achados += $this->sobreposicao($b);
        }

        // Conferências da SUCESSÃO. Ficam fora do laço porque um ato liga
        // lotes que podem estar em bairros diferentes, e contá-lo uma vez por
        // bairro daria o mesmo achado repetido.
        $achados += $this->sucessaoQuebrada();

        $this->newLine();
        if (! $achados) {
            $this->info('Nenhum achado. Base consistente.');
            return self::SUCCESS;
        }

        $this->warn("Total de achados: {$achados}");
        $this->line('Achado não é necessariamente erro — mas cada um precisa de uma explicação.');

        return $this->option('estrito') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Buraco na sequência de quadras.
     *
     * Quadra é numerada em sequência dentro do loteamento. Um número faltando
     * quase sempre significa que ela não foi importada — foi assim que a
     * Quadra 35 do Jardim Europa IV sumiu sem ninguém notar.
     */
    private function buracosDeQuadra(string $b): int
    {
        $nums = DB::table('lotes')->where('bairro', $b)
            ->whereNotNull('quadra')->distinct()->pluck('quadra')
            ->map(fn ($q) => (int) $q)->filter()->sort()->values();

        if ($nums->count() < 2) { return 0; }

        $faltando = collect(range($nums->first(), $nums->last()))->diff($nums);
        if ($faltando->isEmpty()) { return 0; }

        $this->error(sprintf(
            '  QUADRAS FALTANDO (%d): %s   — vão de %02d a %02d',
            $faltando->count(),
            $faltando->map(fn ($n) => sprintf('%02d', $n))->implode(', '),
            $nums->first(), $nums->last()
        ));
        $this->line('    Provável supressão na extração. Confira essas quadras no DWG.');

        return $faltando->count();
    }

    /**
     * Quadra com um lote só.
     *
     * Não é erro — existe quadra de lote único (gleba remanescente, área
     * institucional). Mas é o formato exato que o filtro de área derrubava,
     * então merece conferência: se o DWG mostra uma quadra loteada e aqui ela
     * tem um lote só, faltou gente.
     */
    private function quadraDeLoteUnico(string $b): int
    {
        $q = DB::table('lotes')->where('bairro', $b)
            ->select('quadra', DB::raw('COUNT(*) n'), DB::raw('ROUND(MAX(area_gis_m2)) area'))
            ->groupBy('quadra')->having('n', '=', 1)->orderBy('quadra')->get();

        if ($q->isEmpty()) { return 0; }

        $this->warn('  QUADRAS COM UM ÚNICO LOTE (' . $q->count() . '):');
        foreach ($q as $r) {
            $this->line(sprintf('    Q%-4s lote único, %s m²', $r->quadra, number_format((float) $r->area, 0, ',', '.')));
        }
        $this->line('    Confira no DWG: quadra loteada que aparece com um lote só perdeu os demais.');

        return $q->count();
    }

    /** Buraco na sequência de lotes dentro da quadra. */
    private function buracosDeLote(string $b): int
    {
        $achados = 0;
        $porQuadra = DB::table('lotes')->where('bairro', $b)
            ->whereNotNull('quadra')->whereNotNull('numero_lote')
            ->select('quadra', 'numero_lote')->get()->groupBy('quadra');

        foreach ($porQuadra as $quadra => $lotes) {
            $nums = $lotes->pluck('numero_lote')->map(fn ($l) => (int) $l)
                ->filter()->unique()->sort()->values();
            if ($nums->count() < 3) { continue; }

            $faltando = collect(range(1, $nums->last()))->diff($nums);
            if ($faltando->isEmpty()) { continue; }

            $this->warn(sprintf('  Q%-4s lotes faltando: %s  (existe 1..%d)',
                $quadra, $faltando->implode(', '), $nums->last()));
            $achados += $faltando->count();
        }

        return $achados;
    }

    /**
     * Identificação repetida.
     *
     * bairro|quadra|lote é a chave de integração com o cadastro imobiliário.
     * Repetida, ela deixa de identificar — e é o sintoma direto da quadra
     * atribuída pelo rótulo mais próximo.
     */
    private function duplicidade(string $b): int
    {
        // Lote sem quadra fica de fora: ele já é relatado por semNumero(), e
        // como todos compartilham a chave "bairro||número" apareceriam aqui
        // como dezenas de "identificação repetida" — afogando a duplicidade de
        // verdade, que é dois lotes DIFERENTES com a mesma quadra e número.
        $dup = DB::table('lotes')->where('bairro', $b)
            ->whereNotNull('quadra')->whereNotNull('numero_lote')
            ->where('quadra', '<>', '')->where('numero_lote', '<>', '')
            ->select('quadra', 'numero_lote', DB::raw('COUNT(*) n'))
            ->groupBy('quadra', 'numero_lote')->having('n', '>', 1)
            ->orderByDesc('n')->get();

        if ($dup->isEmpty()) { return 0; }

        $this->error('  IDENTIFICAÇÃO REPETIDA (' . $dup->count() . ' combinações):');
        foreach ($dup->take(10) as $d) {
            $this->line(sprintf('    Q%-4s Lt%-5s aparece %dx', $d->quadra, $d->numero_lote, $d->n));
        }
        if ($dup->count() > 10) { $this->line('    ... e mais ' . ($dup->count() - 10)); }
        $this->line('    Rode: php artisan lotes:corrigir-quadras --bairro="' . $b . '"');

        return $dup->count();
    }

    /** Área fora da faixa plausível de lote urbano. */
    private function areasSuspeitas(string $b): int
    {
        $g = DB::table('lotes')->where('bairro', $b)
            ->where('area_gis_m2', '>', self::AREA_SUSPEITA)
            ->orderByDesc('area_gis_m2')->get(['quadra', 'numero_lote', 'area_gis_m2']);

        $p = DB::table('lotes')->where('bairro', $b)
            ->where('area_gis_m2', '<', self::AREA_MINIMA)
            ->orderBy('area_gis_m2')->get(['quadra', 'numero_lote', 'area_gis_m2']);

        foreach ([['grande', $g], ['pequeno', $p]] as [$tipo, $lista]) {
            foreach ($lista as $l) {
                $this->warn(sprintf('  ÁREA suspeita (%s): Q%s Lt%s = %s m²',
                    $tipo, $l->quadra, $l->numero_lote, number_format((float) $l->area_gis_m2, 2, ',', '.')));
            }
        }

        return $g->count() + $p->count();
    }

    /** Lote sem número ou sem quadra não fecha chave de integração. */
    private function semNumero(string $b): int
    {
        $n = DB::table('lotes')->where('bairro', $b)
            ->where(fn ($q) => $q->whereNull('quadra')->orWhereNull('numero_lote')
                                 ->orWhere('quadra', '')->orWhere('numero_lote', ''))
            ->count();

        if ($n) {
            $this->error("  SEM QUADRA OU SEM NÚMERO: {$n} lote(s) — não fecham chave de integração.");
            $this->line('    O extrator deixa a quadra vazia quando o desenho não permite prová-la:');
            $this->line('    quadras coladas (sem a rua entre elas) ou sem rótulo dentro do quarteirão.');
            $this->line('    Corrigir o DWG e reimportar é o caminho — a reimportação atualiza no lugar.');

            // Mostrar a área e o número ajuda a achar o quarteirão no DWG: os
            // lotes sem quadra costumam vir todos de um mesmo bloco.
            $ex = DB::table('lotes')->where('bairro', $b)->whereNull('quadra')
                ->orderByRaw('CAST(numero_lote AS UNSIGNED)')
                ->limit(6)->get(['numero_lote', 'area_gis_m2']);
            foreach ($ex as $l) {
                $this->line(sprintf('      lote %-5s %s m² (quadra vazia)',
                    $l->numero_lote, number_format((float) $l->area_gis_m2, 2, ',', '.')));
            }
            if ($n > $ex->count()) {
                $this->line('      ... e mais ' . ($n - $ex->count()));
            }
        }

        return $n;
    }

    /**
     * Lote ATIVO com a mesma identificação de um lote INATIVO.
     *
     * Não é erro por si: unificar os lotes 05 e 06 e chamar o resultado de
     * "05" produz exatamente isso, e é a prática. O que a conferência procura
     * é o caso em que NÃO há ato ligando os dois — aí a identificação foi
     * reaproveitada por acidente, tipicamente por uma reimportação que
     * ressuscitou um lote que tinha sido unificado.
     */
    private function identidadeRessuscitada(string $b): int
    {
        $linhas = DB::select("
            SELECT a.quadra, a.numero_lote, a.id AS ativo, x.id AS inativo
              FROM lotes a
              JOIN lotes x ON x.bairro = a.bairro AND x.quadra = a.quadra
                          AND x.numero_lote = a.numero_lote AND x.situacao = 'inativo'
             WHERE a.bairro = ? AND a.situacao = 'ativo'
               AND NOT EXISTS (
                     SELECT 1 FROM lote_ato_lotes p
                       JOIN lote_ato_lotes s ON s.ato_id = p.ato_id
                      WHERE p.lote_id = x.id AND p.papel = 'anterior'
                        AND s.lote_id = a.id AND s.papel = 'posterior')
        ", [$b]);

        if (! $linhas) {
            return 0;
        }

        $this->error('  IDENTIFICAÇÃO REAPROVEITADA SEM ATO (' . count($linhas) . '):');
        foreach (array_slice($linhas, 0, 8) as $l) {
            $this->line("    Q{$l->quadra} Lt{$l->numero_lote}: o lote {$l->ativo} usa a identificação "
                . "do lote {$l->inativo}, que foi inativado — e nenhum ato liga os dois.");
        }
        $this->line('    Provável reimportação sobre imóvel que já tinha sido unificado.');

        return count($linhas);
    }

    /**
     * Lotes ATIVOS que se sobrepõem no terreno.
     *
     * É o defeito que o desenho manual e o desmembramento podem introduzir e
     * que nenhuma outra conferência detecta: dois imóveis ocupando o mesmo
     * pedaço de chão, cada um com sua identificação válida.
     *
     * A busca é em dois tempos, e a divisão NÃO é otimização — é correção:
     *
     *   1. o banco acha os CANDIDATOS com `MBRIntersects`, que é comparação de
     *      retângulos envolventes, usa o índice espacial e não erra;
     *   2. a área comum é medida FORA do banco, em App\Support\GeometriaPlana.
     *
     * Medir no banco não funciona. Em SRID 4326 o `ST_Intersection` do MySQL
     * devolve resultado errado: para os lotes 1 e 30 da quadra 40 do Buritis V,
     * de 214,47 m² cada, ele reportou 215,5 m² de área comum — mais do que a
     * área de qualquer um dos dois. A interseção verdadeira é ZERO; são
     * vizinhos. O `ST_Overlaps` também engana, respondendo "sim" para lotes
     * que apenas se encostam. E há pares em que o `ST_Intersection` nem
     * responde: levanta "General error: 3122 Inconsistent intersection points"
     * e derruba a conferência inteira.
     *
     * Confiar nele teria feito esta conferência acusar 26 sobreposições que
     * não existem — pior do que não conferir nada.
     */
    private function sobreposicao(string $b): int
    {
        $pares = DB::select("
            SELECT a.id ia, a.quadra qa, a.numero_lote la, ST_AsGeoJSON(a.geom) ga,
                   c.id ic, c.quadra qc, c.numero_lote lc, ST_AsGeoJSON(c.geom) gc
              FROM lotes a
              JOIN lotes c ON c.id > a.id AND c.bairro = a.bairro
                          AND c.situacao = 'ativo' AND MBRIntersects(a.geom, c.geom)
             WHERE a.bairro = ? AND a.situacao = 'ativo'
        ", [$b]);

        $achados = [];
        $duvidosos = [];

        foreach ($pares as $p) {
            $ra = json_decode($p->ga, true)['coordinates'][0] ?? null;
            $rb = json_decode($p->gc, true)['coordinates'][0] ?? null;
            if (! $ra || ! $rb) {
                continue;
            }

            // Os dois anéis na MESMA origem local, senão as coordenadas não
            // são comparáveis entre si.
            $lat = $ra[0][1];
            $lon = $ra[0][0];
            $r = GeometriaPlana::areaComum(
                GeometriaPlana::projetar($ra, $lat, $lon),
                GeometriaPlana::projetar($rb, $lat, $lon)
            );

            if ($r['area'] <= self::SOBREPOSICAO_TOLERADA_M2) {
                continue;
            }
            $r['confiavel'] ? $achados[] = [$p, $r['area']] : $duvidosos[] = [$p, $r['area']];
        }

        if ($achados) {
            usort($achados, fn ($x, $y) => $y[1] <=> $x[1]);
            $this->error('  LOTES ATIVOS SOBREPOSTOS (' . count($achados) . '):');
            foreach (array_slice($achados, 0, 12) as [$p, $area]) {
                $this->line(sprintf('    Q%s Lt%s × Q%s Lt%s compartilham %s m²',
                    $p->qa, $p->la, $p->qc, $p->lc, number_format($area, 2, ',', '.')));
            }
            $this->line('    Dois imóveis não ocupam o mesmo chão. Confira o desenho de ambos.');
        }

        if ($duvidosos) {
            $this->warn('  SOBREPOSIÇÃO PROVÁVEL, MEDIDA NÃO CONFIÁVEL (' . count($duvidosos) . '):');
            foreach (array_slice($duvidosos, 0, 6) as [$p, $area]) {
                $this->line(sprintf('    Q%s Lt%s × Q%s Lt%s ~%s m² — nenhum dos dois é convexo.',
                    $p->qa, $p->la, $p->qc, $p->lc, number_format($area, 2, ',', '.')));
            }
            $this->line('    Confira no QGIS: lote em L ou com reentrância exige medida manual.');
        }

        return count($achados) + count($duvidosos);
    }

    /**
     * Sucessão pela metade.
     *
     * Três defeitos que só existem depois que desmembramento e unificação
     * passam a gravar, e que nada mais enxerga:
     *
     *   - lote inativo sem ato nenhum: inativaram na mão, e o imóvel sumiu do
     *     mapa sem deixar dito para onde foi;
     *   - ato sem anterior ou sem posterior: gravação interrompida no meio;
     *   - sufixo de desmembramento preenchido sem ato de origem.
     */
    private function sucessaoQuebrada(): int
    {
        $achados = 0;

        $orfaos = DB::table('lotes')->where('situacao', 'inativo')
            ->whereNotExists(fn ($q) => $q->from('lote_ato_lotes')
                ->whereColumn('lote_ato_lotes.lote_id', 'lotes.id')
                ->where('papel', 'anterior'))
            ->get(['id', 'bairro', 'quadra', 'numero_lote']);

        if ($orfaos->isNotEmpty()) {
            $this->newLine();
            $this->error('BAIXA SEM ATO (' . $orfaos->count() . '):');
            foreach ($orfaos->take(8) as $l) {
                $this->line("  lote {$l->id} — {$l->bairro} Q{$l->quadra} Lt{$l->numero_lote}");
            }
            $this->line('  Imóvel inativado sem dizer para onde foi. Sem isso a ficha não');
            $this->line('  consegue mostrar a origem do sucessor.');
            $achados += $orfaos->count();
        }

        $incompletos = DB::table('lote_atos')
            ->where(fn ($q) => $q
                ->whereNotExists(fn ($s) => $s->from('lote_ato_lotes')
                    ->whereColumn('lote_ato_lotes.ato_id', 'lote_atos.id')->where('papel', 'anterior'))
                ->orWhereNotExists(fn ($s) => $s->from('lote_ato_lotes')
                    ->whereColumn('lote_ato_lotes.ato_id', 'lote_atos.id')->where('papel', 'posterior')))
            ->get(['id', 'tipo', 'protocolo_id']);

        if ($incompletos->isNotEmpty()) {
            $this->newLine();
            $this->error('ATO INCOMPLETO (' . $incompletos->count() . '):');
            foreach ($incompletos as $a) {
                $this->line("  ato {$a->id} ({$a->tipo}, protocolo {$a->protocolo_id}) "
                    . 'não tem lote anterior ou não tem lote posterior.');
            }
            $achados += $incompletos->count();
        }

        $sufixoSolto = DB::table('lotes')->where('desmembramento', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('lote_ato_lotes')
                ->whereColumn('lote_ato_lotes.lote_id', 'lotes.id')
                ->where('papel', 'posterior'))
            ->count();

        if ($sufixoSolto > 0) {
            $this->newLine();
            $this->warn("SUFIXO DE DESMEMBRAMENTO SEM ATO: {$sufixoSolto} lote(s).");
            $this->line('  A inscrição diz que o imóvel veio de um desmembramento, mas não há');
            $this->line('  ato registrado. Provável digitação manual do sufixo.');
            $achados += $sufixoSolto;
        }

        return $achados;
    }
}
