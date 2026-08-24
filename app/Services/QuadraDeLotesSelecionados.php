<?php

namespace App\Services;

use App\Models\Lote;
use Illuminate\Support\Facades\DB;

/**
 * Grava a quadra num conjunto de lotes escolhidos a dedo no mapa.
 *
 * É irmão de QuadraDoQuarteirao, e a diferença entre os dois é o que cada um
 * aceita tocar:
 *
 *   QuadraDoQuarteirao  só lote SEM quadra, e o alcance é o quarteirão inteiro
 *                       (quem manda é a adjacência, não a pessoa).
 *   este                lote QUALQUER, inclusive com quadra já preenchida, e o
 *                       alcance é exatamente o que foi clicado.
 *
 * Existe porque o primeiro não resolve quadra ERRADA — e quadra errada é o
 * defeito mais perigoso da base: ela não deixa buraco nenhum à vista, só faz
 * dois imóveis diferentes responderem pela mesma identificação no cadastro
 * imobiliário.
 *
 * Poder maior, provas mais duras. Sobrescrever quadra que estava certa é um
 * clique de distância, então tudo que o sistema consegue provar que está
 * errado ele recusa, e o que ele apenas desconfia ele avisa antes de gravar.
 *
 * Os dois serviços NÃO foram unificados de propósito: os invariantes são
 * diferentes (ver a prova 6 abaixo, que precisa de `whereNotIn` e não existe
 * no outro). Unificar cedo demais foi como seis quadras do Buritis viraram uma.
 */
class QuadraDeLotesSelecionados
{
    /**
     * Teto de lotes por operação.
     *
     * Não é limite técnico — o UPDATE aguentaria milhares. É limite de
     * CONFERÊNCIA: ninguém revisa de olho uma lista de mil identificações
     * antes de confirmar, e uma correção em massa que não foi conferida é
     * indistinguível de um acidente. Quem precisa de mais faz em duas vezes,
     * ou usa o comando de terminal, que relata lote a lote.
     */
    public const MAXIMO = 300;

    /** Lotes a menos disto se tocam. A rua tem ~15 m. */
    private const TOLERANCIA_M = 1.0;

    public function __construct(private QuadraDoQuarteirao $quarteirao) {}

    /**
     * Duas grafias da mesma quadra ("7" e "07") quebrariam a chave de
     * integração. A base grava sempre com dois dígitos.
     */
    public function normalizar(string $quadra): string
    {
        return $this->quarteirao->normalizar($quadra);
    }

    /**
     * O que a tela mostra ANTES de gravar: o que muda, o que não muda, e o que
     * o sistema desconfia.
     *
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function retrato(array $ids, string $quadra): array
    {
        $lotes = $this->lotes($ids);

        $mudam    = $lotes->filter(fn ($l) => (string) $l->quadra !== $quadra);
        $jaEstao  = $lotes->count() - $mudam->count();
        $comQuadra = $mudam->filter(fn ($l) => $l->quadra !== null && $l->quadra !== '');

        return [
            'total'          => $lotes->count(),
            'quadra'         => $quadra,
            'bairro'         => $lotes->first()->bairro ?? null,
            'mudam'          => $mudam->count(),
            'ja_estao'       => $jaEstao,
            // Sobrescrita é o caso perigoso e tem de aparecer sozinho, não
            // diluído no total: são lotes cuja quadra já estava preenchida.
            'sobrescreve'    => $comQuadra->count(),
            'de_para'        => $comQuadra->take(12)->map(fn ($l) => [
                'lote' => $l->numero_lote,
                'de'   => $l->quadra,
                'para' => $quadra,
            ])->values()->all(),
            'origens'        => $comQuadra->pluck('quadra')->unique()->sort()->values()->all(),
        ];
    }

    /**
     * Devolve a mensagem do impedimento, ou null se pode gravar.
     *
     * @param  list<int>  $ids
     */
    public function impedimento(array $ids, string $quadra): ?string
    {
        if (! $ids) {
            return 'Selecione ao menos um lote no mapa.';
        }

        if (count($ids) > self::MAXIMO) {
            return sprintf('Seleção de %d lotes: o máximo por operação é %d. '
                . 'O limite existe para a lista poder ser conferida antes de gravar.',
                count($ids), self::MAXIMO);
        }

        $lotes = $this->lotes($ids);

        if ($lotes->count() !== count(array_unique($ids))) {
            return 'Algum lote da seleção não existe mais. Recarregue o mapa e refaça.';
        }

        // Quadra é numerada DENTRO do bairro: a 07 do Buritis e a 07 do Jardim
        // Europa são quadras diferentes. Gravar a mesma quadra em lotes de dois
        // bairros produziria duas quadras "07" que o sistema trata como uma.
        $bairros = $lotes->pluck('bairro')->unique();
        if ($bairros->count() > 1) {
            return 'A seleção mistura os bairros ' . $bairros->implode(' e ')
                . '. Quadra é numerada dentro do bairro — corrija um de cada vez.';
        }

        // Numa quadra de verdade cada número de lote aparece uma vez.
        if ($repetido = $this->quarteirao->numeroRepetido($ids)) {
            return "A seleção tem o lote {$repetido} repetido — são imóveis de quadras "
                . 'diferentes, e juntá-los na mesma quadra criaria identificação duplicada.';
        }

        // Choque com quem já está na quadra de destino.
        //
        // O `whereNotIn` é obrigatório e é a diferença em relação ao
        // QuadraDoQuarteirao: como aqui se altera lote que JÁ tem quadra, a
        // seleção pode conter lotes que já são da quadra de destino. Sem
        // excluir os próprios ids, a operação se acusaria a si mesma — o
        // lote 12 da quadra 08 impediria de gravar quadra 08 no lote 12.
        $numeros = $lotes->pluck('numero_lote')->filter()->all();
        $choque  = DB::table('lotes')
            ->where('bairro', $bairros->first())
            ->where('quadra', $quadra)
            ->where('situacao', 'ativo')
            ->whereNotIn('id', $ids)
            ->whereIn('numero_lote', $numeros)
            ->pluck('numero_lote');

        if ($choque->isNotEmpty()) {
            return "A quadra {$quadra} já tem o(s) lote(s) " . $choque->take(6)->implode(', ')
                . '. Gravar criaria duas identificações iguais.';
        }

        return null;
    }

    /**
     * O que o sistema desconfia mas não impede. Cada aviso exige confirmação
     * explícita na tela.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function avisos(array $ids, string $quadra): array
    {
        $lotes = $this->lotes($ids);
        $avisos = [];

        if ($lotes->isEmpty()) {
            return $avisos;
        }

        $bairro = $lotes->first()->bairro;

        // ── seleção partida ao meio ──
        // Uma quadra é um bloco de lotes que se encostam. Seleção em dois
        // pedaços desconexos quase sempre é clique no lote errado do outro
        // lado da rua. Não impede: há quadra cortada por praça ou córrego.
        $pedacos = $this->pedacos($ids);
        if ($pedacos > 1) {
            $avisos[] = "A seleção está partida em {$pedacos} blocos que não se encostam. "
                . 'Uma quadra costuma ser um bloco só — confira se não há lote clicado '
                . 'do outro lado da rua.';
        }

        // ── quadra de origem que fica vazia ──
        foreach ($lotes->pluck('quadra')->filter()->unique() as $origem) {
            if ((string) $origem === $quadra) {
                continue;
            }
            $sobram = DB::table('lotes')->where('bairro', $bairro)->where('situacao', 'ativo')
                ->where('quadra', $origem)->whereNotIn('id', $ids)->count();
            if ($sobram === 0) {
                $avisos[] = "A quadra {$origem} ficará sem nenhum lote e deixará de existir em {$bairro}.";
            }
        }

        // ── lote sem número ──
        $semNumero = $lotes->filter(fn ($l) => $l->numero_lote === null || $l->numero_lote === '')->count();
        if ($semNumero) {
            $avisos[] = "{$semNumero} lote(s) da seleção não têm número: a chave deles ficará "
                . 'incompleta e eles seguirão aparecendo na conferência.';
        }

        return $avisos;
    }

    /**
     * Grava. Devolve quantos lotes mudaram de fato.
     *
     * Pelo Eloquent, para a trilha de auditoria registrar cada alteração de
     * identificação (ver App\Models\Lote::acaoAuditoria). `lockForUpdate`
     * porque duas correções cruzadas simultâneas passariam pelas provas de
     * cada uma e produziriam identificação duplicada.
     *
     * @param  list<int>  $ids
     */
    public function aplicar(array $ids, string $quadra): int
    {
        return DB::transaction(function () use ($ids, $quadra) {
            $n = 0;
            foreach (Lote::whereIn('id', $ids)->lockForUpdate()->get() as $lote) {
                if ((string) $lote->quadra === $quadra) {
                    continue;   // já está lá: não é alteração, e não vira linha de auditoria
                }
                $lote->update([
                    'quadra' => $quadra,
                    'chave'  => $lote->bairro . '|' . $quadra . '|' . ($lote->numero_lote ?: '?'),
                ]);
                $n++;
            }

            return $n;
        });
    }

    /**
     * Em quantos blocos desconexos a seleção se divide (union-find por
     * adjacência). 1 = bloco único.
     *
     * @param  list<int>  $ids
     */
    private function pedacos(array $ids): int
    {
        if (count($ids) < 2) {
            return 1;
        }

        // MBRIntersects usa o índice espacial e derruba o custo do par a par;
        // o ST_Distance depois confirma o encosto de verdade.
        $pares = DB::select(
            'SELECT a.id a, b.id b FROM lotes a
               JOIN lotes b ON b.id > a.id
              WHERE a.situacao = \'ativo\' AND b.situacao = \'ativo\'
                AND a.id IN (' . implode(',', array_map('intval', $ids)) . ')
                AND b.id IN (' . implode(',', array_map('intval', $ids)) . ')
                AND MBRIntersects(a.geom, b.geom)
                AND ST_Distance(a.geom, b.geom) <= ?',
            [self::TOLERANCIA_M]
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

        $raizes = [];
        foreach ($ids as $id) {
            $raizes[$raiz($id)] = true;
        }

        return count($raizes);
    }

    /** @param list<int> $ids */
    private function lotes(array $ids): \Illuminate\Support\Collection
    {
        return DB::table('lotes')->whereIn('id', $ids)->where('situacao', 'ativo')
            ->orderByRaw('CAST(numero_lote AS UNSIGNED)')
            ->get(['id', 'bairro', 'quadra', 'numero_lote']);
    }
}
