<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importa lotes de um GeoJSON (EPSG:4326) gerado pelo pipeline da Etapa 1
 * (`gis/tools/dxf_para_geojson.py`) para a tabela `lotes`.
 *
 * Idempotente por `--substituir`: reimportar um bairro apaga só o que é dele,
 * nunca a tabela inteira — o município será importado bairro a bairro, ao longo
 * de semanas, e um `TRUNCATE` acidental custaria todo o trabalho anterior.
 */
class ImportarLotes extends Command
{
    protected $signature = 'gis:importar-lotes
                            {arquivo : caminho do .geojson (EPSG:4326)}
                            {--substituir : apaga antes os lotes dos bairros presentes no arquivo}';

    protected $description = 'Importa lotes de um GeoJSON para a tabela lotes';

    /** Linhas por INSERT. 200 mantém o pacote longe do max_allowed_packet. */
    private const LOTE_INSERCAO = 200;

    public function handle(): int
    {
        $arquivo = $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");
            return self::FAILURE;
        }

        $gj = json_decode(file_get_contents($arquivo), true);
        if (! is_array($gj) || ($gj['type'] ?? null) !== 'FeatureCollection') {
            $this->error('O arquivo não é uma FeatureCollection GeoJSON.');
            return self::FAILURE;
        }

        $feicoes = $gj['features'] ?? [];
        $this->info(sprintf('Feições no arquivo: %d', count($feicoes)));

        // ── preparo das linhas ──
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

            $bairros[$bairro] = true;
            $linhas[] = [
                'bairro'      => $bairro,
                'quadra'      => $p['quadra'] ?? null,
                'numero_lote' => $p['numero_lote'] ?? null,
                'chave'       => $p['chave'] ?? ($bairro . '|?|?'),
                'area_gis_m2' => $p['area_gis_m2'] ?? null,
                'fonte'       => $p['fonte'] ?? basename($arquivo),
                'geojson'     => json_encode($geom),
                'ts'          => $agora,
            ];
        }

        if ($ignoradas > 0) {
            $this->warn("Feições ignoradas (sem polígono ou sem bairro): {$ignoradas}");
        }
        if (empty($linhas)) {
            $this->error('Nada a importar.');
            return self::FAILURE;
        }

        // ── substituição por bairro ──
        //
        // APAGAR É O ÚLTIMO RECURSO, não o caminho normal. O `id` do lote é a
        // âncora de todo o histórico: vistoria, obra, documento e protocolo
        // apontam para ele. Apagar leva vistoria e obra junto (FK em CASCADE) —
        // sem erro, em silêncio — e trava se houver documento lavrado (FK
        // RESTRICT), abortando a importação pela metade.
        //
        // O caminho normal passou a ser a CONCILIAÇÃO (ver a inserção abaixo):
        // lote que já existe é ATUALIZADO mantendo o id; só o novo é inserido.
        if ($this->option('substituir')) {
            $nomes = array_keys($bairros);

            $vinculados = DB::table('lotes')->whereIn('bairro', $nomes)
                ->where(fn ($q) => $q
                    ->whereExists(fn ($s) => $s->from('vistorias')->whereColumn('vistorias.lote_id', 'lotes.id'))
                    ->orWhereExists(fn ($s) => $s->from('documentos')->whereColumn('documentos.lote_id', 'lotes.id'))
                    ->orWhereExists(fn ($s) => $s->from('obras')->whereColumn('obras.lote_id', 'lotes.id')))
                ->count();

            if ($vinculados) {
                $this->error("ABORTADO: {$vinculados} lote(s) destes bairros têm vistoria, obra ou documento.");
                $this->line('  Apagá-los destruiria trabalho de campo e peça de processo.');
                $this->line('  Rode SEM --substituir: o lote existente é atualizado no lugar,');
                $this->line('  preservando o id e todo o histórico pendurado nele.');
                return self::FAILURE;
            }

            // Lote que participou de desmembramento ou unificação não está na
            // conta acima e seria apagado — arrastando o ato pela FK RESTRICT
            // (erro cru de banco) ou, pior, deixando a cadeia de sucessão do
            // imóvel manca. O inativo entra junto: ele só existe para contar a
            // história, e apagá-lo apaga a história.
            $emSucessao = DB::table('lotes')->whereIn('bairro', $nomes)
                ->where(fn ($q) => $q
                    ->where('situacao', 'inativo')
                    ->orWhereExists(fn ($s) => $s->from('lote_ato_lotes')
                        ->whereColumn('lote_ato_lotes.lote_id', 'lotes.id')))
                ->count();

            if ($emSucessao) {
                $this->error("ABORTADO: {$emSucessao} lote(s) destes bairros participam de desmembramento ou unificação.");
                $this->line('  Apagá-los destruiria a cadeia de sucessão dos imóveis — o histórico');
                $this->line('  que diz de onde cada lote atual veio.');
                $this->line('  Rode SEM --substituir: o lote existente é atualizado no lugar.');
                return self::FAILURE;
            }

            $apagados = DB::table('lotes')->whereIn('bairro', $nomes)->delete();
            $this->warn(sprintf('Removidos %d lotes de: %s', $apagados, implode(', ', $nomes)));
        }

        // ── o índice único é pré-requisito, não detalhe ──
        //
        // A gravação usa ON DUPLICATE KEY UPDATE para atualizar o lote que já
        // existe em vez de criar um segundo. Sem o índice único o MySQL não
        // tem como reconhecer "o mesmo lote": a cláusula nunca dispara e o
        // comando volta a duplicar — em silêncio, terminando "com sucesso".
        //
        // A conferência vem DEPOIS do --substituir de propósito. A base pode
        // estar suja justamente por uma importação antiga, e a limpeza que
        // destrava o índice é a que acabou de acontecer acima; conferir antes
        // trancaria a porta pelo lado de dentro — a importação que corrige o
        // problema seria recusada por causa do problema que ela corrige.
        if (! $this->garantirIndice()) {
            return self::FAILURE;
        }

        // ── inserção ──
        // ST_GeomFromGeoJSON é o caminho seguro para a ordem dos eixos: o MySQL
        // interpreta o documento conforme a RFC 7946 (longitude, latitude) e
        // faz a conversão para a ordem interna sozinho. Montar WKT à mão aqui
        // exigiria o 'axis-order=long-lat' e abriria espaço para erro silencioso.
        // Contado ANTES de gravar: depois do INSERT já não dá para distinguir
        // quem foi preservado de quem simplesmente não mudou.
        $preservados = $this->contarPreservados($linhas);

        $barra = $this->output->createProgressBar(count($linhas));
        $barra->start();
        $inseridos = 0;

        DB::transaction(function () use ($linhas, $barra, &$inseridos) {
            foreach (array_chunk($linhas, self::LOTE_INSERCAO) as $bloco) {
                $valores = [];
                $params  = [];
                foreach ($bloco as $l) {
                    $valores[] = '(?, ?, ?, ?, ?, ?, ST_GeomFromGeoJSON(?, 1, 4326), ?, ?)';
                    array_push($params,
                        $l['bairro'], $l['quadra'], $l['numero_lote'], $l['chave'],
                        $l['area_gis_m2'], $l['fonte'], $l['geojson'], $l['ts'], $l['ts']);
                }
                // ON DUPLICATE KEY UPDATE: reimportar o mesmo bairro ATUALIZA o
                // lote em vez de criar um segundo. O id sobrevive, e com ele
                // toda vistoria, obra, documento e protocolo já ligados.
                //
                // Depende do índice único da identificação — sem ele o MySQL
                // não reconhece "o mesmo lote" e volta a duplicar. Hoje o
                // índice é sobre a coluna gerada `chave_identidade`, que só
                // tem valor para lote ATIVO: reimportar não ressuscita lote
                // inativo, ele simplesmente não é reconhecido como o mesmo.
                //
                // created_at fica de fora do UPDATE de propósito: é a data em
                // que o lote entrou na base, não a da última reimportação.
                //
                // ── A GEOMETRIA SÓ É SOBRESCRITA SE VEIO DO DWG ──
                //
                // Este era o risco mais caro do módulo. Um lote desenhado à
                // mão no mapa, ou nascido de um desmembramento, cuja
                // identificação bata com a do DWG teria a geometria trocada
                // pela do desenho antigo — em silêncio, com o comando
                // terminando "com sucesso". O trabalho manual desapareceria
                // sem nada no log.
                //
                // `origem` existe para isto: só `importacao` aceita ser
                // sobrescrito. O resto é preservado, e o comando RELATA
                // quantos preservou — nunca truncar em silêncio é regra
                // declarada do projeto (ver o comentário de max_lotes em
                // config/gis.php).
                DB::insert(
                    'INSERT INTO lotes (bairro, quadra, numero_lote, chave, area_gis_m2,
                                        fonte, geom, created_at, updated_at) VALUES '
                    . implode(',', $valores)
                    . ' ON DUPLICATE KEY UPDATE
                          chave       = IF(origem = \'importacao\', VALUES(chave), chave),
                          area_gis_m2 = IF(origem = \'importacao\', VALUES(area_gis_m2), area_gis_m2),
                          fonte       = IF(origem = \'importacao\', VALUES(fonte), fonte),
                          geom        = IF(origem = \'importacao\', VALUES(geom), geom),
                          updated_at  = VALUES(updated_at)',
                    $params
                );
                $inseridos += count($bloco);
                $barra->advance(count($bloco));
            }
        });

        $barra->finish();
        $this->newLine(2);
        $this->info("Inseridos: {$inseridos}");

        if ($preservados > 0) {
            $this->newLine();
            $this->warn("Geometria PRESERVADA em {$preservados} lote(s).");
            $this->line('  São lotes desenhados à mão ou nascidos de desmembramento/unificação,');
            $this->line('  cuja identificação também existe no DWG. O desenho do arquivo NÃO os');
            $this->line('  sobrescreveu — se a intenção era justamente voltar ao DWG, é preciso');
            $this->line('  tratar cada um deliberadamente.');
            $this->line('  Lista: php artisan gis:conferir');
        }

        // ── conferência ──
        // SRID errado não lança erro: dá mapa vazio. Conferir aqui é mais barato
        // do que descobrir depois, olhando uma tela em branco no celular.
        $d = app(\App\Repositories\LoteRepository::class)->diagnostico();
        $this->newLine();
        $this->line('Conferência da tabela:');
        $this->table(
            ['total', 'srid≠4326', 'geom inválida', 'chaves distintas', 'bairros'],
            [[$d->total, $d->srid_errado, $d->geometria_invalida, $d->chaves_distintas, $d->bairros]]
        );

        if ((int) $d->srid_errado > 0 || (int) $d->geometria_invalida > 0) {
            $this->error('Há geometria com SRID errado ou inválida — investigar antes de usar.');
            return self::FAILURE;
        }
        if ((int) $d->chaves_distintas < (int) $d->total) {
            // Repetição aqui não é mais sinal de quadra trocada: o extrator
            // deixa a quadra VAZIA quando não consegue prová-la, e todos esses
            // lotes compartilham a mesma chave "bairro|?|número". Separar os
            // dois casos importa — um é pendência conhecida, o outro é defeito.
            $semQuadra = DB::table('lotes')->whereNull('quadra')->count();
            $this->warn(sprintf('Chaves repetidas: %d lotes para %d chaves.',
                $d->total, $d->chaves_distintas));
            $this->line(sprintf('  %d lote(s) estão sem quadra — o desenho não permitiu prová-la.', $semQuadra));
            $this->line('  Rode "php artisan gis:conferir" para ver quais e corrigi-los.');
        }

        return self::SUCCESS;
    }

    /**
     * Quantos lotes do arquivo já existem na base com geometria feita à mão.
     *
     * São os que o `IF(origem = 'importacao', ...)` da gravação vai preservar.
     * Contar aqui é o que permite RELATAR a preservação: sem isso ela seria
     * silenciosa, e silêncio é exatamente o defeito que ela veio corrigir —
     * quem reimporta o bairro precisa saber que o DWG não venceu em N lotes.
     *
     * @param  list<array<string,mixed>>  $linhas
     */
    private function contarPreservados(array $linhas): int
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
     * Garante que existe um índice único de identificação, criando o antigo se
     * a base ainda não migrou. Devolve false — e explica — quando a
     * duplicidade impede.
     *
     * Dois índices podem ocupar este papel, e qualquer um dos dois serve para
     * o ON DUPLICATE KEY UPDATE funcionar:
     *
     *   uk_lotes_identificacao         (bairro, quadra, numero_lote) — o antigo
     *   uk_lotes_identificacao_ativos  sobre a coluna gerada `chave_identidade`,
     *                                  que só tem valor quando o lote está ativo
     *
     * O segundo veio com a sucessão (migração 2026_08_21_000100): sem ele,
     * unificar os lotes 05 e 06 e chamar o resultado de "05" colidiria com o
     * 05 recém-inativado, que é o caminho normal da unificação.
     *
     * Quadra nula não atrapalha em nenhum dos dois: no MySQL o índice único
     * trata cada NULL como distinto, então lote cuja quadra o desenho não
     * permitiu provar entra sem travar a identidade dos demais. Ele aparece na
     * conferência (php artisan gis:conferir) para correção manual.
     */
    private function garantirIndice(): bool
    {
        $existe = fn (string $nome) => (bool) DB::selectOne(
            'SELECT COUNT(*) n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = "lotes"
                AND index_name = ?',
            [$nome]
        )->n;

        if ($existe('uk_lotes_identificacao_ativos') || $existe('uk_lotes_identificacao')) {
            return true;
        }

        // Só entre ATIVOS: lote inativo não disputa identidade com ninguém.
        $duplicadas = DB::selectOne('SELECT COUNT(*) n FROM (
            SELECT 1 FROM lotes
             WHERE situacao = "ativo" AND quadra IS NOT NULL AND numero_lote IS NOT NULL
             GROUP BY bairro, quadra, numero_lote
            HAVING COUNT(*) > 1
        ) t')->n;

        if ($duplicadas > 0) {
            $this->error('ABORTADO: não há índice único de identificação, e ele não pode ser criado.');
            $this->line("  {$duplicadas} combinações bairro|quadra|lote estão repetidas entre lotes ativos.");
            $this->line('  Sem o índice esta importação DUPLICARIA os lotes em vez de atualizá-los.');
            $this->line('  Diagnóstico: php artisan gis:conferir');
            return false;
        }

        DB::statement('ALTER TABLE lotes ADD UNIQUE KEY uk_lotes_identificacao (bairro, quadra, numero_lote)');
        $this->info('Índice único uk_lotes_identificacao criado.');

        return true;
    }
}
