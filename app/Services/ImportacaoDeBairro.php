<?php

namespace App\Services;

use App\Cadastro\BairrosDoDesenho;
use App\Cadastro\VinculoDoBairro;
use App\Models\ImportacaoLote;
use App\Models\User;
use App\Repositories\LoteRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Importar um bairro pela TELA: conferir o arquivo, gravar em revisão, publicar
 * ou desfazer a importação inteira.
 *
 * ── O ciclo ──
 *
 *   conferir   lê o GeoJSON e diz o que ele traz, sem gravar nada;
 *   gravar     carrega os lotes como RASCUNHO (`em_revisao = 1`) — só quem
 *              carregou enxerga, e nada entra na trilha;
 *              pré-curadoria e conferência com o cadastro já valem aqui;
 *   salvar     o rascunho passa a valer: vira importação em revisão;
 *   descartar  o rascunho some inteiro, sem registro;
 *   conferir   com o cadastro da prefeitura (ConferenciaComCadastro);
 *   publicar   o administrador libera; justificativa se houver divergência;
 *   excluir    apaga os lotes da importação salva, se nenhum tiver vínculo.
 *
 * ── Por que a tela NÃO atualiza lote existente ──
 *
 * O comando `gis:importar-lotes` concilia: lote que já existe é ATUALIZADO no
 * lugar (ON DUPLICATE KEY UPDATE). Pela tela isso seria um atalho perigoso — a
 * atualização valeria na hora, para todos, antes de qualquer revisão, e não
 * teria como ser desfeita junto com o resto da importação. Aqui lote que já
 * existe é CONFLITO e barra a gravação; reconciliar bairro já carregado
 * continua sendo trabalho do comando, deliberado.
 *
 * A leitura do arquivo, o índice de identidade e a contagem de preservados
 * saíram do comando para cá, e o comando os chama: são as mesmas regras nos
 * dois caminhos.
 */
class ImportacaoDeBairro
{
    /** Linhas por INSERT. 200 mantém o pacote longe do max_allowed_packet. */
    public const LOTE_INSERCAO = 200;

    /** Quantos itens de cada lista a conferência devolve para a tela. */
    private const AMOSTRA = 40;

    public function __construct(private LoteRepository $lotes) {}

    /**
     * Lê o GeoJSON e prepara as linhas de `lotes`.
     *
     * @return array{linhas: list<array<string,mixed>>, ignoradas: int, feicoes: int, bairros: list<string>}
     */
    public function lerArquivo(string $caminho, ?string $nome = null): array
    {
        if (! is_file($caminho)) {
            throw new RuntimeException("Arquivo não encontrado: {$caminho}");
        }

        $gj = json_decode(file_get_contents($caminho), true);
        if (! is_array($gj) || ($gj['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException('O arquivo não é uma FeatureCollection GeoJSON.');
        }

        $feicoes = $gj['features'] ?? [];
        $linhas = [];
        $bairros = [];
        $ignoradas = 0;
        $agora = now();

        foreach ($feicoes as $f) {
            $geom = $f['geometry'] ?? null;
            // A tabela declara POLYGON. MultiPolygon entraria como geometria de
            // tipo incompatível e o INSERT falharia no meio da importação —
            // melhor recusar aqui, contando, do que abortar no meio.
            if (! $geom || ($geom['type'] ?? null) !== 'Polygon') {
                $ignoradas++;
                continue;
            }

            $p = $f['properties'] ?? [];
            $bairro = $p['bairro'] ?? null;
            if (! $bairro) { $ignoradas++; continue; }

            // Texto, sempre: o GeoJSON pode trazer 5 onde a base guarda "5", e
            // a comparação com a chave de identidade é de texto.
            $texto = fn ($v) => ($v === null || $v === '') ? null : (string) $v;

            $bairros[$bairro] = true;
            $linhas[] = [
                'bairro'      => $bairro,
                'quadra'      => $texto($p['quadra'] ?? null),
                'numero_lote' => $texto($p['numero_lote'] ?? null),
                'chave'       => $p['chave'] ?? ($bairro . '|?|?'),
                'area_gis_m2' => $p['area_gis_m2'] ?? null,
                'fonte'       => $p['fonte'] ?? ($nome ?? basename($caminho)),
                'geojson'     => json_encode($geom),
                'ts'          => $agora,
            ];
        }

        return [
            'linhas'    => $linhas,
            'ignoradas' => $ignoradas,
            'feicoes'   => count($feicoes),
            'bairros'   => array_keys($bairros),
        ];
    }

    /**
     * O que o arquivo traz e o que impede gravá-lo. NADA é gravado.
     *
     * É a conferência que faltou quando o Buritis entrou com 105 lotes sem
     * quadra: importação que grava direto só mostra o problema depois, no mapa.
     *
     * @return array<string,mixed>
     */
    public function conferirArquivo(string $caminho, string $nome): array
    {
        $lido = $this->lerArquivo($caminho, $nome);
        $linhas = $lido['linhas'];
        $impedimentos = [];

        if (! $linhas) {
            $impedimentos[] = 'O arquivo não tem nenhum polígono com bairro.';
        }
        if (count($lido['bairros']) > 1) {
            $impedimentos[] = 'O arquivo traz ' . count($lido['bairros']) . ' bairros ('
                . implode(', ', $lido['bairros']) . '). Envie um bairro por importação — '
                . 'é o que permite revisar, publicar e desfazer cada um por si.';
        }
        $bairro = $lido['bairros'][0] ?? null;

        // Sem quadra ou sem número não há identidade: entra, e a correção é
        // feita no mapa com as ferramentas de curadoria.
        $semIdentidade = array_values(array_filter($linhas,
            fn ($l) => $l['quadra'] === null || $l['numero_lote'] === null));

        // Repetidos DENTRO do arquivo: o índice único recusaria o segundo.
        $contagem = [];
        foreach ($linhas as $l) {
            if ($l['quadra'] !== null && $l['numero_lote'] !== null) {
                $k = $l['quadra'] . '|' . $l['numero_lote'];
                $contagem[$k] = ($contagem[$k] ?? 0) + 1;
            }
        }
        $repetidos = [];
        foreach ($contagem as $k => $n) {
            if ($n > 1) {
                [$q, $lt] = explode('|', $k, 2);
                $repetidos[] = ['quadra' => $q, 'lote' => $lt, 'vezes' => $n];
            }
        }
        if ($repetidos) {
            $impedimentos[] = count($repetidos) . ' combinação(ões) de quadra e lote aparecem mais de uma vez no arquivo.';
        }

        // Lotes que JÁ EXISTEM ativos na base, pela chave de identidade — a
        // coluna gerada que o índice único usa.
        $conflitos = $this->conflitos($linhas);
        if ($conflitos['total'] > 0) {
            $impedimentos[] = $conflitos['total'] . ' lote(s) do arquivo já existem na base. '
                . 'Pela tela só se importa lote novo; reconciliar bairro já carregado é pelo comando gis:importar-lotes.';
        }

        $hash = hash_file('sha256', $caminho);
        $mesmoArquivo = ImportacaoLote::where('arquivo_hash', $hash)
            ->whereIn('status', ['rascunho', 'revisao', 'publicada'])->first();
        if ($mesmoArquivo) {
            $impedimentos[] = $mesmoArquivo->emRascunho()
                ? "Este mesmo arquivo já está carregado como rascunho por {$mesmoArquivo->usuario?->name}. "
                  . 'Continue aquele rascunho, ou descarte-o antes de carregar de novo.'
                : "Este mesmo arquivo já foi importado (importação nº {$mesmoArquivo->id}).";
        }

        if ($bairro) {
            $aberta = ImportacaoLote::where('bairro', $bairro)->whereIn('status', ['rascunho', 'revisao'])->first();
            if ($aberta && ! $mesmoArquivo) {
                $impedimentos[] = $aberta->emRascunho()
                    ? "O bairro {$bairro} já tem um rascunho de importação carregado por {$aberta->usuario?->name}. "
                      . 'Salve ou descarte aquele antes de carregar outro.'
                    : "O bairro {$bairro} já tem a importação nº {$aberta->id} salva e não publicada. "
                      . 'Publique ou exclua aquela antes de enviar outra.';
            }
        }

        if ($indice = $this->problemaNoIndice()) {
            $impedimentos[] = $indice;
        }

        return [
            'arquivo'        => $nome,
            'hash'           => $hash,
            'bairro'         => $bairro,
            'feicoes'        => $lido['feicoes'],
            'lidos'          => count($linhas),
            'ignoradas'      => $lido['ignoradas'],
            'sem_quadra'     => count($semIdentidade),
            'sem_quadra_amostra' => array_map(fn ($l) => [
                'quadra' => $l['quadra'], 'lote' => $l['numero_lote'],
            ], array_slice($semIdentidade, 0, self::AMOSTRA)),
            'repetidos'      => array_slice($repetidos, 0, self::AMOSTRA),
            'repetidos_total' => count($repetidos),
            'conflitos'      => $conflitos['amostra'],
            'conflitos_total' => $conflitos['total'],
            // Informativo: o bairro já tem lotes, mas nenhum destes colide.
            'lotes_do_bairro_na_base' => $bairro
                ? DB::table('lotes')->where('bairro', $bairro)->where('situacao', 'ativo')->count()
                : 0,
            // A que bairro do cadastro o nome do arquivo está ligado — e, se
            // a nenhum, a lista para ligar já no carregamento.
            'vinculo'        => $bairro ? app(VinculoDoBairro::class)->situacao($bairro) : null,
            'impedimentos'   => $impedimentos,
            'pode_gravar'    => $impedimentos === [],
        ];
    }

    /**
     * Carrega os lotes como RASCUNHO: existem na base, mas só quem carregou os
     * vê, e a importação ainda não vale — ver salvar() e descartar().
     */
    public function gravar(string $caminho, string $nome, User $autor, ?int $cadastroBairroId = null): ImportacaoLote
    {
        $conf = $this->conferirArquivo($caminho, $nome);
        if (! $conf['pode_gravar']) {
            throw new RuntimeException(implode(' ', $conf['impedimentos']));
        }

        $linhas = $this->lerArquivo($caminho, $nome)['linhas'];

        return DB::transaction(function () use ($conf, $linhas, $autor, $cadastroBairroId) {
            $imp = ImportacaoLote::create([
                'bairro'       => $conf['bairro'],
                'arquivo_nome' => $conf['arquivo'],
                'arquivo_hash' => $conf['hash'],
                'user_id'      => $autor->id,
                'status'       => 'rascunho',
                'total_lotes'  => count($linhas),
                'conferencia'  => collect($conf)->except(['hash', 'impedimentos', 'pode_gravar', 'vinculo'])->all(),
            ]);

            foreach (array_chunk($linhas, self::LOTE_INSERCAO) as $bloco) {
                $valores = [];
                $params  = [];
                foreach ($bloco as $l) {
                    $valores[] = '(?, ?, ?, ?, ?, ?, ST_GeomFromGeoJSON(?, 1, 4326), ?, ?, 1, ?, ?)';
                    array_push($params,
                        $l['bairro'], $l['quadra'], $l['numero_lote'], $l['chave'],
                        $l['area_gis_m2'], $l['fonte'], $l['geojson'], 'importacao', $imp->id,
                        $l['ts'], $l['ts']);
                }
                // INSERT puro, sem ON DUPLICATE: a conferência já provou que
                // nenhum destes existe. Se um aparecer entre a conferência e
                // aqui, o índice recusa e a transação desfaz tudo — que é o
                // certo, em vez de sobrescrever em silêncio.
                DB::insert(
                    'INSERT INTO lotes (bairro, quadra, numero_lote, chave, area_gis_m2, fonte, geom,
                                        origem, importacao_id, em_revisao, created_at, updated_at) VALUES '
                    . implode(',', $valores),
                    $params
                );
            }

            // A mesma conferência de LoteRepository::diagnostico, só sobre o
            // que ACABOU de entrar: um defeito antigo de outro bairro não
            // pode barrar esta importação, nem este passar escondido por ele.
            $d = DB::selectOne('SELECT SUM(ST_SRID(geom) <> 4326) AS srid_errado,
                                       SUM(NOT ST_IsValid(geom))  AS geometria_invalida
                                  FROM lotes WHERE importacao_id = ?', [$imp->id]);
            if ((int) $d->srid_errado > 0 || (int) $d->geometria_invalida > 0) {
                // QUAIS lotes: sem isso o curador recebe um "inválido" sem ter
                // por onde começar a corrigir o DWG.
                $quais = collect(DB::select('SELECT quadra, numero_lote FROM lotes
                                              WHERE importacao_id = ? AND (NOT ST_IsValid(geom) OR ST_SRID(geom) <> 4326)
                                              LIMIT 8', [$imp->id]))
                    ->map(fn ($l) => 'Q' . ($l->quadra ?? '?') . ' L' . ($l->numero_lote ?? '?'))->implode(', ');
                $n = (int) $d->srid_errado + (int) $d->geometria_invalida;
                throw new RuntimeException("O arquivo tem {$n} lote(s) com geometria que o banco não aceita "
                    . "(contorno que se cruza ou coordenada fora de EPSG:4326): {$quais}"
                    . ($n > 8 ? ' e outros' : '') . '. Corrija no desenho e envie de novo. Nada foi gravado.');
            }

            // Escolhido na leitura do arquivo. Se a ligação for recusada, o
            // carregamento inteiro volta — melhor do que um rascunho que não
            // é o que a pessoa pediu.
            if ($cadastroBairroId) {
                app(VinculoDoBairro::class)->vincular($imp->bairro, $cadastroBairroId, $imp->id);
            }

            // Sem trilha: rascunho ainda não é importação. O registro nasce
            // em salvar().
            return $imp;
        });
    }

    /**
     * O rascunho passa a VALER: vira importação em revisão, entra na trilha e
     * na lista de todos os curadores. Daqui em diante, conferência, publicação
     * e exclusão seguem registradas.
     */
    public function salvar(ImportacaoLote $imp, User $autor): void
    {
        if (! $imp->emRascunho()) {
            throw new RuntimeException('Esta importação já foi salva.');
        }

        // A pré-curadoria pode ter mexido nos desenhos: a mesma conferência
        // do carregamento, sobre o que está na base agora.
        $ruins = (int) DB::scalar('SELECT COUNT(*) FROM lotes WHERE importacao_id = ? AND situacao = "ativo"
                                     AND (NOT ST_IsValid(geom) OR ST_SRID(geom) <> 4326)', [$imp->id]);
        if ($ruins > 0) {
            throw new RuntimeException("{$ruins} lote(s) do rascunho têm geometria inválida. Corrija na pré-curadoria antes de salvar.");
        }
        $ativos = DB::table('lotes')->where('importacao_id', $imp->id)->where('situacao', 'ativo')->count();
        if ($ativos === 0) {
            throw new RuntimeException('O rascunho não tem nenhum lote. Descarte-o em vez de salvar.');
        }

        DB::transaction(function () use ($imp, $autor, $ativos) {
            $ajustes = DB::table('auditoria')->where('importacao_id', $imp->id)
                ->where('tabela', 'lotes_em_revisao')->count();
            $imp->update([
                'status'      => 'revisao',
                'total_lotes' => $ativos,
                'salvo_por'   => $autor->id,
                'salvo_em'    => now(),
            ]);
            $this->auditar('importou', $imp, null, [
                'bairro' => $imp->bairro, 'arquivo' => $imp->arquivo_nome, 'lotes' => $ativos,
                'ajustes_pre_curadoria' => $ajustes,
                'conferido' => (bool) $imp->conferido_em,
            ]);
        });
    }

    /**
     * Desfaz o rascunho por inteiro, SEM registro: lotes, atos internos, os
     * ajustes da pré-curadoria e o próprio registro da importação. Ele nunca
     * valeu — não há o que guardar.
     */
    public function descartar(ImportacaoLote $imp): int
    {
        if (! $imp->emRascunho()) {
            throw new RuntimeException('Só se descarta rascunho. Importação salva se exclui, com motivo.');
        }
        // Lote em rascunho só o autor vê, mas a regra é a mesma da exclusão:
        // nada que prenda o lote ao resto do sistema some junto.
        if ($v = $this->vinculos($imp)) {
            throw new RuntimeException('Há lotes do rascunho com vínculos ('
                . implode(', ', array_map(fn ($k, $n) => "{$k}: {$n}", array_keys($v), $v))
                . '). Nada foi descartado.');
        }

        return DB::transaction(function () use ($imp) {
            $ids = DB::table('lotes')->where('importacao_id', $imp->id)->pluck('id');

            // Lote apagado DURANTE a pré-curadoria já saiu de `lotes`, mas a
            // cópia em lotes_apagados ficaria órfã — e restaurável, como se
            // tivesse sido de todos. Ela é achada pelo registro da pré-curadoria.
            $apagadosNoRascunho = DB::table('auditoria')->where('importacao_id', $imp->id)
                ->where('tabela', 'lotes_em_revisao')->pluck('registro_id');
            if (Schema::hasTable('lotes_apagados')) {
                DB::table('lotes_apagados')->whereIn('lote_id', $apagadosNoRascunho->merge($ids)->unique())->delete();
            }

            $internos = DB::table('lote_ato_lotes')->whereIn('lote_id',
                DB::table('lotes')->where('importacao_id', $imp->id)->select('id'))->distinct()->pluck('ato_id');
            DB::table('lote_atos')->whereIn('id', $internos)->delete();

            $n = DB::table('lotes')->where('importacao_id', $imp->id)->delete();
            DB::table('auditoria')->where('importacao_id', $imp->id)->delete();
            DB::table('auditoria')->where('tabela', 'importacoes_lotes')->where('registro_id', $imp->id)->delete();
            $imp->delete();

            return $n;
        });
    }

    /**
     * O que prende os lotes desta importação ao resto do sistema.
     *
     * É a mesma checagem que o `--substituir` do comando sempre fez antes de
     * apagar — vistoria, documento, obra e sucessão —, somada ao que veio
     * depois dele: protocolo, ordem de serviço e ficha do BCI.
     *
     * @return array<string,int>
     */
    public function vinculos(ImportacaoLote $imp): array
    {
        $ids = fn () => DB::table('lotes')->where('importacao_id', $imp->id)->select('id');
        $conta = fn (string $tabela) => Schema::hasTable($tabela)
            ? DB::table($tabela)->whereIn('lote_id', $ids())->count()
            : 0;

        return array_filter([
            'vistorias'      => $conta('vistorias'),
            'documentos'     => $conta('documentos'),
            'protocolos'     => $conta('protocolos'),
            'ordens_servico' => $conta('ordens_servico'),
            'obras'          => $conta('obras'),
            // Só a sucessão que SAI da importação prende: desmembrar ou
            // unificar lotes do próprio bairro em revisão é pré-curadoria, e
            // some junto com ele.
            'sucessao'       => DB::table('lote_ato_lotes')->whereIn('lote_id', $ids())
                ->whereIn('ato_id', $this->atosExternos($imp))->count(),
        ]);
    }

    /**
     * Atos de sucessão (lote_atos) que envolvem algum lote FORA desta
     * importação. Os demais são internos — nasceram na pré-curadoria.
     */
    private function atosExternos(ImportacaoLote $imp)
    {
        return DB::table('lote_ato_lotes as al')
            ->join('lotes as l', 'l.id', '=', 'al.lote_id')
            ->where(fn ($q) => $q->whereNull('l.importacao_id')->orWhere('l.importacao_id', '<>', $imp->id))
            ->select('al.ato_id');
    }

    /**
     * Libera os lotes a todos.
     *
     * A conferência com o cadastro tem de estar FEITA e EM DIA: publicar sobre
     * uma conferência antiga seria publicar sobre um retrato que já não é o
     * bairro. Havendo divergência, só com justificativa — que fica gravada.
     */
    public function publicar(ImportacaoLote $imp, User $admin, ?string $justificativa): void
    {
        if (! $imp->emRevisao()) {
            throw new RuntimeException('Só se publica importação salva e ainda não publicada.');
        }
        if (! $imp->conferido_em) {
            throw new RuntimeException('Confira a importação com o cadastro da prefeitura antes de publicar.');
        }
        if ($this->mudouDepoisDaConferencia($imp)) {
            throw new RuntimeException('Houve alteração nos lotes depois da última conferência. Confira de novo antes de publicar.');
        }

        $divergencias = (int) ($imp->conferencia_cadastro['total_divergencias'] ?? 0);
        $justificativa = trim((string) $justificativa);
        if ($divergencias > 0 && mb_strlen($justificativa) < 20) {
            throw new RuntimeException("A conferência encontrou {$divergencias} divergência(s). "
                . 'Corrija-as, ou escreva uma justificativa (mínimo de 20 caracteres) para publicar mesmo assim.');
        }

        DB::transaction(function () use ($imp, $admin, $justificativa, $divergencias) {
            DB::table('lotes')->where('importacao_id', $imp->id)->update(['em_revisao' => false]);
            $imp->update([
                'status'        => 'publicada',
                'publicado_por' => $admin->id,
                'publicado_em'  => now(),
                'justificativa_publicacao' => $justificativa !== '' ? $justificativa : null,
            ]);
            $this->auditar('publicou', $imp, ['status' => 'revisao'], [
                'status' => 'publicada', 'divergencias' => $divergencias,
                'justificativa' => $justificativa !== '' ? $justificativa : null,
            ]);
        });
    }

    /**
     * Apaga os lotes da importação SALVA e ainda não publicada, desde que
     * nenhum deles tenha vínculo.
     *
     * Só a salva, por decisão do usuário: o rascunho se descarta (nunca
     * valeu), e a publicada já é o cadastro de todos — a partir dali os lotes
     * mudam pelas ferramentas da curadoria, com histórico, e não somem em bloco.
     *
     * O desenho de cada lote vai antes para `lotes_apagados`, como em qualquer
     * exclusão do cadastro (ver LotesApagados): apagar sem guardar a geometria
     * é a única operação do cadastro que não teria volta. O registro da
     * importação fica, marcado como excluído, com o motivo.
     *
     * @return int quantos lotes foram apagados
     */
    public function excluir(ImportacaoLote $imp, User $autor, string $motivo): int
    {
        if ($imp->status === 'excluida') {
            throw new RuntimeException('Esta importação já foi excluída.');
        }
        if ($imp->emRascunho()) {
            throw new RuntimeException('Rascunho não se exclui: descarte-o.');
        }
        if ($imp->status === 'publicada') {
            throw new RuntimeException('Importação publicada não se exclui: os lotes já valem para todos. '
                . 'Corrija-os pelas ferramentas da curadoria.');
        }
        if ($v = $this->vinculos($imp)) {
            throw new RuntimeException('Há lotes desta importação com vínculos ('
                . implode(', ', array_map(fn ($k, $n) => "{$k}: {$n}", array_keys($v), $v))
                . '). Nada foi apagado.');
        }

        return DB::transaction(function () use ($imp, $autor, $motivo) {
            $agora = now();
            DB::insert(
                "INSERT INTO lotes_apagados (lote_id, bairro, quadra, numero_lote, desmembramento, chave,
                        inscricao_imobiliaria, area_gis_m2, frente_m, fundos_m, lado_direito_m,
                        lado_esquerdo_m, area_matricula_m2, fonte, origem, geom, user_id,
                        usuario_nome, motivo, apagado_em, created_at, updated_at)
                 SELECT id, bairro, quadra, numero_lote, desmembramento, chave,
                        inscricao_imobiliaria, area_gis_m2, frente_m, fundos_m, lado_direito_m,
                        lado_esquerdo_m, area_matricula_m2, fonte, origem, geom, ?, ?, ?, ?, ?, ?
                   FROM lotes WHERE importacao_id = ?",
                [$autor->id, $autor->name, "Importação nº {$imp->id} excluída: {$motivo}",
                 $agora, $agora, $agora, $imp->id]
            );

            // Atos internos da pré-curadoria saem antes: a FK da sucessão é
            // RESTRICT, e eles não têm sentido sem os lotes.
            $internos = DB::table('lote_ato_lotes')
                ->whereIn('lote_id', DB::table('lotes')->where('importacao_id', $imp->id)->select('id'))
                ->distinct()->pluck('ato_id');
            DB::table('lote_atos')->whereIn('id', $internos)->delete();

            $apagados = DB::table('lotes')->where('importacao_id', $imp->id)->delete();

            $anterior = $imp->status;
            $imp->update([
                'status'          => 'excluida',
                'excluido_por'    => $autor->id,
                'excluido_em'     => $agora,
                'motivo_exclusao' => $motivo,
            ]);
            $this->auditar('excluiu importação', $imp, ['status' => $anterior],
                ['status' => 'excluida', 'lotes_apagados' => $apagados, 'motivo' => $motivo]);

            return $apagados;
        });
    }

    /**
     * Lote novo nascido DENTRO de um bairro em revisão entra na mesma revisão.
     *
     * Sem isto, desenhar o lote que faltou no arquivo — que é justamente a
     * correção que a conferência pede — o publicaria na hora, antes do resto
     * do bairro. Usado por DesenhoDeLote, DesmembramentoDeLote e
     * UnificacaoDeLotes, somado aos atributos do lote criado.
     *
     * @return array{importacao_id?: int, em_revisao?: bool}
     */
    public static function emRevisaoNoBairro(?string $bairro): array
    {
        if (! $bairro || ! Schema::hasColumn('lotes', 'em_revisao')) {
            return [];
        }
        $id = DB::table('importacoes_lotes')->where('bairro', $bairro)
            ->whereIn('status', ['rascunho', 'revisao'])->value('id');

        return $id ? ['importacao_id' => (int) $id, 'em_revisao' => true] : [];
    }

    /**
     * Uma operação de curadoria não pode juntar lote em revisão com lote
     * publicado, nem lotes de importações diferentes.
     *
     * O registro de cada lado vai para um lugar (Histórico do cadastro ou a
     * ficha da importação) e o resultado precisaria nascer num dos dois: uma
     * unificação que mistura os dois lados publicaria um pedaço do bairro que
     * ainda está em revisão, ou esconderia um imóvel que todos já viam.
     *
     * @param  list<int>  $ids
     */
    public static function misturaRevisao(array $ids): ?string
    {
        if (! $ids || ! Schema::hasColumn('lotes', 'em_revisao')) {
            return null;
        }
        $grupos = DB::table('lotes')->whereIn('id', $ids)
            ->selectRaw('em_revisao, COALESCE(importacao_id, 0) AS imp')->distinct()->get()
            ->map(fn ($g) => $g->em_revisao ? 'revisao:' . $g->imp : 'publicado')
            ->unique();

        return $grupos->count() > 1
            ? 'A seleção mistura lotes de uma importação não publicada com lotes publicados (ou de outra '
              . 'importação). Faça a pré-curadoria só com os lotes da importação.'
            : null;
    }

    /** Alguém mexeu nos lotes da importação depois da última conferência? */
    public function mudouDepoisDaConferencia(ImportacaoLote $imp): bool
    {
        if (! $imp->conferido_em) {
            return true;
        }
        // Religar o bairro troca o código — e com ele todas as inscrições.
        $codigo = (new BairrosDoDesenho())->codigos()[BairrosDoDesenho::chave($imp->bairro)] ?? null;
        if (ltrim((string) $codigo, '0') !== ltrim((string) ($imp->conferencia_cadastro['codigo_bairro'] ?? ''), '0')) {
            return true;
        }
        $ultima = DB::table('lotes')->where('importacao_id', $imp->id)->max('updated_at');
        $total  = DB::table('lotes')->where('importacao_id', $imp->id)->where('situacao', 'ativo')->count();

        return ($ultima && strtotime($ultima) > $imp->conferido_em->getTimestamp())
            || $total !== (int) ($imp->conferencia_cadastro['lotes_conferidos'] ?? -1);
    }

    /**
     * Quantos lotes do arquivo já existem na base com geometria feita à mão.
     *
     * São os que o `IF(origem = 'importacao', ...)` do comando vai preservar.
     * Contar aqui é o que permite RELATAR a preservação: sem isso ela seria
     * silenciosa, e silêncio é exatamente o defeito que ela veio corrigir.
     *
     * @param  list<array<string,mixed>>  $linhas
     */
    public function contarPreservados(array $linhas): int
    {
        if (! Schema::hasColumn('lotes', 'origem')) {
            return 0;   // base ainda sem a migração da sucessão
        }

        $n = 0;
        // Em blocos: um `whereIn` com 23 mil triplas estoura o max_allowed_packet.
        foreach (array_chunk($linhas, 500) as $bloco) {
            $n += DB::table('lotes')
                ->where('origem', '<>', 'importacao')
                ->where('situacao', 'ativo')
                ->where(function ($q) use ($bloco) {
                    foreach ($bloco as $l) {
                        $q->orWhere(fn ($w) => $w
                            ->where('bairro', $l['bairro'])
                            ->where('quadra', $l['quadra'])
                            ->where('numero_lote', $l['numero_lote']));
                    }
                })
                ->count();
        }

        return $n;
    }

    /**
     * Existe índice único de identificação? Devolve o motivo quando ele falta
     * e não pode ser criado; null quando está tudo certo.
     *
     * Dois índices podem ocupar este papel:
     *
     *   uk_lotes_identificacao         (bairro, quadra, numero_lote) — o antigo
     *   uk_lotes_identificacao_ativos  sobre a coluna gerada `chave_identidade`,
     *                                  que só tem valor quando o lote está ativo
     *
     * Quadra nula não atrapalha em nenhum dos dois: no MySQL o índice único
     * trata cada NULL como distinto.
     *
     * Sem índice, o ON DUPLICATE KEY UPDATE do comando nunca dispara e a
     * importação DUPLICA em silêncio; por isso ele é criado aqui quando falta.
     */
    public function problemaNoIndice(): ?string
    {
        $existe = fn (string $nome) => (bool) DB::selectOne(
            'SELECT COUNT(*) n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = "lotes"
                AND index_name = ?',
            [$nome]
        )->n;

        if ($existe('uk_lotes_identificacao_ativos') || $existe('uk_lotes_identificacao')) {
            return null;
        }

        // Só entre ATIVOS: lote inativo não disputa identidade com ninguém.
        $duplicadas = DB::selectOne('SELECT COUNT(*) n FROM (
            SELECT 1 FROM lotes
             WHERE situacao = "ativo" AND quadra IS NOT NULL AND numero_lote IS NOT NULL
             GROUP BY bairro, quadra, numero_lote
            HAVING COUNT(*) > 1
        ) t')->n;

        if ($duplicadas > 0) {
            return "Não há índice único de identificação, e ele não pode ser criado: {$duplicadas} "
                . 'combinações bairro|quadra|lote estão repetidas entre lotes ativos. '
                . 'Diagnóstico: php artisan gis:conferir';
        }

        DB::statement('ALTER TABLE lotes ADD UNIQUE KEY uk_lotes_identificacao (bairro, quadra, numero_lote)');

        return null;
    }

    /**
     * Lotes do arquivo que colidem com lote ATIVO da base.
     *
     * @param  list<array<string,mixed>>  $linhas
     * @return array{total: int, amostra: list<array<string,mixed>>}
     */
    private function conflitos(array $linhas): array
    {
        $chaves = [];
        foreach ($linhas as $l) {
            if ($l['quadra'] !== null && $l['numero_lote'] !== null) {
                $chaves[] = $l['bairro'] . '|' . $l['quadra'] . '|' . $l['numero_lote'];
            }
        }

        $total = 0;
        $amostra = [];
        $temColunaGerada = Schema::hasColumn('lotes', 'chave_identidade');
        foreach (array_chunk(array_unique($chaves), 500) as $bloco) {
            $q = DB::table('lotes')->where('situacao', 'ativo');
            $temColunaGerada
                ? $q->whereIn('chave_identidade', $bloco)
                : $q->whereIn(DB::raw("CONCAT(bairro, '|', quadra, '|', numero_lote)"), $bloco);
            $achados = $q->get(['id', 'quadra', 'numero_lote', 'em_revisao']);
            $total += $achados->count();
            foreach ($achados as $a) {
                if (count($amostra) < self::AMOSTRA) {
                    $amostra[] = ['id' => $a->id, 'quadra' => $a->quadra, 'lote' => $a->numero_lote];
                }
            }
        }

        return ['total' => $total, 'amostra' => $amostra];
    }

    /** Uma linha na trilha, no mesmo formato de RegistraAuditoria. */
    public function auditar(string $acao, ImportacaoLote $imp, ?array $antes, ?array $depois): void
    {
        $u = auth()->user();
        DB::table('auditoria')->insert([
            'user_id'          => $u?->id,
            'usuario_nome'     => $u?->name ?? 'sistema',
            'matricula'        => $u?->matricula,
            'acao'             => $acao,
            'tabela'           => 'importacoes_lotes',
            'registro_id'      => $imp->id,
            'descricao'        => mb_substr("Importação nº {$imp->id} — {$imp->bairro}", 0, 200),
            'dados_anteriores' => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'dados_novos'      => $depois ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
            'ip'               => Request::ip(),
            'dispositivo'      => substr((string) Request::userAgent(), 0, 255),
            'created_at'       => now(),
        ]);
    }
}
