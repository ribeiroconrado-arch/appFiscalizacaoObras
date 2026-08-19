<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reatribui a quadra de cada lote pela GEOMETRIA, não pelo rótulo mais próximo.
 *
 * O pipeline de importação (gis/tools/dxf_para_geojson.py) atribuía a quadra
 * pelo texto de quadra mais próximo do centroide do lote — com a ressalva, no
 * próprio comentário do script, de que erraria "na divisa entre duas". Nas
 * quadras compridas deste loteamento o rótulo fica no meio, e os lotes das
 * pontas acabam mais perto do rótulo da quadra vizinha. O resultado foram 259
 * lotes (11,6% da base) com identificação bairro|quadra|lote repetida — o que
 * quebra justamente a chave de integração com o cadastro imobiliário.
 *
 * A correção usa o fato físico que define uma quadra: os lotes de uma quadra
 * se TOCAM entre si (distância zero) e são separados dos da quadra vizinha
 * pela rua — medido aqui em ~15 m. Então:
 *
 *   1. monta o grafo de adjacência (lotes a menos de TOLERANCIA metros);
 *   2. cada componente conexa é uma quadra física;
 *   3. a quadra do grupo é o número que a MAIORIA dos lotes já trazia.
 *
 * O passo 3 se apoia no erro ser minoritário dentro de cada quadra: quem erra
 * é a borda, não o miolo. Onde isso não valer, o relatório mostra o empate em
 * vez de escolher sozinho.
 */
class CorrigirQuadras extends Command
{
    protected $signature = 'lotes:corrigir-quadras
                            {--aplicar : grava as correções; sem esta opção só relata}
                            {--bairro= : limita a um bairro}';

    protected $description = 'Reatribui a quadra dos lotes por adjacência geométrica';

    /** Lotes a menos disto são da mesma quadra. A rua tem ~15 m. */
    private const TOLERANCIA_M = 1.0;

    public function handle(): int
    {
        $bairro = $this->option('bairro');
        $aplicar = $this->option('aplicar');

        $lotes = DB::table('lotes')
            ->when($bairro, fn ($q) => $q->where('bairro', $bairro))
            ->select('id', 'bairro', 'quadra', 'numero_lote')
            ->get()->keyBy('id');

        $this->info('Lotes analisados: ' . $lotes->count());

        // ── 1. adjacência ──
        // MBRIntersects usa o índice espacial e derruba o custo do par a par;
        // o ST_Distance depois confirma o encosto de verdade.
        $this->line('Montando o grafo de adjacência...');
        $pares = DB::select(
            'SELECT a.id a, b.id b
               FROM lotes a
               JOIN lotes b ON b.id > a.id AND a.bairro = b.bairro
              WHERE MBRIntersects(a.geom, b.geom)
                AND ST_Distance(a.geom, b.geom) <= ?'
            . ($bairro ? ' AND a.bairro = ?' : ''),
            $bairro ? [self::TOLERANCIA_M, $bairro] : [self::TOLERANCIA_M]
        );
        $this->line('Pares vizinhos: ' . count($pares));

        // ── 2. componentes conexas (union-find) ──
        $pai = [];
        $raiz = function (int $x) use (&$pai, &$raiz): int {
            while (($pai[$x] ?? $x) !== $x) {
                $pai[$x] = $pai[$pai[$x]] ?? $pai[$x];
                $x = $pai[$x];
            }
            return $x;
        };
        foreach ($pares as $p) {
            $ra = $raiz($p->a);
            $rb = $raiz($p->b);
            if ($ra !== $rb) {
                $pai[$ra] = $rb;
            }
        }

        $grupos = [];
        foreach ($lotes as $id => $l) {
            $grupos[$raiz($id)][] = $id;
        }
        $this->line('Quadras físicas encontradas: ' . count($grupos));

        // ── 3. número da quadra por maioria ──
        $correcoes = [];
        $empates = 0;
        $semNumero = 0;

        foreach ($grupos as $ids) {
            $votos = [];
            foreach ($ids as $id) {
                $q = $lotes[$id]->quadra;
                if ($q !== null && $q !== '') {
                    $votos[$q] = ($votos[$q] ?? 0) + 1;
                }
            }
            if (! $votos) { $semNumero++; continue; }

            arsort($votos);
            $vencedor = array_key_first($votos);
            $maisVotos = $votos[$vencedor];

            // Empate real: dois números com a mesma votação. Não decide sozinho.
            if (count(array_keys($votos, $maisVotos)) > 1) {
                $empates++;
                $this->warn('  empate no grupo de ' . count($ids) . ' lotes: '
                    . json_encode($votos, JSON_UNESCAPED_UNICODE));
                continue;
            }

            foreach ($ids as $id) {
                if ($lotes[$id]->quadra !== $vencedor) {
                    $correcoes[] = [
                        'id' => $id,
                        'bairro' => $lotes[$id]->bairro,
                        'de' => $lotes[$id]->quadra,
                        'para' => $vencedor,
                        'lote' => $lotes[$id]->numero_lote,
                    ];
                }
            }
        }

        $this->newLine();
        $this->info('Lotes a corrigir: ' . count($correcoes));
        if ($empates)   { $this->warn('Grupos sem maioria clara: ' . $empates); }
        if ($semNumero) { $this->warn('Grupos sem nenhum número: ' . $semNumero); }

        foreach (array_slice($correcoes, 0, 15) as $c) {
            $this->line(sprintf('  %s: lote %s  Q%s -> Q%s', $c['bairro'], $c['lote'], $c['de'], $c['para']));
        }
        if (count($correcoes) > 15) {
            $this->line('  ... e mais ' . (count($correcoes) - 15));
        }

        if (! $aplicar) {
            $this->newLine();
            $this->comment('Simulação. Rode com --aplicar para gravar.');
            return self::SUCCESS;
        }

        // ── grava, e refaz a chave de integração junto ──
        DB::transaction(function () use ($correcoes) {
            foreach ($correcoes as $c) {
                DB::table('lotes')->where('id', $c['id'])->update([
                    'quadra' => $c['para'],
                    'chave'  => $c['bairro'] . '|' . $c['para'] . '|' . DB::table('lotes')
                        ->where('id', $c['id'])->value('numero_lote'),
                ]);
            }
        });

        $this->info('Gravado.');
        $this->relatorioDuplicidade();

        return self::SUCCESS;
    }

    private function relatorioDuplicidade(): void
    {
        $dup = DB::select('SELECT COUNT(*) n FROM (
            SELECT 1 FROM lotes GROUP BY bairro, quadra, numero_lote HAVING COUNT(*) > 1
        ) t');
        $this->newLine();
        $this->info('Chaves duplicadas restantes: ' . ($dup[0]->n ?? '?'));
    }
}
