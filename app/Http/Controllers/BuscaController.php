<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Lote;
use App\Models\Vistoria;
use App\Support\InscricaoImobiliaria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Consulta de imóveis SEM abrir o mapa.
 *
 * Razão de existir: a camada de satélite do mapa é serviço pago por
 * requisição. Quem só precisa conferir a situação de um lote — o que é a
 * maior parte das consultas do balcão — não deveria gerar faturamento de
 * imagem aérea para ler quatro campos de texto.
 *
 * Devolve dados do próprio cadastro, sem tocar em serviço externo.
 */
class BuscaController extends Controller
{
    /**
     * GET /api/imoveis/busca
     *
     * Todos os filtros são opcionais, mas ao menos um é exigido: sem nenhum,
     * a resposta seria a base inteira (23 mil lotes) e o aparelho do fiscal
     * é justamente o alvo.
     */
    public function buscar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'bairro'        => ['nullable', 'string', 'max:120'],
            'quadra'        => ['nullable', 'string', 'max:20'],
            'lote'          => ['nullable', 'string', 'max:20'],
            'inscricao'     => ['nullable', 'string', 'max:40'],
            'inscricao_de'  => ['nullable', 'string', 'max:40'],
            'inscricao_ate' => ['nullable', 'string', 'max:40'],
            // Filtros avançados
            'vistoria'      => ['nullable', Rule::in(array_keys(Vistoria::SITUACOES))],
            'embargo'       => ['nullable', 'boolean'],
            'doc_pendente'  => ['nullable', 'boolean'],
            'obra_sem_vistoria' => ['nullable', 'boolean'],
            // Campo único da busca do mapa
            'termo'         => ['nullable', 'string', 'max:120'],
            'incluir_baixados' => ['nullable', 'boolean'],
        ]);

        // Lote baixado (unificado ou desmembrado) nao e mais um imovel que
        // existe: fica na base para o historico, mas nao se busca nem se marca
        // no mapa. A ficha dele continua abrindo — ver ficha().
        //
        // O FILTRO É O CAMINHO DE VOLTA. Desligado por padrão, porque a
        // consulta de balcão pergunta pelo imóvel que existe hoje; ligado,
        // acha o lote que foi unificado ou desmembrado e leva à história dele.
        // Sem ele, um imóvel com processo em curso desaparecia do sistema no
        // instante em que o ato cadastral era executado.
        $q = empty($d['incluir_baixados']) ? Lote::query()->ativos() : Lote::query();
        $usou = $this->aplicarFiltros($q, $d);

        if (! $usou) {
            return response()->json(['message' => 'Informe ao menos um filtro para buscar.'], 422);
        }

        $limite = 200;
        $lotes = $q->orderBy('bairro')->orderBy('quadra')->orderBy('numero_lote')
                   ->limit($limite + 1)->get();

        $truncado = $lotes->count() > $limite;
        $lotes = $lotes->take($limite);

        // Contagens numa consulta só: um COUNT por linha faria 200 idas ao
        // banco para montar uma tabela de leitura.
        $ids  = $lotes->pluck('id');
        $docs = Documento::whereIn('lote_id', $ids)->selectRaw('lote_id, COUNT(*) c')
                    ->groupBy('lote_id')->pluck('c', 'lote_id');
        $vist = Vistoria::whereIn('lote_id', $ids)->selectRaw('lote_id, COUNT(*) c')
                    ->groupBy('lote_id')->pluck('c', 'lote_id');

        return response()->json([
            'imoveis' => $lotes->map(fn (Lote $l) => [
                'id'         => $l->id,
                'bairro'     => $l->bairro,
                'quadra'     => $l->quadra,
                'lote'       => $l->numero_lote,
                'inscricao'  => $this->inscricaoDe($l),
                'area'       => $l->area_gis_m2,
                'documentos' => (int) ($docs[$l->id] ?? 0),
                'vistorias'  => (int) ($vist[$l->id] ?? 0),
                // A situação viaja SEMPRE, mesmo sem o filtro: sem ela, a linha
                // de um baixado seria idêntica à de um imóvel vivo, e alguém
                // acabaria lavrando peça contra um lote que não existe mais.
                'situacao'   => $l->situacao,
                'baixado_em' => $l->baixado_em?->format('d/m/Y'),
            ])->values(),
            'total'    => $lotes->count(),
            'truncado' => $truncado,
        ]);
    }

    /**
     * Nome do bairro no desenho → código dele no cadastro.
     *
     * Resolvido UMA VEZ por requisição: derivar a inscrição lote a lote faria
     * uma consulta por linha, e uma busca devolve até 200 delas.
     *
     * @var array<string,string>|null
     */
    private ?array $codigosDeBairro = null;

    /**
     * A inscrição de um lote (modelo ou linha crua), derivada das partes.
     *
     * Ver Lote::inscricao() para o porquê de derivar em vez de guardar. Aqui a
     * conta é refeita com o mapa em memória, e não chamando o modelo, só para
     * não consultar o banco a cada linha do resultado.
     *
     * @param  object  $l  precisa de bairro, quadra, numero_lote, desmembramento
     */
    private function inscricaoDe(object $l): ?string
    {
        if ($this->codigosDeBairro === null) {
            $this->codigosDeBairro = DB::table('cadastro_bairros')
                ->whereNotNull('nome_gis')
                ->pluck('codigo', 'nome_gis')
                ->all();
        }

        $gravada = InscricaoImobiliaria::normalizar($l->inscricao_imobiliaria ?? null);
        if ($gravada !== null) {
            return InscricaoImobiliaria::formatar($gravada);
        }

        return InscricaoImobiliaria::formatar(InscricaoImobiliaria::montar(
            $this->codigosDeBairro[$l->bairro] ?? null,
            $l->quadra ?? null,
            $l->numero_lote ?? null,
            $l->desmembramento ?? 0
        ));
    }

    /**
     * Aplica os filtros na consulta, respeitando a PRECEDÊNCIA definida.
     *
     * A precedência não é detalhe de interface, é regra do domínio: a
     * inscrição imobiliária identifica um imóvel específico, então combiná-la
     * com bairro ou quadra só produz contradição ("inscrição X que também
     * esteja no bairro Y") — e a resposta vazia que sai daí parece defeito do
     * sistema, não filtro conflitante.
     *
     *   1. intervalo de inscrição (BCI)  — desconsidera todo o resto
     *   2. inscrição unitária            — desconsidera todo o resto
     *   3. demais filtros                — combinados entre si
     *
     * @param  array<string,mixed>  $d
     * @return bool  false quando nenhum filtro foi informado
     */
    private function aplicarFiltros($q, array $d): bool
    {
        $de  = trim((string) ($d['inscricao_de'] ?? ''));
        $ate = trim((string) ($d['inscricao_ate'] ?? ''));

        // 1. Intervalo de BCI. Comparação como texto: a inscrição é código
        // formatado (01.000.024.0009.000), não número — e o formato é de
        // largura fixa, então a ordem alfabética coincide com a numérica.
        if ($de !== '' || $ate !== '') {
            $q->whereNotNull('inscricao_imobiliaria');
            if ($de !== '')  { $q->where('inscricao_imobiliaria', '>=', $de); }
            if ($ate !== '') { $q->where('inscricao_imobiliaria', '<=', $ate); }
            return true;
        }

        // 2. Inscrição unitária.
        //
        // A INSCRIÇÃO É DESMONTADA, e não comparada como texto. A coluna
        // `inscricao_imobiliaria` está vazia nos 2.239 lotes — o desenho vem do
        // DWG e não a traz —, então procurar por ela nunca achava nada: a tela
        // de Consulta oferecia como PRIMEIRO campo uma busca que não podia dar
        // certo. Mas a inscrição É bairro + quadra + lote + variação (ver
        // App\Support\InscricaoImobiliaria), e por essas quatro o lote se acha.
        if (! empty($d['inscricao'])) {
            $partes = InscricaoImobiliaria::partes($d['inscricao']);

            if ($partes) {
                // O nome do bairro é resolvido em PHP, e não por subconsulta:
                // `lotes.bairro` e `cadastro_bairros.nome_gis` foram criadas com
                // COLAÇÕES DIFERENTES (utf8mb4_0900_ai_ci e utf8mb4_unicode_ci),
                // e o MySQL recusa comparar as duas — "Illegal mix of
                // collations". Trazer os nomes e comparar com literais evita o
                // encontro. A divergência em si continua lá, esperando a
                // próxima consulta que junte as duas tabelas.
                $nomes = DB::table('cadastro_bairros')
                    ->whereRaw('CAST(codigo AS UNSIGNED) = ?', [$partes['bairro']])
                    ->whereNotNull('nome_gis')
                    ->pluck('nome_gis')
                    ->all();

                $q->whereRaw("TRIM(LEADING '0' FROM quadra) = ?", [(string) $partes['quadra']])
                    ->whereRaw("TRIM(LEADING '0' FROM numero_lote) = ?", [(string) $partes['lote']])
                    ->where('desmembramento', $partes['variacao'])
                    ->whereIn('bairro', $nomes);
            } else {
                // Inscrição pela metade (o fiscal ainda está digitando): vale a
                // busca na coluna, para as que um dia forem informadas à mão.
                $q->where('inscricao_imobiliaria', 'like', '%' . $d['inscricao'] . '%');
            }

            return true;
        }

        $usou = false;

        // 3. Campo único da busca do mapa: um texto só, que pode ser bairro,
        // inscrição, chave ou "quadra lote".
        if (! empty($d['termo'])) {
            $t = trim($d['termo']);
            $numeros = [];
            preg_match_all('/\d+/', $t, $m);
            $numeros = $m[0];

            $q->where(function ($s) use ($t, $numeros) {
                $s->where('bairro', 'like', '%' . $t . '%')
                  ->orWhere('inscricao_imobiliaria', 'like', '%' . $t . '%')
                  ->orWhere('chave', 'like', '%' . $t . '%');

                // "24 9" e "quadra 24 lote 9" caem aqui: dois números soltos
                // são quadra e lote, na ordem em que se fala.
                if (count($numeros) >= 2) {
                    $s->orWhere(fn ($x) => $x->where('quadra', $numeros[0])->where('numero_lote', $numeros[1]));
                }
            });
            $usou = true;
        }

        if (! empty($d['bairro'])) { $q->where('bairro', 'like', '%' . $d['bairro'] . '%'); $usou = true; }
        if (! empty($d['quadra'])) { $q->where('quadra', $d['quadra']); $usou = true; }
        if (! empty($d['lote']))   { $q->where('numero_lote', $d['lote']); $usou = true; }

        // Situação da última vistoria — não de qualquer uma. Um imóvel
        // regularizado depois de irregular não deve aparecer como irregular.
        if (! empty($d['vistoria'])) {
            $q->whereIn('id', function ($s) use ($d) {
                $s->from('vistorias as v')->selectRaw('v.lote_id')
                  ->whereRaw('v.id = (SELECT MAX(v2.id) FROM vistorias v2 WHERE v2.lote_id = v.lote_id)')
                  ->where('v.situacao', $d['vistoria']);
            });
            $usou = true;
        }

        // Embargo ativo: auto de embargo lavrado e ainda não anulado.
        if (! empty($d['embargo'])) {
            $q->whereIn('id', function ($s) {
                $s->from('documentos')->select('lote_id')
                  ->where('tipo', 'auto_embargo')
                  ->whereIn('status', ['lavrado', 'atendido']);
            });
            $usou = true;
        }

        // Documento pendente: lavrado, com prazo, e o prazo ainda corre ou já
        // venceu sem atendimento.
        if (! empty($d['doc_pendente'])) {
            $q->whereIn('id', function ($s) {
                $s->from('documentos')->select('lote_id')
                  ->where('status', 'lavrado')
                  ->where(fn ($x) => $x->whereNotNull('prazo_ate')->orWhereNotNull('defesa_ate'));
            });
            $usou = true;
        }

        // Obra com alvará mas sem nenhuma vistoria — o caso que a fiscalização
        // precisa achar: projeto aprovado que ninguém foi conferir.
        if (! empty($d['obra_sem_vistoria'])) {
            $q->whereIn('id', function ($s) {
                $s->from('obras')->select('lote_id')
                  ->whereNotNull('alvara')->where('alvara', '<>', '');
            })->whereNotIn('id', function ($s) {
                $s->from('vistorias')->select('lote_id');
            });
            $usou = true;
        }

        return $usou;
    }

    /**
     * GET /api/imoveis/{lote}/geometria — o contorno de UM imóvel.
     *
     * Existe por causa do lote BAIXADO: ele não está na camada do mapa (o
     * repositório filtra `situacao = 'ativo'`) e não voltaria a estar sem
     * trazer junto o loteamento antigo inteiro, sobreposto ao atual. Aqui se
     * pede um contorno de cada vez, para desenhar por cima e tracejado.
     *
     * Serve a qualquer lote e não só ao baixado: a regra de quem aparece na
     * camada é do mapa, não desta consulta — e uma exceção escrita aqui seria
     * uma segunda regra para manter em dia.
     */
    public function geometria(Lote $lote): JsonResponse
    {
        $geojson = DB::scalar('SELECT ST_AsGeoJSON(geom) FROM lotes WHERE id = ?', [$lote->id]);

        if (! $geojson) {
            return response()->json(['message' => 'Este imóvel não tem desenho no cadastro.'], 404);
        }

        return response()->json([
            'id'         => $lote->id,
            'situacao'   => $lote->situacao,
            'baixado_em' => $lote->baixado_em?->format('d/m/Y'),
            'geometry'   => json_decode($geojson),
        ]);
    }

    /**
     * GET /api/imoveis/bairros — para o filtro, sem digitação livre.
     *
     * Sem o Eloquent de propósito: o escopo global `sem_geometria` do model
     * força um SELECT com todas as colunas, e aí o DISTINCT passa a valer
     * para a linha inteira — o resultado vinha com um "Jardim Europa IV" por
     * lote, não um por bairro.
     */
    public function bairros(): JsonResponse
    {
        return response()->json([
            'bairros' => DB::table('lotes')
                ->where('situacao', 'ativo')
                ->whereNotNull('bairro')->where('bairro', '<>', '')
                ->distinct()->orderBy('bairro')
                ->pluck('bairro'),
        ]);
    }

    /**
     * GET /api/bairros — os bairros DO MUNICÍPIO, para cadastrar lote.
     *
     * Diferente de `bairros()` acima, que lista só os que já têm lote: aqui o
     * lote ainda vai existir, e ele pode ser o primeiro do bairro dele. Um
     * combobox que só oferecesse bairro já usado obrigaria a digitar o nome à
     * mão justamente no caso novo — que é como o mesmo bairro acabou grafado
     * de três jeitos até hoje.
     *
     * Enquanto `cadastro_bairros` estiver vazia, cai no que os lotes têm: a
     * lista da prefeitura chega por carga, e até lá um combobox vazio travaria
     * o cadastro de lote inteiro.
     *
     * `nome` é o que se mostra e `valor` é o que se grava em `lotes.bairro` —
     * são textos diferentes quando o desenho escreve o bairro de outro jeito,
     * e é o de GIS que os lotes existentes já usam.
     */
    public function bairrosDoMunicipio(): JsonResponse
    {
        $cadastrados = DB::table('cadastro_bairros')
            ->orderByRaw('CAST(codigo AS UNSIGNED)')
            ->get(['codigo', 'nome_cadastro', 'nome_gis']);

        if ($cadastrados->isNotEmpty()) {
            return response()->json([
                'fonte'   => 'cadastro',
                'bairros' => $cadastrados->map(fn ($b) => [
                    'codigo' => $b->codigo,
                    'nome'   => $b->nome_cadastro ?: $b->nome_gis,
                    'valor'  => $b->nome_gis ?: $b->nome_cadastro,
                ])->values(),
            ]);
        }

        return response()->json([
            'fonte'   => 'desenho',
            'bairros' => DB::table('lotes')
                ->whereNotNull('bairro')->where('bairro', '<>', '')
                ->distinct()->orderBy('bairro')->pluck('bairro')
                ->map(fn ($n) => ['codigo' => null, 'nome' => $n, 'valor' => $n])
                ->values(),
        ]);
    }

    /**
     * GET /api/imoveis/pins — imóveis que atendem a um filtro, com coordenada.
     *
     * Alimenta os marcadores do mapa. Devolve só o que o pino precisa (ponto e
     * rótulo), nunca a geometria: um filtro que pegue mil lotes traria
     * megabytes de polígono para desenhar mil alfinetes.
     *
     * O teto é baixo de propósito. Mapa com dois mil pinos não informa nada —
     * vira mancha. Estourado o teto, o cliente avisa para refinar o filtro em
     * vez de desenhar um resultado que não se lê.
     */
    public function pins(Request $request): JsonResponse
    {
        $d = $request->validate([
            'bairro'            => ['nullable', 'string', 'max:120'],
            'vistoria'          => ['nullable', Rule::in(array_keys(Vistoria::SITUACOES))],
            'embargo'           => ['nullable', 'boolean'],
            'doc_pendente'      => ['nullable', 'boolean'],
            'obra_sem_vistoria' => ['nullable', 'boolean'],
        ]);

        // Lote baixado (unificado ou desmembrado) nao e mais um imovel que
        // existe: fica na base para o historico, mas nao se busca nem se marca
        // no mapa. A ficha dele continua abrindo — ver ficha().
        $q = Lote::query()->ativos();
        if (! $this->aplicarFiltros($q, $d)) {
            return response()->json(['message' => 'Escolha ao menos um filtro.'], 422);
        }

        $limite = 400;
        $ids = $q->limit($limite + 1)->pluck('id');
        $truncado = $ids->count() > $limite;
        $ids = $ids->take($limite);

        if ($ids->isEmpty()) {
            return response()->json(['pins' => [], 'total' => 0, 'truncado' => false]);
        }

        // Mesmo primeiro-vértice usado em toda consulta espacial deste sistema:
        // ST_Centroid não é implementado para SRS geográfico no MySQL. Em SRID
        // 4326 o MySQL guarda lat/long, então ST_X devolve a LATITUDE.
        $marca = 'SELECT id, bairro, quadra, numero_lote, inscricao_imobiliaria,
                         ST_X(ST_PointN(ST_ExteriorRing(geom), 1)) AS lat,
                         ST_Y(ST_PointN(ST_ExteriorRing(geom), 1)) AS lon
                    FROM lotes WHERE id IN (' . $ids->implode(',') . ')';

        $pins = collect(DB::select($marca))
            ->filter(fn ($l) => $l->lat !== null)
            ->map(fn ($l) => [
                'id'        => $l->id,
                'lat'       => (float) $l->lat,
                'lon'       => (float) $l->lon,
                'bairro'    => $l->bairro,
                'quadra'    => $l->quadra,
                'lote'      => $l->numero_lote,
                'inscricao' => $this->inscricaoDe($l),
            ])->values();

        return response()->json([
            'pins'     => $pins,
            'total'    => $pins->count(),
            'truncado' => $truncado,
            'teto'     => $limite,
        ]);
    }

    /**
     * GET /api/imoveis/{lote} — ficha técnica, para exibir NA TELA.
     *
     * Mesmo conteúdo da ficha do mapa, sem a parte gráfica: quem chega por
     * aqui quer os dados, não a imagem aérea.
     */
    public function ficha(Lote $lote): JsonResponse
    {
        // Primeiro vértice do anel externo, como em LoteRepository::extensao():
        // ST_Centroid não é implementado para SRS geográfico no MySQL. Para
        // centralizar o mapa num lote de 12 m, o erro é irrelevante.
        // Em SRID 4326 o MySQL guarda lat/long — ST_X devolve a LATITUDE.
        $p = DB::selectOne(
            'SELECT ST_X(ST_PointN(ST_ExteriorRing(geom), 1)) AS lat,
                    ST_Y(ST_PointN(ST_ExteriorRing(geom), 1)) AS lon
               FROM lotes WHERE id = ?',
            [$lote->id]
        );

        $documentos = Documento::where('lote_id', $lote->id)
            ->with('agente:id,name')
            ->latest('created_at')->limit(20)->get()
            ->map(function (Documento $doc) {
                [$stTxt, $stCls] = $doc->statusBadge();
                return [
                    'id'     => $doc->id,
                    'numero' => $doc->numeroFormatado(),
                    'tipo'   => $doc->rotuloTipo(),
                    'data'   => ($doc->data_lavratura ?? $doc->created_at)?->format('d/m/Y'),
                    'status' => ['texto' => $stTxt, 'classe' => $stCls],
                    'agente' => $doc->agente?->name,
                ];
            });

        $vistorias = Vistoria::where('lote_id', $lote->id)
            ->with('fiscal:id,name')
            ->latest('data_hora')->limit(20)->get()
            ->map(fn (Vistoria $v) => [
                'id'       => $v->id,
                'data'     => $v->data_hora?->format('d/m/Y H:i'),
                'fiscal'   => $v->fiscal?->name,
                'situacao' => $v->situacao,
            ]);

        return response()->json([
            'id'        => $lote->id,
            'bairro'    => $lote->bairro,
            'quadra'    => $lote->quadra,
            'lote'      => $lote->numero_lote,
            'inscricao' => $lote->inscricaoFormatada(),
            'chave'     => $lote->chave,
            'area'      => $lote->area_gis_m2,
            'fonte'     => $lote->fonte,
            'situacao'  => $lote->situacao,
            'baixado_em' => $lote->baixado_em?->format('d/m/Y'),
            'lat'       => $p?->lat !== null ? (float) $p->lat : null,
            'lon'       => $p?->lon !== null ? (float) $p->lon : null,
            'documentos' => $documentos,
            'vistorias'  => $vistorias,
            'sucessao'  => $this->sucessao($lote),
        ]);
    }

    /**
     * De onde este imóvel veio e no que ele se transformou.
     *
     * Vai embutido na ficha, e não numa rota própria, por duas razões: é uma
     * requisição a menos no aparelho do fiscal, e o modal já monta tudo de um
     * payload só.
     *
     * A consulta é o mesmo par de tabelas lido nos dois sentidos — quem foi
     * `anterior` num ato em que este lote é `posterior` é origem; quem é
     * `posterior` num ato em que ele é `anterior` é destino.
     *
     * @return array{origens: list<array<string,mixed>>, destinos: list<array<string,mixed>>}
     */
    private function sucessao(Lote $lote): array
    {
        // Base ainda sem a migração da sucessão: devolve vazio em vez de
        // estourar. A ficha é a tela mais usada do sistema.
        if (! Schema::hasTable('lote_ato_lotes')) {
            return ['origens' => [], 'destinos' => []];
        }

        $lado = fn (string $euSou, string $procuro) => DB::table('lote_ato_lotes as eu')
            ->join('lote_ato_lotes as outro', 'outro.ato_id', '=', 'eu.ato_id')
            ->join('lote_atos as ato', 'ato.id', '=', 'eu.ato_id')
            ->join('lotes as l', 'l.id', '=', 'outro.lote_id')
            ->where('eu.lote_id', $lote->id)->where('eu.papel', $euSou)
            ->where('outro.papel', $procuro)
            ->orderBy('l.numero_lote')
            ->get([
                'l.id', 'l.bairro', 'l.quadra', 'l.numero_lote', 'l.situacao',
                'ato.tipo', 'ato.protocolo_id', 'ato.created_at',
                'outro.area_m2',
            ])
            ->map(fn ($r) => [
                'id'        => $r->id,
                'rotulo'    => 'Quadra ' . ($r->quadra ?? '—') . ' · Lote ' . ($r->numero_lote ?? '—'),
                'bairro'    => $r->bairro,
                'situacao'  => $r->situacao,
                'tipo'      => $r->tipo,
                'protocolo' => $r->protocolo_id,
                'area'      => $r->area_m2,
                'data'      => $r->created_at ? date('d/m/Y', strtotime($r->created_at)) : null,
            ])->all();

        return [
            'origens'  => $lado('posterior', 'anterior'),
            'destinos' => $lado('anterior', 'posterior'),
        ];
    }
}
