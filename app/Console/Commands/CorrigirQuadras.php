<?php

namespace App\Console\Commands;

use App\Models\Lote;
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

        $lotes = DB::table('lotes')->where('situacao', 'ativo')
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
              WHERE a.situacao = \'ativo\' AND b.situacao = \'ativo\'
                AND MBRIntersects(a.geom, b.geom)
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

        $fundidos = 0;

        foreach ($grupos as $ids) {
            // ── TRAVA CONTRA FUSÃO DE QUADRAS ──
            //
            // A adjacência assume que quadras vizinhas são separadas pela rua.
            // Onde a digitalização não respeita isso, lotes de quadras
            // diferentes se tocam e viram UM componente só — e a maioria
            // engole todas. Foi o que aconteceu no Residencial Buritis V: a
            // Q38 saiu de 89 para 150 lotes, absorvendo seis quadras vizinhas.
            //
            // O teste não é um limite de tamanho (que seria chute), é
            // estrutural: numa quadra de verdade cada número de lote aparece
            // UMA vez. Número repetido dentro do componente prova que ali há
            // mais de uma quadra, e então não há o que decidir por maioria.
            $vistos = [];
            $repetido = null;
            foreach ($ids as $id) {
                $n = $lotes[$id]->numero_lote;
                if ($n === null || $n === '') { continue; }
                if (isset($vistos[$n])) { $repetido = $n; break; }
                $vistos[$n] = true;
            }
            if ($repetido !== null) {
                $fundidos++;
                $this->warn(sprintf(
                    '  grupo de %d lotes IGNORADO: número de lote "%s" repetido dentro dele '
                    . '— são quadras distintas coladas, não uma só.',
                    count($ids), $repetido
                ));
                continue;
            }

            $votos = [];
            foreach ($ids as $id) {
                $q = $lotes[$id]->quadra;
                if ($q !== null && $q !== '') {
                    $votos[$q] = ($votos[$q] ?? 0) + 1;
                }
            }
            if (! $votos) { $semNumero++; continue; }

            arsort($votos);

            // O (string) é obrigatório: o PHP converte chave de array numérica
            // em INTEIRO, então `$votos["24"]` vira `$votos[24]` e
            // array_key_first() devolve int. A comparação estrita lá embaixo
            // (`!==`) passava a dar verdadeiro para TODO lote de quadra sem
            // zero à esquerda — 570 falsos positivos de 707 lotes, exibidos
            // como "Q24 -> Q24", escondendo as correções reais no meio.
            $vencedor = (string) array_key_first($votos);
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
        if ($fundidos)  {
            $this->error('Grupos IGNORADOS por fusão de quadras: ' . $fundidos);
            $this->line('  Nesses o desenho não separa as quadras pela rua — a adjacência não');
            $this->line('  resolve, e forçar produziria uma quadra fictícia. Corrija no DWG.');
        }
        if ($empates)   { $this->warn('Grupos sem maioria clara: ' . $empates); }
        if ($semNumero) { $this->warn('Grupos sem nenhum número: ' . $semNumero); }

        foreach (array_slice($correcoes, 0, 100) as $c) {
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
        //
        // Pelo Eloquent, não por `DB::table()`: só ele dispara os eventos da
        // trilha de auditoria. Este comando é a maior intervenção que o
        // sistema permite — reatribui a quadra de centenas de imóveis de uma
        // vez — e era justamente a única que não deixava rastro.
        //
        // Rodando no terminal não há sessão, então o autor sai como
        // "terminal: lotes:corrigir-quadras" (ver RegistraAuditoria).
        DB::transaction(function () use ($correcoes) {
            foreach ($correcoes as $c) {
                $lote = Lote::find($c['id']);
                if (! $lote) {
                    continue;
                }
                $lote->update([
                    'quadra' => $c['para'],
                    'chave'  => $c['bairro'] . '|' . $c['para'] . '|' . $lote->numero_lote,
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
            SELECT 1 FROM lotes WHERE situacao = \'ativo\'
             GROUP BY bairro, quadra, numero_lote HAVING COUNT(*) > 1
        ) t');
        $this->newLine();
        $this->info('Chaves duplicadas restantes: ' . ($dup[0]->n ?? '?'));
    }
}
