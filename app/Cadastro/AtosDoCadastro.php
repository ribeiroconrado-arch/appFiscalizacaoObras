<?php

namespace App\Cadastro;

use App\Services\TrocaDeIdentidades;
use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;

/**
 * OS ATOS DO CADASTRO (20/11/2026) — a tela do ato e a tela "Histórico".
 *
 * Um ato é o que está em `lote_atos`: desmembramento, unificação ou fração
 * ideal, com quem participou (`lote_ato_lotes`). A troca de identidade não é
 * ato novo: é uma correção do desmembramento, gravada na trilha
 * (`auditoria`, ação TrocaDeIdentidades::ACAO, uma linha por parte com o id
 * do ato) — aqui ela é agrupada de volta numa linha só.
 *
 * Ato desfeito sai de `lote_atos` (DesfazerAlteracao): não aparece aqui; a
 * trilha do lote continua contando.
 */
final class AtosDoCadastro
{
    public const TIPOS = ['desmembramento' => 'Desmembramento', 'unificacao' => 'Unificação', 'fracao_ideal' => 'Fração ideal'];

    private ?BairrosDoDesenho $bairros = null;

    private function bairroOficial(?string $doDesenho): ?string
    {
        return ($this->bairros ??= new BairrosDoDesenho())->oficial($doDesenho) ?? $doDesenho;
    }

    private static function inscricao(?string $informada, ?string $montada): ?string
    {
        $d = InscricaoImobiliaria::normalizar($informada) ?? InscricaoImobiliaria::normalizar($montada);

        return $d ? InscricaoImobiliaria::formatar($d) : null;
    }

    /**
     * O número como a tela mostra. A letra (4A) já vem no número do lote; o
     * sufixo (`desmembramento`) é o da inscrição, e não entra aqui.
     */
    public static function numeroComLetra(?string $numero, ?int $sufixo = null): string
    {
        return $numero ?? '—';
    }

    /** O ato do lote como parte (posterior); senão, como mãe (anterior). */
    public function atoDoLote(int $loteId): ?int
    {
        foreach (['posterior', 'anterior', 'terreno'] as $papel) {
            $id = DB::table('lote_ato_lotes')->where('lote_id', $loteId)->where('papel', $papel)->max('ato_id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Tudo o que a tela do ato mostra: o ato, os lotes com o desenho, e o
     * histórico (o ato aplicado e cada troca de identidade).
     *
     * @return array<string,mixed>|null
     */
    public function ato(int $atoId): ?array
    {
        $a = DB::table('lote_atos as at')->leftJoin('users as u', 'u.id', '=', 'at.user_id')
            ->leftJoin('protocolos as p', 'p.id', '=', 'at.protocolo_id')
            ->where('at.id', $atoId)
            ->first(['at.*', 'u.name as usuario', 'p.numero as protocolo']);
        if (! $a) {
            return null;
        }

        $lotes = DB::table('lote_ato_lotes as al')->join('lotes as l', 'l.id', '=', 'al.lote_id')
            ->where('al.ato_id', $atoId)
            ->orderBy('l.numero_lote')->orderBy('l.desmembramento')->orderBy('l.id')
            ->selectRaw('al.papel, al.area_m2 AS area_no_ato, l.id, l.bairro, l.quadra, l.numero_lote, l.desmembramento,
                         l.inscricao_imobiliaria, l.inscricao_montada, l.situacao, l.area_gis_m2,
                         ST_AsGeoJSON(l.geom, 7) AS geojson')
            ->get();

        $lote = fn ($l) => [
            'id'          => (int) $l->id,
            'numero'      => self::numeroComLetra($l->numero_lote, (int) $l->desmembramento),
            'quadra'      => $l->quadra,
            'inscricao'   => self::inscricao($l->inscricao_imobiliaria, $l->inscricao_montada),
            'situacao'    => $l->situacao,
            'area'        => round((float) $l->area_gis_m2, 2),
            'area_no_ato' => $l->area_no_ato !== null ? round((float) $l->area_no_ato, 2) : null,
            'sufixo'      => (int) $l->desmembramento,
            'geometria'   => json_decode((string) $l->geojson, true),
            'vinculos'    => [
                'documentos' => DB::table('documentos')->where('lote_id', $l->id)->count(),
                'vistorias'  => DB::table('vistorias')->where('lote_id', $l->id)->count(),
                'obras'      => DB::table('obras')->where('lote_id', $l->id)->count(),
            ],
        ];

        $primeiro = $lotes->first();
        $saida = [
            'id'         => (int) $a->id,
            'tipo'       => $a->tipo,
            'rotulo'     => self::TIPOS[$a->tipo] ?? $a->tipo,
            'data'       => $a->created_at ? date('d/m/Y', strtotime($a->created_at)) : null,
            'hora'       => $a->created_at ? date('H:i', strtotime($a->created_at)) : null,
            'usuario'    => $a->usuario,
            'protocolo'  => $a->protocolo,
            'direto'     => $a->protocolo_id === null,
            'observacao' => $a->observacao,
            'bairro'     => $this->bairroOficial($primeiro?->bairro),
            'quadra'     => $primeiro?->quadra,
            'anteriores' => $lotes->where('papel', 'anterior')->map($lote)->values()->all(),
            'terreno'    => $lotes->where('papel', 'terreno')->map($lote)->values()->all(),
            'posteriores' => $lotes->where('papel', 'posterior')->map($lote)->values()->all(),
            'historico'  => $this->historicoDoAto($a),
        ];

        // A troca só vale para as partes ativas de um desmembramento (TrocaDeIdentidades).
        if ($a->tipo === 'desmembramento') {
            $ativas = array_filter($saida['posteriores'], fn ($p) => $p['situacao'] === 'ativo');
            $quadras = array_unique(array_map(fn ($p) => $p['quadra'], $ativas));
            $saida['troca'] = [
                'bloqueio' => count($ativas) < 2 ? 'O ato tem menos de duas partes ativas: não há o que trocar.'
                    : (count($quadras) > 1 ? 'As partes não são da mesma quadra.' : null),
            ];
        }

        return $saida;
    }

    /** "4A ↔ 4B" ou "4A → 4B, 4B → 4C, 4C → 4A". */
    public static function textoDasTrocas(array $trocas): string
    {
        return implode(', ', array_map(fn ($x) => $x['de'] . ($x['mutua'] ? ' ↔ ' : ' → ') . $x['para'], $trocas));
    }

    /** O ato aplicado e as trocas de identidade, do mais recente ao mais antigo. */
    private function historicoDoAto(object $a): array
    {
        $itens = [];
        foreach ($this->trocas([(int) $a->id]) as $t) {
            $itens[] = [
                'tipo'    => 'troca',
                'titulo'  => 'Identidades trocadas: ' . self::textoDasTrocas($t['trocas']),
                'quando'  => $t['quando'],
                'usuario' => $t['usuario'],
                'motivo'  => $t['motivo'],
            ];
        }
        $anteriores = DB::table('lote_ato_lotes as al')->join('lotes as l', 'l.id', '=', 'al.lote_id')
            ->where('al.ato_id', $a->id)->get(['al.papel', 'l.numero_lote', 'l.desmembramento'])
            ->sort(fn ($x, $y) => strnatcmp((string) $x->numero_lote, (string) $y->numero_lote));
        $nums = fn (string $papel) => $anteriores->where('papel', $papel)
            ->map(fn ($l) => self::numeroComLetra($l->numero_lote, (int) $l->desmembramento))->implode(', ');
        $itens[] = [
            'tipo'    => 'ato',
            'titulo'  => (self::TIPOS[$a->tipo] ?? $a->tipo) . ' aplicado: lote ' . $nums('anterior') . ' → ' . $nums('posterior'),
            'quando'  => $a->created_at ? date('d/m/Y H:i', strtotime($a->created_at)) : null,
            'usuario' => $a->usuario,
            'motivo'  => $a->observacao,
        ];

        return $itens;
    }

    /**
     * As trocas de identidade dos atos, agrupadas: cada aplicação grava uma
     * linha por parte, no mesmo segundo e com o mesmo usuário.
     *
     * @param  list<int>|null  $atos  null = de todos
     * @return list<array{ato:int, quando:string, em:string, usuario:?string, motivo:?string, trocas:list<array{de:string,para:string}>}>
     */
    public function trocas(?array $atos = null, int $limite = 500): array
    {
        $q = DB::table('auditoria')->where('acao', TrocaDeIdentidades::ACAO)->where('tabela', 'lotes')
            ->orderByDesc('created_at')->orderBy('id')->limit($limite);
        if ($atos !== null) {
            $q->whereIn(DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(dados_novos, '$.ato')) AS UNSIGNED)"), $atos);
        }
        $grupos = [];
        foreach ($q->get() as $r) {
            $novo = json_decode((string) $r->dados_novos, true) ?: [];
            $velho = json_decode((string) $r->dados_anteriores, true) ?: [];
            $k = ($novo['ato'] ?? 0) . '|' . $r->created_at . '|' . $r->user_id;
            $grupos[$k] ??= [
                'ato'     => (int) ($novo['ato'] ?? 0),
                'quando'  => date('d/m/Y H:i', strtotime($r->created_at)),
                'em'      => $r->created_at,
                'usuario' => $r->usuario_nome,
                'motivo'  => $novo['motivo'] ?? null,
                'trocas'  => [],
            ];
            $de = self::numeroComLetra($velho['numero_lote'] ?? null, (int) ($velho['desmembramento'] ?? 0));
            $para = self::numeroComLetra($novo['numero_lote'] ?? null, (int) ($novo['desmembramento'] ?? 0));
            // A permutação de duas partes grava "4A → 4B" e "4B → 4A": vira "4A ↔ 4B".
            $par = array_search(['de' => $para, 'para' => $de, 'mutua' => false], $grupos[$k]['trocas'], true);
            if ($par !== false) {
                // Na ordem natural: "4A ↔ 4B", qualquer que seja a linha lida primeiro.
                $x = [$de, $para];
                usort($x, 'strnatcmp');
                $grupos[$k]['trocas'][$par] = ['de' => $x[0], 'para' => $x[1], 'mutua' => true];
            } else {
                $grupos[$k]['trocas'][] = ['de' => $de, 'para' => $para, 'mutua' => false];
            }
        }

        return array_values($grupos);
    }

    /**
     * A tela "Histórico": os atos e as trocas, do mais recente ao mais antigo.
     *
     * @param  ?string  $tipo   desmembramento | unificacao | fracao_ideal | troca
     * @param  ?string  $busca  bairro, quadra, lote ou inscrição
     * @return array{itens:list<array<string,mixed>>, mais:bool}
     */
    public function lista(?string $tipo, ?string $busca, int $pagina = 1, int $porPagina = 50): array
    {
        $atos = [];
        if ($tipo !== 'troca') {
            $q = DB::table('lote_atos as at')->leftJoin('users as u', 'u.id', '=', 'at.user_id')
                ->leftJoin('protocolos as p', 'p.id', '=', 'at.protocolo_id')
                ->orderByDesc('at.created_at')->orderByDesc('at.id');
            if ($tipo) {
                $q->where('at.tipo', $tipo);
            }
            $atos = $q->limit(2000)->get(['at.id', 'at.tipo', 'at.created_at', 'at.protocolo_id', 'at.observacao',
                'u.name as usuario', 'p.numero as protocolo'])->all();
        }

        $ids = array_map(fn ($a) => (int) $a->id, $atos);
        $trocas = ($tipo === null || $tipo === 'troca') ? $this->trocas() : [];
        $idsTodos = array_values(array_unique(array_merge($ids, array_map(fn ($t) => $t['ato'], $trocas))));

        // Quem participou de cada ato, numa consulta só.
        $partes = [];
        if ($idsTodos) {
            foreach (DB::table('lote_ato_lotes as al')->join('lotes as l', 'l.id', '=', 'al.lote_id')
                ->whereIn('al.ato_id', $idsTodos)
                ->orderBy('l.numero_lote')->orderBy('l.desmembramento')
                ->get(['al.ato_id', 'al.papel', 'l.id', 'l.bairro', 'l.quadra', 'l.numero_lote', 'l.desmembramento',
                    'l.inscricao_imobiliaria', 'l.inscricao_montada']) as $l) {
                $partes[$l->ato_id][] = $l;
            }
        }

        $resumo = function (int $atoId) use ($partes) {
            $ls = collect($partes[$atoId] ?? []);
            $num = fn (string $papel) => $ls->where('papel', $papel)
                ->map(fn ($l) => self::numeroComLetra($l->numero_lote, (int) $l->desmembramento))->values()->all();
            $p = $ls->first();

            return [
                'bairro'      => $this->bairroOficial($p?->bairro),
                'quadra'      => $p?->quadra,
                'anteriores'  => $num('anterior'),
                'terreno'     => $num('terreno'),
                'posteriores' => $num('posterior'),
                'lote_id'     => $p ? (int) $p->id : null,
                'texto'       => mb_strtolower(implode(' ', $ls->map(fn ($l) => implode(' ', [$l->bairro, $this->bairroOficial($l->bairro),
                    'q' . $l->quadra, 'quadra ' . $l->quadra, 'lote ' . $l->numero_lote, self::numeroComLetra($l->numero_lote, (int) $l->desmembramento),
                    InscricaoImobiliaria::normalizar($l->inscricao_imobiliaria) ?? InscricaoImobiliaria::normalizar($l->inscricao_montada) ?? '',
                    self::inscricao($l->inscricao_imobiliaria, $l->inscricao_montada) ?? '']))->all())),
            ];
        };

        $itens = [];
        foreach ($atos as $a) {
            $r = $resumo((int) $a->id);
            $itens[] = ['tipo' => $a->tipo, 'rotulo' => self::TIPOS[$a->tipo] ?? $a->tipo, 'ato' => (int) $a->id,
                'em' => $a->created_at, 'quando' => date('d/m/Y', strtotime($a->created_at)),
                'usuario' => $a->usuario, 'protocolo' => $a->protocolo, 'observacao' => $a->observacao] + $r;
        }
        foreach ($trocas as $t) {
            if (! isset($partes[$t['ato']])) {
                continue;   // ato desfeito depois da troca
            }
            $r = $resumo($t['ato']);
            $itens[] = ['tipo' => 'troca', 'rotulo' => 'Troca de identidade', 'ato' => $t['ato'],
                'em' => $t['em'], 'quando' => date('d/m/Y', strtotime($t['em'])),
                'usuario' => $t['usuario'], 'protocolo' => null, 'observacao' => $t['motivo'],
                'trocas' => $t['trocas']] + $r;
        }

        $busca = mb_strtolower(trim((string) $busca));
        if ($busca !== '') {
            $digitos = preg_replace('/\D/', '', $busca);
            $itens = array_values(array_filter($itens, fn ($i) => str_contains($i['texto'], $busca)
                || (strlen($digitos) >= 6 && str_contains($i['texto'], $digitos))));
        }
        usort($itens, fn ($x, $y) => strcmp($y['em'], $x['em']));

        $inicio = max(0, ($pagina - 1) * $porPagina);
        $pag = array_map(function ($i) { unset($i['texto'], $i['em']); return $i; }, array_slice($itens, $inicio, $porPagina));

        return ['itens' => $pag, 'mais' => count($itens) > $inicio + $porPagina];
    }
}
