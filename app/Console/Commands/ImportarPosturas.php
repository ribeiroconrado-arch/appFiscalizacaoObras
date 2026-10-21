<?php

namespace App\Console\Commands;

use App\Posturas\Importacao\ClienteSupabase;
use App\Posturas\Importacao\ImportadorPosturas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Traz o AppPOSTURAS (Supabase) para o módulo Posturas deste sistema.
 *
 *   php artisan posturas:importar --simular     ← lê tudo, mostra o que faria, desfaz
 *   php artisan posturas:importar               ← grava
 *   php artisan posturas:importar --so=leis --so=denuncias
 *
 * Pede o login de um ADMINISTRADOR do Posturas (as políticas de lá só mostram
 * tudo a ele). A senha é digitada na hora, não aparece na tela e não é gravada.
 * A URL e a chave pública vêm de POSTURAS_SUPABASE_URL e POSTURAS_SUPABASE_CHAVE.
 *
 * Os documentos do Posturas não vêm: eram de teste. Ver ImportadorPosturas.
 */
class ImportarPosturas extends Command
{
    protected $signature = 'posturas:importar
        {--simular : Lê e confere tudo, mas desfaz no fim — nada é gravado}
        {--so=* : Só estas etapas (usuarios, bairros, logradouros, rua_bairro, contribuintes, decibelimetros, leis, feriados, upf, config, denuncias)}
        {--criar-usuarios : Cria conta DESATIVADA para quem do Posturas não existe aqui}
        {--email= : E-mail do administrador do Posturas (senão, é perguntado)}';

    protected $description = 'Importa denúncias, cadastros, leis e configurações do AppPOSTURAS (Supabase)';

    public function handle(): int
    {
        $so = array_values(array_filter((array) $this->option('so')));
        if ($fora = array_diff($so, ImportadorPosturas::ETAPAS)) {
            $this->error('Etapa desconhecida: ' . implode(', ', $fora));

            return self::INVALID;
        }

        try {
            $sb = new ClienteSupabase((string) config('services.posturas_supabase.url'), (string) config('services.posturas_supabase.chave'));
            $email = $this->option('email') ?: $this->ask('E-mail do administrador do Posturas');
            $sb->entrar((string) $email, (string) $this->secret('Senha (não aparece ao digitar)'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');
        $imp = new ImportadorPosturas($sb, (bool) $this->option('criar-usuarios'), $simular);

        $this->info($simular ? 'SIMULAÇÃO — tudo será desfeito no fim.' : 'Importando…');
        DB::beginTransaction();
        try {
            $imp->importar($so);
            $simular ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            // Só o começo: o erro de SQL repete os valores da linha, e um deles pode ser um PDF inteiro.
            $this->error('Parou com erro, e nada foi gravado: ' . Str::limit($e->getMessage(), 600));

            return self::FAILURE;
        }

        $this->table(['Tabela', 'Lidos', 'Novos', 'Atualizados', 'Sem mudança'],
            collect($imp->relatorio)->map(fn ($r, $t) => [$t, $r['lidos'], $r['novos'], $r['atualizados'], $r['ignorados']])->values());

        if ($imp->config && ! $simular) {
            $arquivo = 'importacao-posturas/config-' . now()->format('Ymd-His') . '.json';
            Storage::disk('local')->put($arquivo, json_encode($imp->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('A tabela config de lá foi guardada para conferência em storage/app/private/' . $arquivo);
        }

        foreach ($imp->avisos as $a) {
            $this->warn('• ' . $a);
        }

        $this->info($simular ? 'Simulação concluída. Nada foi gravado.' : 'Importação concluída.');

        return self::SUCCESS;
    }
}
