<?php

namespace App\Cadastro;

use App\Models\CadastroBairro;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ligar o bairro de uma IMPORTAÇÃO a um bairro do cadastro da prefeitura.
 *
 * É a mesma amarração da aba Bairros em Parâmetros (`cadastro_bairros.nome_gis`
 * recebe o nome do desenho — ver BairrosDoDesenho), feita onde ela faz falta:
 * sem ela a importação não monta inscrição e não confere com o cadastro.
 *
 * ── Por que aqui ela é mais estreita que em Parâmetros ──
 *
 * Em Parâmetros o administrador troca qualquer ligação e é avisado dos lotes
 * que ficaram para trás. Aqui o curador só liga o que NÃO MUDA a inscrição de
 * lote publicado:
 *
 *   - o nome do desenho que já tem lotes FORA da importação, ligado a outro
 *     bairro, não é religado — isso trocaria a inscrição deles;
 *   - o bairro do cadastro que já está ligado a outro nome COM lotes não é
 *     tomado — os lotes daquele nome perderiam o código.
 *
 * Ligação a nome sem lote nenhum (sobra de rascunho descartado, digitação em
 * Parâmetros) pode ser substituída: não há o que perder.
 */
class VinculoDoBairro
{
    /** Palavras que todo loteamento tem e que não distinguem um do outro. */
    private const GENERICAS = ['residencial', 'res', 'jardim', 'jd', 'loteamento', 'lot', 'condominio', 'cond',
        'parque', 'conjunto', 'bairro', 'de', 'da', 'das', 'do', 'dos', 'e', 'expansao', 'prime'];

    private const ROMANOS = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];

    /**
     * A ligação de hoje, as sugestões e a lista inteira para escolher.
     *
     * @return array{atual: ?array, sugestoes: list<int>, bairros: list<array>, lotes_fora: int}
     */
    public function situacao(string $nome, ?int $importacaoId = null): array
    {
        $chave = BairrosDoDesenho::chave($nome);
        $emUso = DB::table('lotes')->where('situacao', 'ativo')->whereNotNull('bairro')
            ->groupBy('bairro')->selectRaw('bairro, COUNT(*) n')->pluck('n', 'bairro')
            ->mapWithKeys(fn ($n, $b) => [BairrosDoDesenho::chave($b) => (int) $n]);

        $bairros = CadastroBairro::orderByRaw('CAST(codigo AS UNSIGNED)')->get()->map(function ($b) use ($chave, $emUso) {
            $outro = $b->nome_gis && BairrosDoDesenho::chave($b->nome_gis) !== $chave ? $b->nome_gis : null;

            return [
                'id'      => $b->id,
                'codigo'  => $b->codigo,
                'nome'    => $b->rotulo(),
                'ligado'  => $b->nome_gis !== null && ! $outro,
                // Ligado a OUTRO nome do desenho, e quantos lotes perderiam o código.
                'ocupado' => $outro ? ['nome' => $outro, 'lotes' => $emUso[BairrosDoDesenho::chave($outro)] ?? 0] : null,
            ];
        })->values();

        $atual = $bairros->firstWhere('ligado', true);

        return [
            'atual'      => $atual,
            'sugestoes'  => $atual ? [] : $this->sugerir($nome, $bairros->all()),
            'bairros'    => $bairros->all(),
            'lotes_fora' => $this->lotesFora($nome, $importacaoId),
        ];
    }

    /**
     * Liga `$nome` ao bairro do cadastro. A gravação é pelo modelo, e por isso
     * fica na trilha (CadastroBairro usa RegistraAuditoria).
     */
    public function vincular(string $nome, int $cadastroBairroId, ?int $importacaoId = null): CadastroBairro
    {
        $alvo = CadastroBairro::find($cadastroBairroId)
            ?? throw new RuntimeException('Bairro do cadastro não encontrado.');
        $chave = BairrosDoDesenho::chave($nome);

        if ($alvo->nome_gis && BairrosDoDesenho::chave($alvo->nome_gis) === $chave) {
            return $alvo;   // já é esta a ligação
        }

        if ($alvo->nome_gis && ($n = $alvo->lotesEmUso()) > 0) {
            throw new RuntimeException("{$alvo->codigo} · {$alvo->rotulo()} já está ligado a \"{$alvo->nome_gis}\", "
                . "que tem {$n} lote(s). Escolha outro bairro, ou peça ao administrador para refazer a ligação em Parâmetros → Bairros.");
        }

        $antigos = CadastroBairro::whereNotNull('nome_gis')->get()
            ->filter(fn ($b) => BairrosDoDesenho::chave($b->nome_gis) === $chave);
        if ($antigos->isNotEmpty() && ($fora = $this->lotesFora($nome, $importacaoId)) > 0) {
            $a = $antigos->first();
            throw new RuntimeException("\"{$nome}\" já tem {$fora} lote(s) fora desta importação, ligados a "
                . "{$a->codigo} · {$a->rotulo()}. Trocar mudaria a inscrição deles — isso é feito pelo administrador em Parâmetros → Bairros.");
        }

        return DB::transaction(function () use ($alvo, $antigos, $nome) {
            // Primeiro solta a ligação antiga: `nome_gis` é único.
            foreach ($antigos as $b) {
                $b->update(['nome_gis' => null]);
            }
            $alvo->update(['nome_gis' => $nome]);

            return $alvo;
        });
    }

    /** Lotes ativos com este nome que NÃO são da importação. */
    private function lotesFora(string $nome, ?int $importacaoId): int
    {
        return DB::table('lotes')->where('bairro', $nome)->where('situacao', 'ativo')
            ->where(fn ($q) => $q->whereNull('importacao_id')
                ->when($importacaoId, fn ($w) => $w->orWhere('importacao_id', '<>', $importacaoId)))
            ->count();
    }

    /**
     * Os candidatos mais parecidos, pelo nome — só SUGESTÃO, ninguém é ligado
     * por semelhança (ver a migração dos bairros do município).
     *
     * Conta as palavras que distinguem o loteamento ("buritis", "europa") e
     * exige a mesma etapa: "Buritis I" e "Buritis II" são vizinhos de nome e
     * bairros diferentes. Nome sem numeral é a etapa I.
     *
     * @param  list<array>  $bairros
     * @return list<int> ids, do mais parecido ao menos
     */
    private function sugerir(string $nome, array $bairros): array
    {
        [$palavras, $etapa] = $this->partes($nome);
        if (! $palavras) {
            return [];
        }

        $notas = [];
        foreach ($bairros as $b) {
            [$p, $e] = $this->partes($b['nome']);
            $comuns = count(array_intersect($palavras, $p));
            if ($comuns === 0 || $e !== $etapa) {
                continue;
            }
            // Mais palavras em comum, menos palavras sobrando.
            $notas[$b['id']] = $comuns * 10 - count(array_diff($p, $palavras));
        }
        arsort($notas);

        return array_slice(array_keys($notas), 0, 5);
    }

    /** @return array{0: list<string>, 1: string} palavras distintivas e a etapa (numeral romano) */
    private function partes(string $nome): array
    {
        $t = preg_replace('/[^a-z0-9 ]+/', ' ', BairrosDoDesenho::chave($nome));
        $palavras = [];
        $etapa = 'i';
        foreach (preg_split('/\s+/', trim($t)) as $w) {
            if ($w === '') { continue; }
            if (ctype_digit($w) && (int) $w >= 1 && (int) $w <= 10) { $w = self::ROMANOS[(int) $w - 1]; }
            if (in_array($w, self::ROMANOS, true)) { $etapa = $w; continue; }
            if (! in_array($w, self::GENERICAS, true)) { $palavras[] = $w; }
        }

        return [array_values(array_unique($palavras)), $etapa];
    }
}
