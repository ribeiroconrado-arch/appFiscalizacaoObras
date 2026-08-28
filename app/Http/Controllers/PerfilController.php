<?php

namespace App\Http\Controllers;

use App\Services\Assinatura;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Meu perfil — o que o próprio usuário pode alterar de si mesmo.
 *
 * Separado de ParametroController de propósito: lá é o administrador mexendo
 * em terceiros; aqui é qualquer servidor autenticado mexendo apenas nos
 * próprios dados. Misturar os dois num controller só faria a regra de quem
 * pode o quê depender de um `if` no meio do método.
 */
class PerfilController extends Controller
{
    /** GET /api/perfil */
    public function index(Request $r): JsonResponse
    {
        $u = $r->user();

        return response()->json([
            'id'            => $u->id,
            'name'          => $u->name,
            'email'         => $u->email,
            'matricula'     => $u->matricula,
            'perfil_rotulo' => $u->perfilRotulo(),
            'tipo_usuario'  => $u->tipo_usuario,
            'iniciais'      => $u->iniciais(),
            'assinatura'    => $u->assinatura,
        ]);
    }

    /**
     * POST /api/perfil/senha
     *
     * Exige a senha atual mesmo com a sessão já autenticada: sem isso, um
     * computador deixado aberto na repartição vira uma troca de senha e a
     * perda da conta do servidor.
     */
    public function trocarSenha(Request $r): JsonResponse
    {
        $d = $r->validate([
            'senha_atual' => ['required', 'string'],
            'senha'       => ['required', 'string', Password::min(8), 'confirmed'],
        ], [
            'senha.confirmed' => 'A confirmação não confere com a nova senha.',
        ]);

        if (! Hash::check($d['senha_atual'], $r->user()->password)) {
            return response()->json(['message' => 'Senha atual incorreta.'], 422);
        }

        $r->user()->update(['password' => Hash::make($d['senha'])]);

        return response()->json(['message' => 'Senha alterada.']);
    }

    /** POST /api/perfil/assinatura — data URL de PNG vinda do canvas. */
    public function salvarAssinatura(Request $r, Assinatura $assinatura): JsonResponse
    {
        $d = $r->validate([
            // ~1 MB de data URL é folgado para um traço de canvas e ainda
            // barra o envio de uma foto disfarçada de assinatura.
            'assinatura' => ['required', 'string', 'max:1000000',
                             'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'],
        ], [
            'assinatura.regex' => 'Formato de assinatura inválido.',
        ]);

        // Aparada até o traço ANTES de guardar: o canvas tem a largura da tela
        // e a pessoa assina num pedaço dele, e é essa margem vazia que faz a
        // rubrica sair minúscula no meio do campo de assinatura do papel.
        // Corrigir na origem vale para tudo que exiba a assinatura depois.
        $r->user()->update(['assinatura' => $assinatura->aparar($d['assinatura'])]);

        return response()->json(['message' => 'Assinatura gravada.']);
    }

    /** DELETE /api/perfil/assinatura */
    public function excluirAssinatura(Request $r): JsonResponse
    {
        // Documentos já lavrados guardam a própria cópia da assinatura, então
        // apagar aqui não altera peça de processo nenhuma.
        $r->user()->update(['assinatura' => null]);

        return response()->json(['message' => 'Assinatura removida. Documentos já lavrados não mudam.']);
    }
}
