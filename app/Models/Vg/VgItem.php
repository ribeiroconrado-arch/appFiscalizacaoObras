<?php

namespace App\Models\Vg;

use App\Models\Artigo;

/**
 * OS TIPOS DE ITEM de um modelo de vistoria guiada — a regra única.
 *
 * O motor continua sem campo fixo: o que é próprio da fiscalização de obra
 * (área aferida, alvará, fase, uso, ano) vira item do MODELO de Obras, com um
 * destes tipos. Quem confere, grava, imprime e (depois) lavra lê daqui, para
 * que a tela, o servidor e o relatório não divirjam sobre o que é uma
 * resposta válida.
 *
 *   confere — Confere / Não confere / Não se aplica. No "Não confere" o
 *             fiscal descreve, pode citar artigos e escrever a exigência
 *             com prazo: é o que a notificação e o auto puxam.
 *   numero  — valor com unidade (m², m, un…); opcionalmente pede o MÉTODO
 *             da medição, que vai impresso junto do número. Um item numérico
 *             por modelo pode ser a ÁREA DA MULTA (papel = area).
 *   opcoes  — uma escolha entre opções escritas no modelo.
 *   data    — uma data.
 *   texto   — resposta escrita.
 *
 * Resposta gravada em `vg_vistorias.respostas[<item>]`:
 *   { v, obs, m?, art?: [ids], exig?, prazo? }
 */
class VgItem
{
    public const TIPOS = [
        'confere' => 'Confere / Não confere',
        'numero'  => 'Número',
        'opcoes'  => 'Escolha',
        'data'    => 'Data',
        'texto'   => 'Texto',
    ];

    public const RESP = ['sim' => 'Confere', 'nao' => 'Não confere', 'na' => 'Não se aplica'];

    /** Como o número foi obtido. Vai IMPRESSO junto do valor, sempre. */
    public const METODOS = [
        'trena'      => 'Medida com trena',
        'estimativa' => 'Estimativa visual',
        'projeto'    => 'Conforme projeto apresentado',
        'croqui'     => 'Calculada pelo croqui',
    ];

    public const MAX_ARTIGOS = 10;
    public const PRAZO_MAX = 365;

    public static function tipo(array $i): string
    {
        return isset(self::TIPOS[$i['tipo'] ?? '']) ? $i['tipo'] : 'confere';
    }

    /** Normaliza a definição de um item (já validada no formato). */
    public static function normalizar(array $i): array
    {
        $t = self::tipo($i);
        $out = ['id' => $i['id'], 'rotulo' => trim($i['rotulo'] ?? ''), 'obrig' => (bool) ($i['obrig'] ?? true), 'tipo' => $t];
        if ($t === 'numero') {
            $out['unidade'] = mb_substr(trim($i['unidade'] ?? ''), 0, 12);
            $out['metodo'] = (bool) ($i['metodo'] ?? false);
            $out['papel'] = ($i['papel'] ?? '') === 'area' ? 'area' : '';
        }
        if ($t === 'opcoes') {
            $out['opcoes'] = array_values(array_unique(array_filter(array_map(
                fn ($o) => mb_substr(trim((string) $o), 0, 80), $i['opcoes'] ?? []), 'strlen')));
        }
        return $out;
    }

    /** Regras do item para publicar — o rascunho aceita incompleto. */
    public static function errosAoPublicar(array $i, string $onde): array
    {
        $e = [];
        if ($i['tipo'] === 'opcoes' && count($i['opcoes'] ?? []) < 2) {
            $e[] = "{$onde}: o item \"{$i['rotulo']}\" precisa de ao menos duas opções.";
        }
        return $e;
    }

    /** Limpa a resposta de um item; null quando não sobra nada. */
    public static function limpar(array $i, mixed $x, array $artigosValidos): ?array
    {
        if (! is_array($x)) { return null; }
        $obs = is_string($x['obs'] ?? null) ? mb_substr($x['obs'], 0, 2000) : null;
        $out = ['v' => null, 'obs' => $obs];

        switch ($i['tipo']) {
            case 'confere':
                $out['v'] = in_array($x['v'] ?? null, ['sim', 'nao', 'na'], true) ? $x['v'] : null;
                if ($out['v'] === 'nao') {
                    $art = array_values(array_unique(array_filter(array_map('intval', (array) ($x['art'] ?? [])),
                        fn ($id) => isset($artigosValidos[$id]))));
                    $out['art'] = array_slice($art, 0, self::MAX_ARTIGOS);
                    $out['exig'] = is_string($x['exig'] ?? null) ? (mb_substr(trim($x['exig']), 0, 2000) ?: null) : null;
                    $p = is_numeric($x['prazo'] ?? null) ? (int) $x['prazo'] : null;
                    $out['prazo'] = $p !== null && $p >= 1 && $p <= self::PRAZO_MAX ? $p : null;
                }
                break;
            case 'numero':
                $n = self::numero($x['v'] ?? null);
                $out['v'] = $n;
                if ($i['metodo'] ?? false) {
                    $out['m'] = isset(self::METODOS[$x['m'] ?? '']) ? $x['m'] : null;
                }
                break;
            case 'opcoes':
                $out['v'] = in_array($x['v'] ?? null, $i['opcoes'] ?? [], true) ? $x['v'] : null;
                break;
            case 'data':
                $d = is_string($x['v'] ?? null) ? $x['v'] : '';
                $out['v'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : null;
                break;
            case 'texto':
                $out['v'] = is_string($x['v'] ?? null) ? (mb_substr(trim($x['v']), 0, 2000) ?: null) : null;
                break;
        }

        $vazio = $out['v'] === null && ($out['obs'] === null || $out['obs'] === '');
        return $vazio ? null : array_filter($out, fn ($v) => $v !== null && $v !== []) + ['v' => null];
    }

    /** Número vindo da tela: aceita 1.234,56 e 1234.56; nunca negativo. */
    public static function numero(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) { $n = (float) $v; }
        elseif (is_string($v) && trim($v) !== '') {
            $s = trim($v);
            if (str_contains($s, ',')) { $s = str_replace(['.', ','], ['', '.'], $s); }
            if (! is_numeric($s)) { return null; }
            $n = (float) $s;
        } else { return null; }
        return $n >= 0 && $n < 1e9 ? round($n, 2) : null;
    }

    /** O que falta neste item — a mesma regra de `pendItem` em vistorias-guiadas.js. */
    public static function pendencias(array $i, ?array $x, bool $naoFoto, bool $temFoto): array
    {
        $x ??= [];
        $f = [];
        $tem = isset($x['v']) && $x['v'] !== null && $x['v'] !== '';
        if ($i['obrig'] && ! $tem) { $f[] = $i['rotulo']; }
        if ($i['tipo'] === 'confere' && ($x['v'] ?? null) === 'nao') {
            if (trim($x['obs'] ?? '') === '') { $f[] = 'descrição de "' . $i['rotulo'] . '"'; }
            if ($naoFoto && ! $temFoto) { $f[] = 'foto de "' . $i['rotulo'] . '"'; }
            if (! empty($x['exig']) && empty($x['prazo'])) { $f[] = 'prazo da exigência de "' . $i['rotulo'] . '"'; }
        }
        if ($i['tipo'] === 'numero' && $tem && ($i['metodo'] ?? false) && empty($x['m'])) {
            $f[] = 'como foi medido "' . $i['rotulo'] . '"';
        }
        return $f;
    }

    /** "88,50 m² (medida com trena)" — o número nunca anda sem o método. */
    public static function textoResposta(array $i, ?array $x): ?string
    {
        if (! $x || ! isset($x['v']) || $x['v'] === null || $x['v'] === '') { return null; }
        return match ($i['tipo']) {
            'confere' => self::RESP[$x['v']] ?? null,
            'numero'  => self::fmtNumero((float) $x['v']) . (($i['unidade'] ?? '') !== '' ? ' ' . $i['unidade'] : '')
                . (! empty($x['m']) ? ' (' . mb_strtolower(self::METODOS[$x['m']]) . ')' : ''),
            'data'    => \Illuminate\Support\Carbon::parse($x['v'])->format('d/m/Y'),
            default   => (string) $x['v'],
        };
    }

    public static function fmtNumero(float $n): string
    {
        $s = number_format($n, 2, ',', '.');
        return str_ends_with($s, ',00') ? substr($s, 0, -3) : $s;
    }

    /** Todos os itens do roteiro, com a etapa de cada um. */
    public static function itensDe(array $def): \Illuminate\Support\Collection
    {
        return collect($def['etapas'] ?? [])->flatMap(fn ($e) => array_map(
            fn ($i) => self::normalizar($i) + ['etapa' => $e['id'], 'etapa_titulo' => $e['titulo']], $e['itens']));
    }

    /** Artigos citados em todas as respostas — ids. */
    public static function artigosCitados(array $respostas): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($x) => (array) ($x['art'] ?? []), array_values($respostas) ?: [[]]))));
    }

    /** id → dados do artigo para a tela e o relatório. */
    public static function rotulosArtigos(array $ids): array
    {
        if (! $ids) { return []; }
        return Artigo::with('legislacao:id,numero')->whereIn('id', $ids)->get()->mapWithKeys(fn (Artigo $a) => [$a->id => [
            'id' => $a->id, 'numero' => $a->numero, 'rotulo' => $a->rotulo(), 'conduta' => $a->conduta,
            'lei' => $a->legislacao?->numero,
            'por_m2' => in_array($a->base_multa, Artigo::BASES_POR_AREA, true) && $a->multa_area !== 'terreno',
        ]])->all();
    }
}
