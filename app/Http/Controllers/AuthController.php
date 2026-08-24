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

    /**
     * Entra com MATRÍCULA ou e-mail, no mesmo campo.
     *
     * A matrícula existe para o campo: são seis dígitos contra trinta
     * caracteres, digitados numa tela de celular, com sol na cara. O e-mail
     * continua aceito — e continua obrigatório no cadastro, porque ele não é
     * identificador, é CANAL: é por ele que se recupera senha e se avisa de um
     * acesso suspeito. Trocar um pelo outro perderia isso; aceitar os dois,
     * não.
     */
    public function entrar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'identificador' => ['required', 'string', 'max:255'],
            'password'      => ['required', 'string'],
        ], [], [
            'identificador' => 'matrícula ou e-mail',
            'password'      => 'senha',
        ]);

        // A arroba decide qual coluna consultar. Matrícula não tem arroba e
        // e-mail sempre tem — não há como um valor ser os dois, então não há
        // ambiguidade a resolver depois.
        $campo = str_contains($dados['identificador'], '@') ? 'email' : 'matricula';

        // `ativo` entra na tentativa: desativar alguém precisa ter efeito
        // imediato no login, não só no cadastro de usuários.
        $ok = Auth::attempt(
            [$campo => $dados['identificador'], 'password' => $dados['password'], 'ativo' => true],
            $request->boolean('lembrar')
        );

        if (! $ok) {
            // Mensagem única para senha errada, usuário inexistente ou conta
            // desativada: dizer qual dos três é enumerar usuários válidos — e
            // matrícula, sendo sequencial, seria varrida em minutos.
            throw ValidationException::withMessages([
                'identificador' => 'Credenciais inválidas ou usuário sem acesso.',
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
