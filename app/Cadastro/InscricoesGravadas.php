<?php

namespace App\Cadastro;

use App\Models\CadastroBairro;
use Illuminate\Support\Facades\DB;

/**
 * A inscrição MONTADA de cada lote, gravada no banco (`lotes.inscricao_montada`).
 *
 * A inscrição é derivada — código do bairro + quadra + lote + desmembramento —,
 * e o código do bairro vem da amarração `cadastro_bairros.nome_gis` =
 * `lotes.bairro`. Derivar sem guardar deixava a inscrição à mercê dessa
 * amarração: em 02/10/2026 trocar o "nome no desenho" de dois bairros (para
 * encurtar o rótulo do mapa) desligou 2.235 lotes, e a ficha passou a dizer
 * "sem inscrição" para todos.
 *
 * Por isso a inscrição, assim que pode ser montada, fica gravada:
 *   - não se perde se a amarração falhar — vale a última montada;
 *   - fica comparável, em SQL, com o que vem do sistema da prefeitura.
 *
 * Ordem de leitura (ver BairrosDoDesenho::inscricaoDe e Lote::inscricao):
 *   1. `inscricao_imobiliaria` — a informada à mão, que tem precedência;
 *   2. a montada AGORA, das partes atuais do lote — e, se ela difere da
 *      gravada, é ela que passa a ficar gravada (quadra renumerada na
 *      curadoria muda a inscrição, e a gravada antiga estaria errada);
 *   3. `inscricao_montada` — a última que se conseguiu montar.
 *
 * As gravações vão por DB::table, e não pelo modelo: são milhares de linhas
 * numa importação, e cada uma na trilha de auditoria afogaria o que importa
 * lá. A trilha registra a causa (o bairro amarrado, a importação publicada),
 * não cada consequência.
 */
class InscricoesGravadas
{
    public function __construct(private ?BairrosDoDesenho $bairros = null)
    {
        $this->bairros ??= new BairrosDoDesenho();
    }

    /**
     * Monta e grava a inscrição dos lotes dos bairros dados (todos, se nulo).
     *
     * Só grava onde dá para montar e o valor mudou: lote de bairro sem
     * amarração conserva a última inscrição que teve.
     *
     * @param  array<int,string>|null  $nomesDoDesenho
     * @return int quantos lotes tiveram a inscrição gravada ou trocada
     */
    public function gravar(?array $nomesDoDesenho = null): int
    {
        $q = DB::table('lotes')
            ->select('id', 'bairro', 'quadra', 'numero_lote', 'desmembramento', 'inscricao_montada');
        if ($nomesDoDesenho !== null) {
            $nomes = array_values(array_filter($nomesDoDesenho, fn ($n) => $n !== null && $n !== ''));
            if (! $nomes) {
                return 0;
            }
            $q->whereIn('bairro', $nomes);
        }

        $total = 0;
        $q->orderBy('id')->chunk(1000, function ($linhas) use (&$total) {
            $total += $this->persistir($this->mudancas($linhas));
        });

        return $total;
    }

    /**
     * As inscrições que mudaram num punhado de linhas já lidas.
     *
     * @param  iterable<object>  $linhas  com bairro, quadra, numero_lote, desmembramento, inscricao_montada
     * @return array<int,string> id => inscrição montada agora
     */
    public function mudancas(iterable $linhas): array
    {
        $mudou = [];
        foreach ($linhas as $l) {
            $agora = $this->montar($l);
            if ($agora !== null && $agora !== ($l->inscricao_montada ?? null)) {
                $mudou[(int) $l->id] = $agora;
            }
        }

        return $mudou;
    }

    /** A inscrição que as partes do lote formam hoje, ou nulo. */
    public function montar(object $l): ?string
    {
        return $this->bairros->montadaAgora($l);
    }

    /**
     * Grava id => inscrição. Uma atualização por valor distinto não existe
     * (cada lote tem a sua), então vai em lotes de CASE para não fazer mil
     * idas ao banco numa importação.
     *
     * @param  array<int,string>  $porId
     */
    public function persistir(array $porId): int
    {
        if (! $porId) {
            return 0;
        }

        $agora = now();
        foreach (array_chunk($porId, 500, true) as $lote) {
            $casos = '';
            $bind = [];
            foreach ($lote as $id => $inscricao) {
                $casos .= ' WHEN ? THEN ?';
                $bind[] = $id;
                $bind[] = $inscricao;
            }
            $ids = array_keys($lote);
            DB::update(
                'UPDATE lotes SET inscricao_montada = CASE id' . $casos . ' END, inscricao_montada_em = ?'
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                [...$bind, $agora, ...$ids]
            );
        }

        return count($porId);
    }

    /**
     * Religa o bairro cujo `nome_gis` não liga lote nenhum, mas cujo nome
     * ANTERIOR na trilha de auditoria ainda é o de lotes existentes. O nome
     * novo — a intenção de quem editou, quase sempre encurtar o rótulo do
     * mapa — vira o apelido. A troca entra na trilha como qualquer outra.
     *
     * @return array<int,string> descrições do que foi religado
     */
    public function repararAmarracoes(): array
    {
        // Nomes de bairro que os lotes de fato guardam, pela forma comparável
        // (sem caixa nem acento — o que a colação do banco já fazia).
        $feitos = [];
        $dosLotes = [];
        foreach (DB::table('lotes')->distinct()->pluck('bairro') as $nome) {
            if ($nome !== null && $nome !== '') {
                $dosLotes[BairrosDoDesenho::chave($nome)] = $nome;
            }
        }
        $amarrados = DB::table('cadastro_bairros')->whereNotNull('nome_gis')->pluck('nome_gis')
            ->map(fn ($n) => BairrosDoDesenho::chave($n))->flip();

        foreach (CadastroBairro::whereNotNull('nome_gis')->get() as $b) {
            if (isset($dosLotes[BairrosDoDesenho::chave($b->nome_gis)])) {
                continue;   // amarração viva
            }

            $trilha = DB::table('auditoria')
                ->where('tabela', 'cadastro_bairros')->where('registro_id', $b->id)
                ->orderByDesc('id')->pluck('dados_anteriores');

            foreach ($trilha as $json) {
                $antes = json_decode((string) $json, true)['nome_gis'] ?? null;
                $chave = BairrosDoDesenho::chave($antes);
                if ($antes === null || ! isset($dosLotes[$chave]) || isset($amarrados[$chave])) {
                    continue;
                }

                $b->update([
                    'apelido'  => $b->apelido ?: $b->nome_gis,
                    'nome_gis' => $dosLotes[$chave],
                ]);
                $amarrados[$chave] = true;
                $feitos[] = "{$b->codigo}: \"{$b->apelido}\" → \"{$b->nome_gis}\"";
                break;
            }
        }

        return $feitos;
    }
}
