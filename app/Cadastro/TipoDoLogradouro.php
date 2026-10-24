<?php

namespace App\Cadastro;

use App\Models\CadastroBairro;
use App\Models\Logradouro;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * O TIPO DO LOGRADOURO que o cadastro municipal manda sem ele (23/10/2026).
 *
 * Parte das linhas da exportação vem só com o nome ("CUIABÁ"), sem "Rua",
 * "Avenida"… A lista de Ruas e bairros (Parâmetros) tem o nome inteiro; o
 * cruzamento acha a rua pelo nome sem o tipo e devolve "AV. CUIABÁ".
 *
 * NADA É GRAVADO no cadastro importado: a completação é feita NA LEITURA, na
 * ficha do BCI e nos nomes de rua do mapa. Gravar mexeria na comparação da
 * carga mensal (hash e histórico) e a carga seguinte desfaria. Quando o
 * sistema da prefeitura passar a mandar o tipo, `completar` cai sempre no
 * "já tem tipo" e só abrevia — esta classe pode então sair inteira, com as
 * chamadas a ela.
 *
 * Sem chute: dois candidatos ("R. CUIABÁ" e "AV. CUIABÁ") desempatam pelo
 * bairro do imóvel (os bairros ligados à rua); sem desempate, o nome fica
 * como veio e aparece nas pendências da aba Ruas e bairros.
 */
final class TipoDoLogradouro
{
    /** O tipo por extenso (ou já abreviado) → a abreviação. A mesma do mapa (bairros-contorno.js). */
    private const ABREVIACOES = [
        'AVENIDA' => 'AV.', 'AV' => 'AV.', 'RUA' => 'R.', 'R' => 'R.',
        'TRAVESSA' => 'TV.', 'TRAV' => 'TV.', 'TV' => 'TV.', 'ALAMEDA' => 'ALAM.', 'ALAM' => 'ALAM.', 'AL' => 'ALAM.',
        'RODOVIA' => 'ROD.', 'ROD' => 'ROD.', 'ESTRADA' => 'EST.', 'EST' => 'EST.',
        'PRACA' => 'PÇ.', 'PC' => 'PÇ.', 'PCA' => 'PÇ.',
    ];

    /** Tipos que não se abreviam, mas contam como "já tem tipo". */
    private const OUTROS = ['VIELA', 'BECO', 'LARGO', 'ACESSO', 'MARGINAL', 'PASSAGEM', 'VIADUTO', 'CONTORNO', 'ANEL', 'CORREDOR'];

    private const CACHE = 'tipo-do-logradouro:indice:3';   // :3 — com os códigos e os nomes da aba Bairros

    /** @var array{ruas: array<string, list<array{nome:string, bairros:list<string>}>>, bairros: array<string,string>}|null */
    private static ?array $indice = null;

    /**
     * O logradouro com o tipo, abreviado. Para desempatar, o bairro do imóvel:
     * de preferência pelo CÓDIGO do cadastro, que amarra à aba Bairros sem
     * depender da grafia; senão pelo nome (do cadastro, do desenho ou apelido).
     * O nome que o cadastro escreve nem sempre é o da aba ("JARDIM EUROPA I"
     * no cadastro, "JARDIM EUROPA" na aba) — por isso o código vem primeiro.
     */
    public static function completar(?string $logradouro, ?string $bairro = null, ?string $codigoBairro = null): ?string
    {
        $nome = Logradouro::normalizar($logradouro);
        if ($nome === '') {
            return $logradouro;
        }
        if (self::tipo($nome) !== null) {
            return self::abreviar($nome);
        }

        return self::candidato($nome, $bairro, $codigoBairro) ?? $nome;
    }

    /** O nome já começa com RUA, AV., TRAVESSA…? */
    public static function temTipo(?string $nome): bool
    {
        return self::tipo(Logradouro::normalizar($nome)) !== null;
    }

    /** O nome sem o tipo na frente ("AV. CUIABÁ" → "CUIABÁ"), para a busca achar o que veio sem ele. */
    public static function semTipo(?string $nome): string
    {
        $nome = Logradouro::normalizar($nome);
        $tipo = self::tipo($nome);

        return $tipo === null ? $nome : trim(mb_substr($nome, mb_strlen($tipo)));
    }

    /**
     * O que o cruzamento não resolve, de uma lista de [logradouro, bairro,
     * código do bairro, imóveis]: nome sem tipo e sem rua que case, ou com
     * mais de uma e sem desempate.
     *
     * `bairros` é como o cadastro escreve; `bairros_aba`, os mesmos bairros
     * com o nome da aba Bairros (achados pelo código, senão pelo nome) — é
     * com eles que o "Cadastrar" da tela já liga a rua nova.
     *
     * @param  iterable<object{logradouro:?string, bairro:?string, codigo?:?string, n:int}>  $linhas
     * @return list<array{nome:string, imoveis:int, bairros:list<string>, bairros_aba:list<string>, candidatos:list<string>}>
     */
    public static function pendencias(iterable $linhas): array
    {
        $saida = [];
        foreach ($linhas as $l) {
            $nome = Logradouro::normalizar($l->logradouro);
            if ($nome === '' || self::tipo($nome) !== null || self::candidato($nome, $l->bairro, $l->codigo ?? null) !== null) {
                continue;
            }
            $p = &$saida[$nome];
            $p ??= ['nome' => $nome, 'imoveis' => 0, 'bairros' => [], 'bairros_aba' => [],
                'candidatos' => array_column(self::indice()['ruas'][self::chave($nome)] ?? [], 'nome')];
            $p['imoveis'] += (int) $l->n;
            if ($l->bairro && ! in_array($l->bairro, $p['bairros'], true)) {
                $p['bairros'][] = $l->bairro;
            }
            $aba = self::nomeNaAba($l->bairro, $l->codigo ?? null);
            if ($aba !== null && ! in_array($aba, $p['bairros_aba'], true)) {
                $p['bairros_aba'][] = $aba;
            }
            unset($p);
        }
        usort($saida, fn ($a, $b) => $b['imoveis'] <=> $a['imoveis'] ?: strcmp($a['nome'], $b['nome']));

        return array_values($saida);
    }

    /** A lista de ruas ou de bairros mudou: o próximo pedido refaz o índice. */
    public static function esquecer(): void
    {
        Cache::forget(self::CACHE);
        self::$indice = null;
    }

    // ── por dentro ──────────────────────────────────────────────

    /** O nome do bairro na aba Bairros (maiúsculas), pelo código e, sem ele, pelo nome; null se não achar. */
    private static function nomeNaAba(?string $bairro, ?string $codigo): ?string
    {
        $i = self::indice();
        $chave = ($codigo !== null ? $i['codigos'][ltrim(trim($codigo), '0')] ?? null : null)
            ?? ($bairro ? $i['bairros'][self::chave($bairro)] ?? null : null);

        return $chave !== null ? $i['oficiais'][$chave] ?? null : null;
    }

    private static function candidato(string $nome, ?string $bairro, ?string $codigoBairro = null): ?string
    {
        $cands = self::indice()['ruas'][self::chave($nome)] ?? [];
        if (count($cands) > 1) {
            $b = ($codigoBairro !== null ? self::indice()['codigos'][ltrim(trim($codigoBairro), '0')] ?? null : null)
                ?? ($bairro ? self::indice()['bairros'][self::chave($bairro)] ?? self::chave($bairro) : null);
            if ($b === null) {
                return null;
            }
            $cands = array_values(array_filter($cands, fn ($c) => in_array($b, $c['bairros'], true)));
        }

        return count($cands) === 1 ? $cands[0]['nome'] : null;
    }

    /** O tipo na frente do nome, como está escrito ("AVENIDA", "AV."), ou null. */
    private static function tipo(string $nome): ?string
    {
        if (! preg_match('/^([^\s.]+)(\.?)(\s|$)/u', $nome, $m)) {
            return null;
        }
        $palavra = Str::upper(Str::ascii($m[1]));
        if (isset(self::ABREVIACOES[$palavra]) || in_array($palavra, self::OUTROS, true)) {
            return $m[1] . $m[2];
        }

        return null;
    }

    private static function abreviar(string $nome): string
    {
        $tipo = self::tipo($nome);
        $ab = self::ABREVIACOES[Str::upper(Str::ascii(rtrim((string) $tipo, '.')))] ?? null;

        return $tipo === null || $ab === null ? $nome : $ab . mb_substr($nome, mb_strlen($tipo));
    }

    /** O nome sem acento, pontuação nem caixa — a chave de comparação. */
    private static function chave(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', Str::upper(Str::ascii($s)))));
    }

    /**
     * Ruas por nome sem tipo, com os bairros ligados; cada nome de bairro
     * (cadastro, desenho, apelido) e cada CÓDIGO apontando para o nome do
     * cadastro, que é o que a ligação rua↔bairro guarda.
     *
     * A mesma rua cadastrada duas vezes ("AV CARNAÚBA" e "AV. CARNAÚBA", em
     * geral a segunda criada por uma denúncia) é UMA candidata só, com os
     * bairros das duas: antes contava como duas ruas e nada se completava.
     */
    private static function indice(): array
    {
        return self::$indice ??= Cache::remember(self::CACHE, now()->addHours(12), function () {
            $bairros = $codigos = $oficiais = [];
            foreach (CadastroBairro::whereNotNull('nome_cadastro')->get(['codigo', 'nome_cadastro', 'nome_gis', 'apelido']) as $b) {
                $oficial = self::chave($b->nome_cadastro);
                $oficiais[$oficial] = Logradouro::normalizar($b->nome_cadastro);
                foreach ([$b->nome_cadastro, $b->nome_gis, $b->apelido] as $n) {
                    if ($n) { $bairros[self::chave($n)] = $oficial; }
                }
                $codigos[ltrim(trim((string) $b->codigo), '0')] = $oficial;
            }
            $ruas = [];
            foreach (Logradouro::with('vinculos')->get() as $l) {
                if (self::tipo($l->nome) === null) {
                    continue;   // rua da lista sem tipo não completa nada
                }
                $nome = self::abreviar($l->nome);
                $c = &$ruas[self::chave(self::semTipo($l->nome))][self::chave($nome)];
                // Das grafias da mesma rua, fica a acentuada ("CARNAÚBA", e não "CARNAUBA").
                $c ??= ['nome' => $nome, 'bairros' => []];
                if (strlen($nome) > strlen($c['nome'])) { $c['nome'] = $nome; }
                foreach ($l->vinculos as $v) {
                    $c['bairros'][] = $bairros[self::chave($v->bairro)] ?? self::chave($v->bairro);
                }
                $c['bairros'] = array_values(array_unique($c['bairros']));
                unset($c);
            }

            return ['ruas' => array_map('array_values', $ruas), 'bairros' => $bairros, 'codigos' => $codigos, 'oficiais' => $oficiais];
        });
    }
}
