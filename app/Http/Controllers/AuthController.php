<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
    /** Erros de senha de uma mesma conta (de qualquer IP) que travam o login dela. */
    private const FALHAS_POR_CONTA = 10;
    /** Por quanto tempo os erros contam, e por quanto tempo a conta fica travada. */
    private const BLOQUEIO_SEGUNDOS = 15 * 60;

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

        // LIMITE POR CONTA (segurança, 23/10/2026): quem testa a senha de UMA
        // pessoa trocando de IP escapa dos limites por IP (AppServiceProvider).
        // Aqui contam só os ERROS daquela conta, de qualquer lugar: 10 em 15
        // minutos e a conta não aceita login por 15 minutos — nem com a senha
        // certa. Vale também para conta que não existe, com a mesma mensagem:
        // o bloqueio não revela quem é real. Login certo zera a contagem.
        $chave = 'login-falhas:' . sha1(mb_strtolower(trim($dados['identificador'])));
        if (RateLimiter::tooManyAttempts($chave, self::FALHAS_POR_CONTA)) {
            $min = max(1, (int) ceil(RateLimiter::availableIn($chave) / 60));
            throw ValidationException::withMessages([
                'identificador' => "Muitas tentativas para este acesso. Tente de novo em {$min} minuto(s).",
            ]);
        }

        // `ativo` entra na tentativa: desativar alguém precisa ter efeito
        // imediato no login, não só no cadastro de usuários.
        $ok = Auth::attempt(
            [$campo => $dados['identificador'], 'password' => $dados['password'], 'ativo' => true],
            $request->boolean('lembrar')
        );

        if (! $ok) {
            RateLimiter::hit($chave, self::BLOQUEIO_SEGUNDOS);
            if (RateLimiter::tooManyAttempts($chave, self::FALHAS_POR_CONTA)) {
                Log::warning('Login bloqueado por excesso de senhas erradas.', [
                    'identificador' => mb_substr($dados['identificador'], 0, 80),
                    'ip'            => $request->ip(),
                ]);
            }
            // Mensagem única para senha errada, usuário inexistente ou conta
            // desativada: dizer qual dos três é enumerar usuários válidos — e
            // matrícula, sendo sequencial, seria varrida em minutos.
            throw ValidationException::withMessages([
                'identificador' => 'Credenciais inválidas ou usuário sem acesso.',
            ]);
        }

        RateLimiter::clear($chave);
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
