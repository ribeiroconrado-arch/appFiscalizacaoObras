<?php

namespace App\Http\Controllers;

use App\Models\Feriado;
use App\Models\Parametro;
use App\Models\Upf;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Parâmetros do sistema — usuários, dados do órgão, UPF e feriados.
 *
 * Tudo aqui é reservado a administrador: são as engrenagens que decidem se um
 * documento pode ser lavrado (feriado errado vicia prazo de defesa; UPF
 * errada vicia valor de multa) e quem tem acesso ao quê.
 */
class ParametroController extends Controller
{
    private function exigirAdmin(Request $r): ?JsonResponse
    {
        return $r->user()->isAdmin()
            ? null
            : response()->json(['message' => 'Só administrador acessa os parâmetros do sistema.'], 403);
    }

    /** GET /api/parametros — tudo que a tela precisa, de uma vez. */
    public function index(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        return response()->json([
            'usuarios' => User::orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'matricula' => $u->matricula, 'perfil' => $u->perfil,
                'tipo_usuario' => $u->tipo_usuario, 'ativo' => $u->ativo,
                'perfil_rotulo' => $u->perfilRotulo(),
            ]),
            'geral' => collect(Parametro::CHAVES)->map(fn ($def, $chave) => [
                'chave' => $chave, 'descricao' => $def[2],
                'valor' => Parametro::get($chave, $def[0]),
            ])->values(),
            // Data pura (Y-m-d), não o datetime ISO que o cast 'date' serializa
            // por padrão — senão a tela mostra "01T00:00:00.000000Z/01/2026".
            'upfs' => Upf::orderByDesc('exercicio')->get()->map(fn (Upf $u) => [
                'id' => $u->id, 'exercicio' => $u->exercicio, 'valor' => $u->valor,
                'vigencia_inicio' => $u->vigencia_inicio->format('Y-m-d'), 'norma' => $u->norma,
            ]),
            'feriados' => Feriado::orderBy('data')->get()->map(fn (Feriado $f) => [
                'id' => $f->id, 'data' => $f->data->format('Y-m-d'), 'nome' => $f->nome,
                'tipo' => $f->tipo, 'recorrente' => $f->recorrente,
            ]),
            'perfis' => User::PERFIS,
            'cargos' => User::CARGOS,
            'tipos_feriado' => collect(Feriado::TIPOS)->map(fn ($r, $v) => ['valor' => $v, 'rotulo' => $r])->values(),
        ]);
    }

    // ── USUÁRIOS ─────────────────────────────────────────────────

    /** POST /api/parametros/usuarios — cria ou atualiza. Senha só se informada. */
    public function salvarUsuario(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate([
            'id'           => ['nullable', 'exists:users,id'],
            'name'         => ['required', 'string', 'max:160'],
            'email'        => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($r->input('id'))],
            'matricula'    => ['nullable', 'string', 'max:30'],
            'perfil'       => ['required', Rule::in(User::PERFIS)],
            'tipo_usuario' => ['required', Rule::in(User::CARGOS)],
            'ativo'        => ['nullable', 'boolean'],
            'senha'        => ['nullable', 'string', Password::min(8), 'confirmed'],
        ]);

        // Não deixar o admin se rebaixar ou desativar sozinho: travaria o
        // acesso ao próprio módulo de parâmetros sem ninguém para reverter.
        if (($d['id'] ?? null) == $r->user()->id
            && ($d['perfil'] !== 'admin' || ($d['ativo'] ?? true) === false)) {
            return response()->json(['message' => 'Você não pode remover seu próprio acesso de administrador.'], 422);
        }

        $usuario = User::updateOrCreate(
            ['id' => $d['id'] ?? null],
            [
                'name' => $d['name'], 'email' => $d['email'], 'matricula' => $d['matricula'] ?? null,
                'perfil' => $d['perfil'], 'tipo_usuario' => $d['tipo_usuario'], 'ativo' => $d['ativo'] ?? true,
            ]
        );

        if (! empty($d['senha'])) {
            $usuario->update(['password' => Hash::make($d['senha'])]);
        }

        return response()->json(['message' => 'Usuário gravado.', 'id' => $usuario->id]);
    }

    // ── PARÂMETROS GERAIS ────────────────────────────────────────

    /** POST /api/parametros/geral — grava um lote de chave/valor. */
    public function salvarGeral(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate(['valores' => ['required', 'array']]);

        foreach ($d['valores'] as $chave => $valor) {
            if (! array_key_exists($chave, Parametro::CHAVES)) { continue; }
            Parametro::set($chave, (string) $valor);
        }

        return response()->json(['message' => 'Parâmetros gravados.']);
    }

    // ── UPF ──────────────────────────────────────────────────────

    /** POST /api/parametros/upf */
    public function salvarUpf(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate([
            'exercicio'       => ['required', 'integer', 'min:2020', 'max:2100'],
            'valor'           => ['required', 'numeric', 'min:0.0001'],
            'vigencia_inicio' => ['required', 'date'],
            'norma'           => ['nullable', 'string', 'max:80'],
        ]);

        Upf::updateOrCreate(['exercicio' => $d['exercicio']], $d);

        return response()->json(['message' => 'UPF gravada.']);
    }

    public function excluirUpf(Request $r, Upf $upf): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }
        $upf->delete();
        return response()->json(['message' => 'UPF removida.']);
    }

    // ── FERIADOS ─────────────────────────────────────────────────

    /** POST /api/parametros/feriados */
    public function salvarFeriado(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate([
            'id'         => ['nullable', 'exists:feriados,id'],
            'data'       => ['required', 'date'],
            'nome'       => ['required', 'string', 'max:80'],
            'tipo'       => ['required', Rule::in(array_keys(Feriado::TIPOS))],
            'recorrente' => ['nullable', 'boolean'],
        ]);

        Feriado::updateOrCreate(['id' => $d['id'] ?? null], $d + ['recorrente' => $d['recorrente'] ?? false]);

        return response()->json(['message' => 'Feriado gravado.']);
    }

    public function excluirFeriado(Request $r, Feriado $feriado): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }
        $feriado->delete();
        return response()->json(['message' => 'Feriado removido.']);
    }
}
