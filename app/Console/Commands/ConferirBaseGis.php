<?php

namespace App\Console\Commands;

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
        }

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
}
