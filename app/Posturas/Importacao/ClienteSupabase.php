<?php

namespace App\Posturas\Importacao;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Leitura do Supabase do AppPOSTURAS pela API REST (PostgREST), como o próprio
 * app faz (src/core/supabase.js): `apikey` com a chave pública e
 * `Authorization: Bearer` com o token de um usuário logado.
 *
 * O login tem de ser de ADMINISTRADOR: as políticas de lá (RLS) só mostram ao
 * agente as denúncias dele e escondem CPF/CNPJ de quem não é admin. Só lê —
 * nada aqui escreve no Supabase.
 */
class ClienteSupabase
{
    /** PostgREST devolve no máximo mil linhas por pedido (max-rows). */
    private const LOTE = 1000;

    private ?string $token = null;

    public function __construct(private string $url, private string $chave)
    {
        $this->url = rtrim($url, '/');
        if ($this->url === '' || $this->chave === '') {
            throw new RuntimeException('Defina POSTURAS_SUPABASE_URL e POSTURAS_SUPABASE_CHAVE no .env (a URL e a chave pública do AppPOSTURAS).');
        }
    }

    /** Entra com e-mail e senha (grant_type=password) e guarda o token. */
    public function entrar(string $email, string $senha): void
    {
        $r = Http::withHeaders(['apikey' => $this->chave])
            ->acceptJson()->timeout(30)
            ->post($this->url . '/auth/v1/token?grant_type=password', ['email' => $email, 'password' => $senha]);

        if (! $r->successful() || ! $r->json('access_token')) {
            throw new RuntimeException('O Supabase recusou o login: ' . ($r->json('error_description') ?? $r->json('msg') ?? $r->status()));
        }

        $this->token = $r->json('access_token');
    }

    /**
     * Todas as linhas de uma tabela, de mil em mil (offset/limit, como o
     * sbFetchLotes de lá, mas sem o teto de 5.000 que o app usa na tela).
     *
     * @return list<array<string,mixed>>
     */
    public function todas(string $tabela, string $select = '*', ?string $ordem = null): array
    {
        $linhas = [];
        for ($offset = 0; ; $offset += self::LOTE) {
            $q = ['select' => $select, 'offset' => $offset, 'limit' => self::LOTE];
            if ($ordem) {
                $q['order'] = $ordem;
            }

            $r = Http::withHeaders([
                'apikey'        => $this->chave,
                'Authorization' => 'Bearer ' . ($this->token ?? $this->chave),
            ])->acceptJson()->timeout(60)->get($this->url . '/rest/v1/' . $tabela, $q);

            if (! $r->successful()) {
                throw new RuntimeException("Falha ao ler {$tabela}: HTTP {$r->status()} — " . ($r->json('message') ?? $r->body()));
            }

            $lote = $r->json() ?? [];
            array_push($linhas, ...$lote);
            if (count($lote) < self::LOTE) {
                return $linhas;
            }
        }
    }
}
