<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Registra criação, alteração e exclusão na tabela `auditoria`.
 *
 * Fica num trait com eventos do Eloquent, e não em cada controller, porque
 * auditoria que depende de alguém lembrar de chamá-la é auditoria com buraco —
 * e o buraco sempre aparece justamente no registro que interessa.
 *
 * Uso: `use RegistraAuditoria;` no modelo. Para não gravar um campo, liste-o
 * em `$auditoriaOculta` (senhas, assinaturas, geometria).
 */
trait RegistraAuditoria
{
    public static function bootRegistraAuditoria(): void
    {
        static::created(fn (Model $m) => $m->registrarAuditoria('criou', null, $m->getAttributes()));

        static::updated(function (Model $m) {
            $novos = $m->getDirty();
            if (! $novos) {
                return;   // save() sem mudança não é evento
            }
            $anteriores = array_intersect_key($m->getOriginal(), $novos);
            $m->registrarAuditoria($m->acaoAuditoria($novos), $anteriores, $novos);
        });

        static::deleted(fn (Model $m) => $m->registrarAuditoria('excluiu', $m->getOriginal(), null));
    }

    /**
     * Dá nome ao que aconteceu, em vez de gravar tudo como "alterou".
     *
     * Numa lista de auditoria, "lavrou" e "anulou" se acham de imediato;
     * "alterou" obriga a abrir o registro e comparar o antes/depois.
     *
     * @param  array<string,mixed>  $novos
     */
    protected function acaoAuditoria(array $novos): string
    {
        if (array_key_exists('status', $novos)) {
            return match ($novos['status']) {
                'lavrado'   => 'lavrou',
                'anulado'   => 'anulou',
                'cancelado' => 'cancelou',
                'atendido'  => 'atendeu',
                default     => 'mudou status',
            };
        }
        foreach (['prazo_ate', 'defesa_ate', 'prazo_dias'] as $c) {
            if (array_key_exists($c, $novos)) {
                return 'alterou prazo';
            }
        }
        if (array_key_exists('assinatura_autuado', $novos) || array_key_exists('assinatura_agente', $novos)) {
            return 'assinou';
        }
        return 'alterou';
    }

    /**
     * @param  array<string,mixed>|null  $anteriores
     * @param  array<string,mixed>|null  $novos
     */
    public function registrarAuditoria(string $acao, ?array $anteriores, ?array $novos): void
    {
        $u = Auth::user();

        DB::table('auditoria')->insert([
            'user_id'          => $u?->id,
            'usuario_nome'     => $u?->name ?? $this->autorDeConsole(),
            'matricula'        => $u?->matricula,
            'acao'             => $acao,
            'tabela'           => $this->getTable(),
            'registro_id'      => $this->getKey(),
            'descricao'        => $this->descricaoAuditoria(),
            'dados_anteriores' => $anteriores ? json_encode($this->limparAuditoria($anteriores), JSON_UNESCAPED_UNICODE) : null,
            'dados_novos'      => $novos ? json_encode($this->limparAuditoria($novos), JSON_UNESCAPED_UNICODE) : null,
            'ip'               => Request::ip(),
            'dispositivo'      => substr((string) Request::userAgent(), 0, 255),
            'created_at'       => now(),
        ]);
    }

    /**
     * Quem responde por uma alteração feita fora do navegador.
     *
     * Comando de terminal roda sem sessão, então `Auth::user()` é nulo e a
     * linha sairia com autor em branco — o que na trilha se lê como "ninguém
     * fez", exatamente onde a alteração costuma ser em massa. `lotes:corrigir-quadras`
     * reatribui a quadra de centenas de imóveis de uma vez; sem isto, a maior
     * intervenção que o sistema permite seria a única anônima.
     *
     * Não substitui o usuário: `user_id` continua nulo, porque não houve
     * usuário. O que se registra é a PORTA por onde a alteração entrou.
     */
    private function autorDeConsole(): ?string
    {
        if (! app()->runningInConsole()) {
            return null;
        }

        $comando = $_SERVER['argv'][1] ?? null;

        return $comando ? 'terminal: ' . $comando : 'terminal';
    }

    /** Resumo legível do registro, para a lista de auditoria não ser só ids. */
    protected function descricaoAuditoria(): ?string
    {
        foreach (['numero_formatado', 'chave', 'numero', 'nome', 'name', 'descricao', 'codigo'] as $c) {
            if (! empty($this->{$c})) {
                return substr((string) $this->{$c}, 0, 200);
            }
        }
        return null;
    }

    /**
     * Remove o que não deve ir para a trilha: segredo, imagem grande e
     * geometria. Um registro de auditoria com uma assinatura em base64 dentro
     * pesa centenas de KB e não acrescenta nada — o que importa é QUE assinou.
     *
     * @param  array<string,mixed>  $dados
     * @return array<string,mixed>
     */
    protected function limparAuditoria(array $dados): array
    {
        $ocultar = array_merge(
            // TODA ASSINATURA, e não as três que alguém lembrou na hora.
            //
            // Assinatura é imagem: um data URL de PNG, dezenas de KB de texto.
            // Gravá-la na auditoria põe a imagem inteira dentro da linha —
            // 613 KB em treze linhas, em produção, e uma célula de 320 mil
            // pixels de largura em qualquer tela que mostre o antes/depois.
            //
            // A lista original tinha `assinatura_agente` e `assinatura_autuado`;
            // escaparam `assinatura` (rubrica do perfil e do fiscal na OS) e
            // `assinatura_emitente` (quem emitiu a ordem de serviço). Foram
            // ACRESCENTADAS UMA A UMA, e por isso o esquecimento se repetiu.
            //
            // Confira com:
            //   SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            //    WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE '%assinatura%'
            ['password', 'remember_token', 'geom'],
            property_exists($this, 'auditoriaOculta') ? $this->auditoriaOculta : []
        );

        // Qualquer coluna cujo nome contenha "assinatura" sai — menos a que
        // guarda um booleano, `recusa_assinatura`, que é informação do
        // processo (o autuado se recusou a assinar) e não uma imagem.
        foreach (array_keys($dados) as $campo) {
            if (str_contains($campo, 'assinatura') && $campo !== 'recusa_assinatura') {
                $dados[$campo] = '[omitido]';
            }
        }

        foreach ($ocultar as $campo) {
            if (array_key_exists($campo, $dados)) {
                $dados[$campo] = '[omitido]';
            }
        }
        return $dados;
    }
}
