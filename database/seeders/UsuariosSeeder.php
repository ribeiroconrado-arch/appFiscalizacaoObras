<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cria o administrador inicial e um usuário de cada perfil, para que a matriz
 * de permissão possa ser testada de verdade — e não só no papel.
 *
 * Idempotente (`updateOrCreate` por e-mail): rodar de novo não duplica nem
 * apaga senha alterada depois, exceto a do admin, que é sempre reposta.
 *
 * A senha vem de `SEED_SENHA_ADMIN`. Sem ela, é sorteada a cada execução: o
 * repositório é público, e uma senha padrão escrita aqui valeria para qualquer
 * base semeada sem configurar o `.env`.
 */
class UsuariosSeeder extends Seeder
{
    public function run(): void
    {
        $senha = env('SEED_SENHA_ADMIN') ?: Str::password(16, symbols: false);

        $usuarios = [
            [
                'name'         => 'Administrador',
                'email'        => 'admin@primaveradoleste.mt.gov.br',
                'matricula'    => '0001',
                'perfil'       => 'admin',
                'tipo_usuario' => 'agente',
            ],
            [
                'name'         => 'Fiscal de Obras',
                'email'        => 'fiscal@primaveradoleste.mt.gov.br',
                'matricula'    => '0002',
                'perfil'       => 'comum',
                'tipo_usuario' => 'agente',
            ],
            [
                // Cargo não-agente: mesmo com `perfil` gravado como 'comum', o
                // `perfilEfetivo()` do modelo rebaixa para viewer. É o caso de
                // teste da trava — se um dia essa regra quebrar, é aqui que se
                // percebe.
                'name'         => 'Coordenadora',
                'email'        => 'coordenacao@primaveradoleste.mt.gov.br',
                'matricula'    => '0003',
                'perfil'       => 'comum',
                'tipo_usuario' => 'coordenador',
            ],
        ];

        foreach ($usuarios as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                $u + ['password' => Hash::make($senha), 'ativo' => true]
            );
        }

        $this->command->newLine();
        $this->command->warn('Usuários criados com a senha: ' . $senha);
        $this->command->warn('Trocar antes de qualquer uso fora da rede local.');
    }
}
