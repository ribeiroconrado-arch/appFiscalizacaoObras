<?php

namespace App\Support;

use App\Models\User;

/**
 * O MÓDULO EM QUE O USUÁRIO ESTÁ TRABALHANDO — Obras ou Posturas.
 *
 * Fica na SESSÃO, e não em parâmetro de cada requisição: é um estado da tela
 * (o seletor do cabeçalho), e mandá-lo em toda chamada abriria a porta para a
 * tela pedir um módulo e a lista vir de outro. O servidor decide qual vale, e
 * só aceita um módulo que o usuário tenha (User::temModulo).
 *
 * O que cada módulo tem de próprio (peças, abas, parâmetros) está em
 * config/modulos.php.
 */
final class Modulo
{
    private const SESSAO = 'modulo';

    /** @return list<string> na ordem do seletor */
    public static function todos(): array
    {
        return array_keys(config('modulos', []));
    }

    public static function existe(?string $modulo): bool
    {
        return $modulo !== null && array_key_exists($modulo, config('modulos', []));
    }

    /** @return array<string, mixed> */
    public static function config(string $modulo): array
    {
        return config("modulos.$modulo", []);
    }

    /** "Obras" / "Posturas". */
    public static function rotulo(string $modulo): string
    {
        return self::config($modulo)['rotulo'] ?? ucfirst($modulo);
    }

    /** "Fiscalização de Obras" — o título do cabeçalho. */
    public static function titulo(string $modulo): string
    {
        return self::config($modulo)['titulo'] ?? self::rotulo($modulo);
    }

    /** @return list<string> as peças que o módulo emite (ver Documento::TIPOS) */
    public static function tipos(string $modulo): array
    {
        return self::config($modulo)['tipos'] ?? [];
    }

    /** @return list<string> os destinos do menu, na ordem */
    public static function abas(string $modulo): array
    {
        return self::config($modulo)['abas'] ?? [];
    }

    /**
     * O módulo ativo: o da sessão, se o usuário o tiver; senão o primeiro dos
     * dele. Sem usuário (console, fila), Obras — o comportamento de antes.
     */
    public static function ativo(?User $usuario = null): string
    {
        $usuario ??= auth()->user();
        if (! $usuario) {
            return 'obras';
        }

        $daSessao = app()->bound('session') ? session(self::SESSAO) : null;
        if (is_string($daSessao) && $usuario->temModulo($daSessao)) {
            return $daSessao;
        }

        return $usuario->modulos()[0] ?? 'obras';
    }

    /** Troca o módulo da sessão. Quem confere se o usuário pode é o chamador. */
    public static function definir(string $modulo): void
    {
        session([self::SESSAO => $modulo]);
    }
}
