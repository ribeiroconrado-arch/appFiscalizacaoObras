<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Autenticação por sessão, escrita à mão em vez de instalada pelo Breeze.
 *
 * O Breeze traz Tailwind, Vite e um conjunto de views próprio, que brigariam
 * com o design system já portado do AppPOSTURAS (`public/css/app.css`). São
 * três ações; escrevê-las custa menos do que desmontar o starter kit depois.
 */
class AuthController extends Controller
{
    public function mostrarLogin(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('mapa') : view('login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [], [
            'email'    => 'e-mail',
            'password' => 'senha',
        ]);

        // `ativo` entra na tentativa: desativar alguém precisa ter efeito
        // imediato no login, não só no cadastro de usuários.
        $ok = Auth::attempt(
            ['email' => $dados['email'], 'password' => $dados['password'], 'ativo' => true],
            $request->boolean('lembrar')
        );

        if (! $ok) {
            // Mensagem única para senha errada, e-mail inexistente ou conta
            // desativada: dizer qual dos três é enumerar usuários válidos.
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas ou usuário sem acesso.',
            ]);
        }

        $request->session()->regenerate(); // impede fixação de sessão

        $u = Auth::user();
        $u->forceFill(['ultimo_acesso_em' => now()])->saveQuietly();

        return redirect()->intended(route('mapa'));
    }

    public function sair(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
