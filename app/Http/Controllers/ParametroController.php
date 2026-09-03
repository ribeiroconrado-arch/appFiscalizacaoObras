<?php

namespace App\Http\Controllers;

use App\Models\CadastroBairro;
use App\Models\Feriado;
use App\Models\Irregularidade;
use App\Models\Lote;
use App\Models\Parametro;
use App\Models\Upf;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
                'curador_cadastral' => (bool) $u->curador_cadastral,
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
            // Ordenados pelo CÓDIGO, que é como a lista da prefeitura vem e
            // como o cadastrador confere: procurar o 47 numa lista alfabética
            // é procurar duas vezes.
            'bairros' => CadastroBairro::orderByRaw('CAST(codigo AS UNSIGNED)')->get()
                ->map(fn (CadastroBairro $b) => [
                    'id' => $b->id, 'codigo' => $b->codigo,
                    'nome_cadastro' => $b->nome_cadastro, 'nome_gis' => $b->nome_gis,
                    'lotes' => $b->lotesEmUso(),
                ]),
            // Ordem de exibição = ordem de trabalho: `ordem` primeiro (para
            // priorizar as mais comuns), depois alfabético para desempatar.
            'irregularidades' => Irregularidade::orderBy('ordem')->orderBy('descricao')->get()
                ->map(fn (Irregularidade $i) => [
                    'id' => $i->id, 'codigo' => $i->codigo, 'descricao' => $i->descricao,
                    'gravidade' => $i->gravidade, 'base_legal' => $i->base_legal,
                    'ordem' => $i->ordem, 'ativo' => $i->ativo,
                    'em_uso' => DB::table('vistoria_irregularidades')->where('irregularidade_id', $i->id)->count(),
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
            // Única desde que a matrícula virou identificador de login: sem
            // isto, cadastrar uma repetida só falharia lá no banco, com erro
            // 500 e sem dizer ao usuário qual campo está errado.
            'matricula'    => ['nullable', 'string', 'max:30',
                Rule::unique('users', 'matricula')->ignore($r->input('id'))],
            'perfil'       => ['required', Rule::in(User::PERFIS)],
            'tipo_usuario' => ['required', Rule::in(User::CARGOS)],
            'ativo'        => ['nullable', 'boolean'],
            // Permissão para corrigir a base do mapa. Ver a migration
            // 2026_08_27_000100 para por que ela não é um perfil.
            'curador_cadastral' => ['nullable', 'boolean'],
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
                // Vazio vira NULO: '' não é distinto num índice único, então
                // dois usuários sem matrícula colidiriam entre si.
                'name' => $d['name'], 'email' => $d['email'],
                'matricula' => ($d['matricula'] ?? '') !== '' ? $d['matricula'] : null,
                'perfil' => $d['perfil'], 'tipo_usuario' => $d['tipo_usuario'], 'ativo' => $d['ativo'] ?? true,
                'curador_cadastral' => $d['curador_cadastral'] ?? false,
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

    // ── IRREGULARIDADES ──────────────────────────────────────────
    //
    // O catálogo que a vistoria oferece (VistoriaController::catalogo) só
    // lista as ATIVAS — apagar não é o caminho para tirar uma de circulação,
    // porque vistoria já lavrada continua apontando para ela e precisa achar
    // o nome. Desativar, sim; apagar, só quando nenhuma vistoria a usou.

    /** POST /api/parametros/irregularidades */
    public function salvarIrregularidade(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate([
            'id'          => ['nullable', 'exists:irregularidades,id'],
            'codigo'      => ['required', 'string', 'max:20',
                Rule::unique('irregularidades', 'codigo')->ignore($r->input('id'))],
            'descricao'   => ['required', 'string', 'max:200'],
            'gravidade'   => ['required', Rule::in(['leve', 'media', 'grave'])],
            'base_legal'  => ['nullable', 'string', 'max:200'],
            'ordem'       => ['nullable', 'integer', 'min:0', 'max:65535'],
            'ativo'       => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'código', 'descricao' => 'descrição', 'gravidade' => 'gravidade',
            'base_legal' => 'base legal',
        ]);

        Irregularidade::updateOrCreate(['id' => $d['id'] ?? null], [
            'codigo'     => $d['codigo'],
            'descricao'  => $d['descricao'],
            'gravidade'  => $d['gravidade'],
            'base_legal' => $d['base_legal'] ?? null,
            'ordem'      => $d['ordem'] ?? 0,
            'ativo'      => $d['ativo'] ?? true,
        ]);

        return response()->json(['message' => 'Irregularidade gravada.']);
    }

    /**
     * DELETE /api/parametros/irregularidades/{irregularidade}
     *
     * Recusa quando alguma vistoria já a usou — apagar apagaria o nome da
     * infração de um relatório já lavrado. "Desativar" (desmarcar Ativa e
     * salvar) é o caminho para tirá-la das próximas vistorias sem isso.
     */
    public function excluirIrregularidade(Request $r, Irregularidade $irregularidade): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $usos = DB::table('vistoria_irregularidades')
            ->where('irregularidade_id', $irregularidade->id)->count();

        if ($usos > 0) {
            return response()->json([
                'message' => sprintf(
                    'Não dá para excluir: %d vistoria(s) já constataram esta irregularidade. '
                    . 'Desmarque "Ativa" e salve — ela some das próximas vistorias sem apagar o histórico.',
                    $usos),
            ], 422);
        }

        $irregularidade->delete();

        return response()->json(['message' => 'Irregularidade removida.']);
    }

    // ── BAIRROS ──────────────────────────────────────────────────

    /**
     * POST /api/parametros/bairros — cria ou altera.
     *
     * `nome_gis` é o texto que os lotes convertidos do DWG guardam em
     * `lotes.bairro`, e é por ele que o sistema chega ao código do cadastro.
     * Fica NULO enquanto o bairro não foi levantado: bairro do município que
     * ainda não tem desenho existe para ser escolhido, e não tem nome de GIS
     * nenhum — inventar um faria a ponte casar com o que não devia.
     */
    public function salvarBairro(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $d = $r->validate([
            'id'            => ['nullable', 'exists:cadastro_bairros,id'],
            'codigo'        => ['required', 'string', 'max:10',
                Rule::unique('cadastro_bairros', 'codigo')->ignore($r->input('id'))],
            'nome_cadastro' => ['required', 'string', 'max:160'],
            'nome_gis'      => ['nullable', 'string', 'max:160',
                Rule::unique('cadastro_bairros', 'nome_gis')->ignore($r->input('id'))],
        ], [], [
            // Sem isto o erro sai como "Este nome gis já está cadastrado", com
            // o nome da COLUNA no lugar do nome do campo que a pessoa vê.
            'codigo'        => 'código',
            'nome_cadastro' => 'nome no cadastro',
            'nome_gis'      => 'nome no desenho',
        ]);

        $bairro = CadastroBairro::find($d['id'] ?? null) ?? new CadastroBairro();
        $anterior = $bairro->nome_gis;

        $bairro->fill([
            'codigo'        => $d['codigo'],
            'nome_cadastro' => $d['nome_cadastro'],
            'nome_gis'      => $d['nome_gis'] ?: null,
        ])->save();

        // TROCAR O NOME DE GIS DESLIGA OS LOTES.
        //
        // Os lotes guardam o TEXTO do bairro, não uma chave — mudar `nome_gis`
        // em silêncio deixaria N lotes apontando para um nome que não existe
        // mais em cadastro nenhum, e o código do bairro sumiria da ficha deles
        // sem ninguém notar. Aqui não se mexe nos lotes: só se diz o que houve.
        $aviso = null;
        if ($anterior && $anterior !== $bairro->nome_gis) {
            $presos = Lote::where('bairro', $anterior)->count();
            if ($presos > 0) {
                $aviso = sprintf(
                    '%d lote(s) continuam gravados como "%s" e deixaram de achar este bairro. '
                    . 'Corrija o bairro deles ou volte o nome do desenho.',
                    $presos, $anterior);
            }
        }

        return response()->json([
            'message' => 'Bairro gravado.',
            'aviso'   => $aviso,
        ]);
    }

    /**
     * DELETE /api/parametros/bairros/{bairro}
     *
     * Bairro com lote não se apaga. Não é zelo excessivo: o lote guarda o NOME
     * do bairro, e não uma chave — apagar a linha não quebraria nada na hora,
     * e por isso mesmo o estrago só apareceria depois, quando a ficha de um
     * imóvel daquele bairro deixasse de trazer o código do cadastro.
     */
    public function excluirBairro(Request $r, CadastroBairro $bairro): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        if ($presos = $bairro->lotesEmUso()) {
            return response()->json([
                'message' => sprintf(
                    'Não dá para excluir: %d lote(s) do desenho estão neste bairro. '
                    . 'Mova-os antes, ou apenas corrija o nome aqui.',
                    $presos),
            ], 422);
        }

        $bairro->delete();

        return response()->json(['message' => 'Bairro removido.']);
    }

    /**
     * POST /api/parametros/brasao — envia o brasão do município.
     *
     * O sistema não traz brasão nenhum embutido: é ele que permite instalar a
     * mesma aplicação em outro município sem tocar no código. Por isso mora
     * aqui, ao lado do nome da entidade, e não em public/img.
     *
     * O fundo branco de fora do desenho é removido no envio, pela mesma
     * inundação a partir dos cantos usada nos ícones do app (ver
     * tools/gerar-icones.php). Sem isso o brasão aparece dentro de um
     * retângulo branco em cima da barra colorida — que é exatamente o que se
     * quer evitar num cabeçalho institucional.
     */
    public function salvarBrasao(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $r->validate([
            'brasao' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:4096'],
        ], [
            'brasao.image' => 'Envie uma imagem PNG ou JPG.',
            'brasao.max'   => 'A imagem deve ter no máximo 4 MB.',
        ]);

        $img = $this->limparFundo($r->file('brasao')->getRealPath());
        if (! $img) {
            return response()->json(['message' => 'Não foi possível ler a imagem enviada.'], 422);
        }

        // Nome com carimbo de tempo: o navegador guarda o brasão em cache, e
        // sobrescrever o mesmo arquivo deixaria o anterior na tela.
        $nome = 'brasao-' . now()->format('YmdHis') . '.png';
        Storage::disk('public')->put('orgao/' . $nome, $img);

        $anterior = Parametro::get('brasao_url');
        Parametro::set('brasao_url', Storage::url('orgao/' . $nome));

        // O antigo sai do disco: guardar histórico de brasão não serve a nada
        // e só acumula arquivo.
        if ($anterior && str_contains($anterior, '/orgao/')) {
            Storage::disk('public')->delete('orgao/' . basename($anterior));
        }

        return response()->json([
            'message' => 'Brasão atualizado.',
            'url'     => Parametro::get('brasao_url'),
        ]);
    }

    /** DELETE /api/parametros/brasao */
    public function excluirBrasao(Request $r): JsonResponse
    {
        if ($erro = $this->exigirAdmin($r)) { return $erro; }

        $atual = Parametro::get('brasao_url');
        if ($atual && str_contains($atual, '/orgao/')) {
            Storage::disk('public')->delete('orgao/' . basename($atual));
        }
        Parametro::set('brasao_url', '');

        return response()->json(['message' => 'Brasão removido.']);
    }

    /**
     * Torna transparente o fundo de FORA do desenho.
     *
     * Inundação a partir dos quatro cantos: a área externa é alcançada, o
     * branco de dentro do brasão (que faz parte do desenho) fica fechado pelos
     * traços e não é atingido. O alfa da borda é calculado desfazendo a
     * mistura com branco, o que preserva o antisserrilhado em vez de recortá-lo
     * em degraus. Mesma técnica de tools/gerar-icones.php.
     *
     * @return string|null PNG binário, ou null se a imagem não puder ser lida
     */
    private function limparFundo(string $caminho): ?string
    {
        $src = @imagecreatefromstring(file_get_contents($caminho));
        if (! $src) { return null; }

        $L = imagesx($src);
        $A = imagesy($src);

        $fora = array_fill(0, $L * $A, false);
        $pilha = [0, $L - 1, ($A - 1) * $L, $A * $L - 1];

        while ($pilha) {
            $i = array_pop($pilha);
            if ($i < 0 || $i >= $L * $A || $fora[$i]) { continue; }

            $x = $i % $L;
            $y = intdiv($i, $L);
            $c = imagecolorat($src, $x, $y);

            if (min(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF) < 140) { continue; }

            $fora[$i] = true;
            if ($x > 0)      { $pilha[] = $i - 1; }
            if ($x < $L - 1) { $pilha[] = $i + 1; }
            if ($y > 0)      { $pilha[] = $i - $L; }
            if ($y < $A - 1) { $pilha[] = $i + $L; }
        }

        $out = imagecreatetruecolor($L, $A);
        imagealphablending($out, false);
        imagesavealpha($out, true);

        for ($y = 0; $y < $A; $y++) {
            for ($x = 0; $x < $L; $x++) {
                $c = imagecolorat($src, $x, $y);
                $vermelho = ($c >> 16) & 0xFF;

                if (! $fora[$y * $L + $x]) {
                    imagesetpixel($out, $x, $y, imagecolorallocatealpha(
                        $out, $vermelho, ($c >> 8) & 0xFF, $c & 0xFF, 0));
                    continue;
                }

                // Quanto deste pixel ainda é desenho? 0 = branco puro.
                $cobertura = max(0.0, min(1.0, (255 - $vermelho) / 255));
                $alfa = (int) round(127 * (1 - $cobertura));
                imagesetpixel($out, $x, $y, imagecolorallocatealpha(
                    $out, $vermelho, ($c >> 8) & 0xFF, $c & 0xFF, $alfa));
            }
        }

        ob_start();
        imagepng($out, null, 9);
        $png = ob_get_clean();

        imagedestroy($src);
        imagedestroy($out);

        return $png;
    }
}
