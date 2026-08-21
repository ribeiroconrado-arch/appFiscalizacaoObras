<?php

namespace App\Services;

use App\Models\Lote;
use Illuminate\Support\Facades\DB;

/**
 * Preenche a quadra de um quarteirão inteiro a partir de UM lote.
 *
 * Por que existe: o extrator de GeoJSON deixa a quadra VAZIA quando o desenho
 * não permite prová-la — quadra colada na vizinha (sem a rua entre elas) ou
 * sem rótulo dentro do quarteirão. É de propósito: lote sem quadra aparece na
 * conferência e se resolve; lote com quadra ERRADA passa despercebido e
 * contamina a chave de integração com o cadastro imobiliário.
 *
 * Mas "aparece na conferência" só serve se houver como corrigir sem digitar
 * lote a lote. No Residencial Buritis V são 183 lotes sem quadra — que caem em
 * apenas 14 quarteirões. Dizer a quadra de UM lote de cada um resolve os 183.
 *
 * O que torna isto seguro, e não um novo palpite automático:
 *
 *   1. A herança só atravessa lote SEM quadra. Lote com quadra provada é
 *      parede — a propagação encosta nele e para. Sem isso seria a fusão de
 *      quadras que já aconteceu neste mesmo bairro, quando uma correção por
 *      maioria juntou seis quadras numa só.
 *   2. Numa quadra de verdade cada número de lote aparece UMA vez. Se o
 *      quarteirão repetir número por dentro, ele não é uma quadra só.
 *   3. Se algum número do quarteirão já existir na quadra informada, a quadra
 *      informada está errada — gravar criaria duas identificações iguais.
 *
 * Quem decide é sempre a pessoa; o sistema só recusa o que consegue provar
 * que está errado.
 */
class QuadraDoQuarteirao
{
    /** Lotes a menos disto se tocam. A rua tem ~15 m. */
    public const TOLERANCIA_M = 1.0;

    /**
     * Quarteirões de lotes sem quadra do bairro, em ordem estável (pelo menor
     * id), para que o número do quarteirão não mude entre uma listagem e a
     * gravação seguinte.
     *
     * @return array<int, list<int>>
     */
    public function quarteiroes(string $bairro): array
    {
        $ids = DB::table('lotes')->where('bairro', $bairro)
            ->whereNull('quadra')->orderBy('id')->pluck('id')->all();

        if (! $ids) {
            return [];
        }

        // MBRIntersects usa o índice espacial e derruba o custo do par a par;
        // o ST_Distance depois confirma o encosto de verdade.
        $pares = DB::select(
            'SELECT a.id a, b.id b FROM lotes a
               JOIN lotes b ON b.id > a.id AND a.bairro = b.bairro
              WHERE a.bairro = ? AND a.quadra IS NULL AND b.quadra IS NULL
                AND MBRIntersects(a.geom, b.geom)
                AND ST_Distance(a.geom, b.geom) <= ?',
            [$bairro, self::TOLERANCIA_M]
        );

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

        $porRaiz = [];
        foreach ($ids as $id) {
            $porRaiz[$raiz($id)][] = $id;
        }

        $grupos = array_values($porRaiz);
        usort($grupos, fn ($x, $y) => min($x) <=> min($y));

        $out = [];
        foreach ($grupos as $i => $grupo) {
            $out[$i + 1] = $grupo;
        }

        return $out;
    }

    /**
     * O quarteirão a que este lote pertence. Null se o lote já tem quadra.
     *
     * @return list<int>|null
     */
    public function quarteiraoDoLote(Lote $lote): ?array
    {
        if ($lote->quadra !== null && $lote->quadra !== '') {
            return null;
        }

        foreach ($this->quarteiroes($lote->bairro) as $ids) {
            if (in_array($lote->id, $ids, true)) {
                return $ids;
            }
        }

        return null;
    }

    /**
     * Retrato do quarteirão para a tela: tamanho, numeração, quadras vizinhas
     * e o palpite de qual quadra é.
     *
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function retrato(string $bairro, array $ids): array
    {
        $numeros = DB::table('lotes')->whereIn('id', $ids)
            ->orderByRaw('CAST(numero_lote AS UNSIGNED)')
            ->pluck('numero_lote')->filter()->values();

        $vizinhas = collect($this->quadrasVizinhas($bairro, $ids));

        return [
            'lotes'      => count($ids),
            'numeracao'  => $numeros->isEmpty() ? null : $numeros->first() . '..' . $numeros->last(),
            'vizinhas'   => $vizinhas->all(),
            'sugestao'   => $this->sugestao($bairro),
            'coerente'   => $this->numeroRepetido($ids) === null,
        ];
    }

    /**
     * Quadras dos lotes que encostam neste quarteirão. É a pista mais útil
     * para quem vai preencher: o quarteirão espremido entre a 38 e a 49 é,
     * quase sempre, a quadra que falta na sequência.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function quadrasVizinhas(string $bairro, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $linhas = DB::select(
            'SELECT DISTINCT v.quadra q FROM lotes v
               JOIN lotes s ON s.bairro = v.bairro AND s.id IN (' . implode(',', array_map('intval', $ids)) . ')
              WHERE v.bairro = ? AND v.quadra IS NOT NULL
                AND MBRIntersects(v.geom, s.geom) AND ST_Distance(v.geom, s.geom) <= ?',
            [$bairro, self::TOLERANCIA_M]
        );

        $qs = array_map(fn ($r) => $r->q, $linhas);
        sort($qs);

        return $qs;
    }

    /**
     * A primeira quadra que falta na sequência do bairro — ou, se não há
     * buraco, a próxima depois da última.
     *
     * Quadra é numerada em sequência dentro do loteamento, então o buraco é o
     * candidato natural. Quando a sequência está inteira, o que sobrou de fora
     * costuma continuar dela para cima: no Buritis V restaram 13 quarteirões
     * sem quadra depois da 51.
     *
     * É palpite para preencher o campo, não decisão: quem confirma é a pessoa,
     * e as provas de impedimento continuam valendo sobre o que ela digitar.
     */
    public function sugestao(string $bairro): ?string
    {
        $nums = DB::table('lotes')->where('bairro', $bairro)
            ->whereNotNull('quadra')->distinct()->pluck('quadra')
            ->map(fn ($q) => (int) $q)->filter()->unique()->sort()->values();

        if ($nums->count() < 2) {
            return null;
        }

        foreach (range($nums->first(), $nums->last()) as $n) {
            if (! $nums->contains($n)) {
                return sprintf('%02d', $n);
            }
        }

        return sprintf('%02d', $nums->last() + 1);
    }

    /**
     * Duas grafias da mesma quadra ("7" e "07") quebrariam a chave de
     * integração. A base grava sempre com dois dígitos.
     */
    public function normalizar(string $quadra): string
    {
        $quadra = trim($quadra);

        return ctype_digit($quadra) ? sprintf('%02d', (int) $quadra) : $quadra;
    }

    /**
     * Devolve a mensagem do impedimento, ou null se pode gravar.
     *
     * @param  list<int>  $ids
     */
    public function impedimento(string $bairro, array $ids, string $quadra): ?string
    {
        if (! $ids) {
            return 'Este lote não está num quarteirão sem quadra.';
        }

        $repetido = $this->numeroRepetido($ids);
        if ($repetido !== null) {
            return "Este quarteirão repete o lote {$repetido} por dentro — são quadras coladas no "
                . 'desenho, não uma quadra só. Separá-las exige corrigir o DWG e reimportar.';
        }

        $numeros = DB::table('lotes')->whereIn('id', $ids)
            ->pluck('numero_lote')->filter()->all();

        $choque = DB::table('lotes')->where('bairro', $bairro)->where('quadra', $quadra)
            ->whereIn('numero_lote', $numeros)->pluck('numero_lote');

        if ($choque->isNotEmpty()) {
            return "A quadra {$quadra} já tem o(s) lote(s) " . $choque->take(6)->implode(', ')
                . '. Gravar criaria duas identificações iguais — confira o número da quadra.';
        }

        return null;
    }

    /**
     * Grava a quadra em todo o quarteirão. Devolve quantos lotes mudaram.
     *
     * @param  list<int>  $ids
     */
    public function aplicar(string $bairro, array $ids, string $quadra): int
    {
        return DB::transaction(function () use ($bairro, $ids, $quadra) {
            $n = 0;
            foreach (DB::table('lotes')->whereIn('id', $ids)->get(['id', 'numero_lote']) as $l) {
                DB::table('lotes')->where('id', $l->id)->update([
                    'quadra'     => $quadra,
                    'chave'      => $bairro . '|' . $quadra . '|' . ($l->numero_lote ?: '?'),
                    'updated_at' => now(),
                ]);
                $n++;
            }

            return $n;
        });
    }

    /**
     * O primeiro número de lote que aparece duas vezes no quarteirão, ou null.
     *
     * @param  list<int>  $ids
     */
    private function numeroRepetido(array $ids): ?string
    {
        $vistos = [];
        foreach (DB::table('lotes')->whereIn('id', $ids)->pluck('numero_lote') as $num) {
            if ($num === null || $num === '') {
                continue;
            }
            if (isset($vistos[$num])) {
                return (string) $num;
            }
            $vistos[$num] = true;
        }

        return null;
    }
}
