<?php

namespace App\Console\Commands;

use App\Cadastro\CargaDoCadastro;
use App\Models\CadastroCarga;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Carrega uma exportação do cadastro imobiliário (.xlsx) pelo terminal.
 *
 * É a MESMA carga da tela (Parâmetros → Cadastro municipal): passa por
 * App\Cadastro\CargaDoCadastro, grava só o que mudou, registra o histórico e
 * marca como ausente o que sumiu dos bairros presentes no arquivo. Fica
 * registrada em `cadastro_cargas` como qualquer outra.
 *
 * Diferente da tela, o arquivo é lido de onde está e não é copiado nem
 * apagado — ele é seu, no seu disco.
 */
class CarregarCadastro extends Command
{
    protected $signature = 'cadastro:carregar
                            {arquivo : caminho da exportação .xlsx}
                            {--bairro= : nome do bairro no GIS, para amarrar ao bairro do cadastro}
                            {--confirmar : aceita as ausências mesmo acima de 20% dos imóveis dos bairros}';

    protected $description = 'Carrega uma exportação do cadastro imobiliário (.xlsx), gravando só o que mudou';

    public function handle(CargaDoCadastro $servico): int
    {
        $arquivo = $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Planilha não encontrada: {$arquivo}");

            return self::FAILURE;
        }

        $trava = Cache::lock('cadastro-carga', 3600);
        if (! $trava->get()) {
            $this->error('Há outra carga do cadastro em andamento. Tente de novo quando ela terminar.');

            return self::FAILURE;
        }

        $carga = CadastroCarga::create([
            'arquivo_nome'   => basename($arquivo),
            'arquivo_bytes'  => filesize($arquivo),
            'arquivo_sha256' => hash_file('sha256', $arquivo),
            'status'         => 'na_fila',
        ]);

        try {
            $servico->processar($carga, $arquivo, (bool) $this->option('confirmar'));
        } catch (Throwable $e) {
            $carga->update(['status' => 'falhou', 'mensagem' => mb_substr($e->getMessage(), 0, 500)]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $trava->release();
        }

        if ($carga->status === 'aguardando_confirmacao') {
            $this->warn($carga->mensagem);
            $this->warn('Rode de novo com --confirmar para aceitar.');

            return self::FAILURE;
        }

        $this->table(['Novos', 'Alterados', 'Iguais', 'Ausentes', 'Reapareceram', 'Linhas lidas'], [[
            $carga->novos, $carga->alterados, $carga->iguais, $carga->ausentes, $carga->reaparecidos, $carga->linhas_lidas,
        ]]);
        if ($carga->primeira) {
            $this->line('Primeira carga desta mecânica: as linhas antigas viraram base, sem histórico.');
        }
        if (DB::table('cadastro_proprietarios')->doesntExist()) {
            $this->warn('Nenhum proprietário lido — confira os nomes das colunas em ColunasDaExportacao::PROPRIETARIO.');
        }

        $bairros = DB::table('cadastro_externo_imoveis')
            ->whereIn(DB::raw("TRIM(LEADING '0' FROM codigo_bairro)"), $carga->bairros ?? [])
            ->distinct()->pluck('codigo_bairro', 'nome_bairro')->all();
        $this->amarrarBairro($bairros);

        return self::SUCCESS;
    }

    /**
     * Liga o bairro do cadastro ao bairro do GIS.
     *
     * Sem essa ligação o casamento de imóveis não acontece — e não acontecer é
     * o comportamento certo: casar por quadra e lote sem o bairro produz
     * ligação ERRADA, porque quadra 2 lote 5 existe em todo bairro do
     * município. Medido nesta base: 656 pares (quadra, lote) do Buritis existem
     * também no Jardim Europa IV. É por isso que a amarração é um passo
     * explícito, e não um palpite do importador.
     *
     * @param  array<string,?string>  $bairros  nome no cadastro => código
     */
    private function amarrarBairro(array $bairros): void
    {
        $gis = $this->option('bairro');
        if (! $gis) {
            $this->newLine();
            $this->warn('Nenhum bairro do GIS informado: os imóveis ficam carregados, mas ainda');
            $this->warn('não casam com lote nenhum. Para amarrar, rode de novo com');
            $this->warn('  --bairro="Nome do bairro como está no GIS"');

            return;
        }

        if (count($bairros) !== 1) {
            $this->error('A exportação tem ' . count($bairros) . ' bairros; --bairro só serve para arquivo de um.');

            return;
        }

        $lotes = DB::table('lotes')->where('bairro', $gis)->count();
        if ($lotes === 0) {
            $this->error("Não há lote nenhum no bairro \"{$gis}\". Confira o nome — ele tem de ser");
            $this->error('idêntico ao que está na coluna `bairro` da tabela lotes.');

            return;
        }

        $nomeCadastro = array_key_first($bairros);
        $codigo = $bairros[$nomeCadastro];

        DB::table('cadastro_bairros')->updateOrInsert(
            ['nome_gis' => $gis],
            ['codigo' => $codigo, 'nome_cadastro' => $nomeCadastro,
                'created_at' => now(), 'updated_at' => now()]
        );

        $this->newLine();
        $this->info("Bairro amarrado: \"{$nomeCadastro}\" (código {$codigo}) = \"{$gis}\" ({$lotes} lotes no GIS).");
    }

    /** "14891.33" e "14.891,33" viram 14891.33. Vazio vira null. */
}
