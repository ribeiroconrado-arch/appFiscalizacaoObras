<?php

namespace App\Posturas\Importacao;

use App\Models\Artigo;
use App\Models\Legislacao;
use App\Models\Logradouro;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Traz o AppPOSTURAS para cá: denúncias, cadastros (bairros, ruas, rua↔bairro,
 * contribuintes, decibelímetros), leis e configurações (feriados, UPF e a
 * tabela `config`, guardada para conferência). Os documentos NÃO vêm — eram de
 * teste.
 *
 * Pode rodar quantas vezes for preciso: cada linha é reconhecida pelo
 * `origem_id` (o uuid de lá) — ou pela chave natural, onde não há id (bairro e
 * rua pelo nome, contribuinte pelo CPF/CNPJ, UPF pelo ano, feriado pela data).
 * O que já existe é atualizado; nada é apagado.
 *
 * Feriado e UPF são do município, e o Obras já tem os seus: o que coincide
 * fica como está aqui, e a divergência sai no relatório para conferir.
 *
 * Grava pelo construtor de consultas, e não pelos modelos, de propósito: a
 * auditoria registraria milhares de "criou" sem informação nenhuma. No fim vai
 * UMA linha de auditoria por tabela, com a contagem.
 */
class ImportadorPosturas
{
    /** O fuso das datas do Posturas: a hora lá é a de Primavera do Leste. */
    private const FUSO = 'America/Cuiaba';

    /** @var array<string, array{lidos:int,novos:int,atualizados:int,ignorados:int}> */
    public array $relatorio = [];

    /** @var list<string> */
    public array $avisos = [];

    /** Snapshot da tabela `config` de lá — gravado em arquivo pelo comando. */
    public array $config = [];

    /** @var array<string, ?int> nome do agente no Posturas → id do usuário aqui */
    private array $agentes = [];

    /**
     * @param  bool  $simular  na simulação nenhum arquivo é escrito: o banco o comando
     *                         desfaz, mas um PDF salvo ficaria para trás
     */
    public function __construct(private ClienteSupabase $sb, private bool $criarUsuarios = false, private bool $simular = false) {}

    // ── ORDEM ────────────────────────────────────────────────────

    /** As etapas, na ordem em que dependem umas das outras. */
    public const ETAPAS = ['usuarios', 'bairros', 'logradouros', 'rua_bairro', 'contribuintes',
        'decibelimetros', 'leis', 'feriados', 'upf', 'config', 'denuncias'];

    /** @param list<string> $so só estas etapas (vazio = todas) */
    public function importar(array $so = []): void
    {
        foreach (self::ETAPAS as $etapa) {
            if ($so && ! in_array($etapa, $so, true)) {
                continue;
            }
            $this->{'importar' . Str::studly($etapa)}();
        }
        $this->avisarCortes();
    }

    // ── USUÁRIOS — só para ligar a denúncia ao agente ────────────

    /**
     * A denúncia de lá aponta o agente pelo NOME (`denuncia.agente`). Aqui ele
     * é o usuário: casado pela matrícula, depois pelo e-mail, depois pelo nome.
     * Quem não existe aqui fica só com o nome na denúncia — ou, com
     * --criar-usuarios, ganha uma conta DESATIVADA, só de Posturas, que o
     * administrador ativa e para a qual define a senha.
     */
    public function importarUsuarios(): void
    {
        $linhas = $this->sb->todas('usuarios', 'id,nome,login,perfil,tipo_usuario,matricula,email,ativo', 'nome.asc');
        $r = $this->novaEtapa('usuarios', count($linhas));

        foreach ($linhas as $u) {
            $nome = trim((string) ($u['nome'] ?? ''));
            if ($nome === '') { $r['ignorados']++; continue; }

            $aqui = User::query()
                ->when($u['matricula'] ?? null, fn ($q, $m) => $q->orWhere('matricula', $m))
                ->when($u['email'] ?? null, fn ($q, $e) => $q->orWhere('email', $e))
                ->orWhere('name', $nome)
                ->first();

            if ($aqui) {
                // Já existe: passa a ter Posturas também (sem tirar o que tinha).
                $mods = array_values(array_unique([...((array) $aqui->getAttribute('modulos') ?: ['obras']), 'posturas']));
                DB::table('users')->where('id', $aqui->id)->update(['modulos' => json_encode($mods)]);
                $this->agentes[$nome] = $aqui->id;
                $r['atualizados']++;
                continue;
            }

            if (! $this->criarUsuarios) {
                $this->agentes[$nome] = null;
                $this->avisos[] = "Usuário do Posturas sem conta aqui: {$nome}" . (($u['matricula'] ?? null) ? " (mat. {$u['matricula']})" : '') . ' — as denúncias dele ficam só com o nome.';
                $r['ignorados']++;
                continue;
            }

            $tipo = in_array($u['tipo_usuario'] ?? null, User::CARGOS, true) ? $u['tipo_usuario'] : 'agente';
            $id = DB::table('users')->insertGetId($this->caber('users', [
                'name' => $nome, 'email' => ($u['email'] ?? null) ?: null, 'matricula' => ($u['matricula'] ?? null) ?: null,
                'perfil' => in_array($u['perfil'] ?? null, User::PERFIS, true) && $u['perfil'] !== 'admin' ? $u['perfil'] : 'comum',
                'tipo_usuario' => $tipo, 'modulos' => json_encode(['posturas']),
                // Desativado e com senha sorteada que ninguém conhece: entra
                // depois que o administrador ativar e definir a senha.
                'ativo' => false, 'password' => Hash::make(Str::random(40)),
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->agentes[$nome] = $id;
            $this->avisos[] = "Conta criada DESATIVADA para {$nome} — ative e defina a senha em Parâmetros › Usuários.";
            $r['novos']++;
        }

        $this->fecharEtapa('usuarios', $r);
    }

    // ── BAIRROS, RUAS E RUA↔BAIRRO ───────────────────────────────

    public function importarBairros(): void
    {
        $linhas = $this->sb->todas('bairros', 'nome,codigo', 'nome.asc');
        $r = $this->novaEtapa('bairros', count($linhas));

        foreach ($linhas as $b) {
            // Cortado ANTES de procurar: o nome guardado é o cortado, e é com ele
            // que a próxima importação tem de achar o mesmo bairro.
            ['nome' => $nome, 'codigo' => $codigo] = $this->caber('bairros_endereco',
                ['nome' => Logradouro::normalizar($b['nome'] ?? ''), 'codigo' => ($b['codigo'] ?? null) ?: null]);
            if ($nome === '') { $r['ignorados']++; continue; }
            $existe = DB::table('bairros_endereco')->where('nome', $nome)->first();
            if ($existe) {
                DB::table('bairros_endereco')->where('id', $existe->id)
                    ->update(['codigo' => $codigo ?: $existe->codigo, 'updated_at' => now()]);
                $r['atualizados']++;
            } else {
                DB::table('bairros_endereco')->insert(['nome' => $nome, 'codigo' => $codigo,
                    'created_at' => now(), 'updated_at' => now()]);
                $r['novos']++;
            }
        }

        $this->fecharEtapa('bairros_endereco', $r);
    }

    public function importarLogradouros(): void
    {
        $linhas = $this->sb->todas('logradouros', 'nome', 'nome.asc');
        $r = $this->novaEtapa('logradouros', count($linhas));

        foreach ($linhas as $l) {
            $nome = Logradouro::normalizar($l['nome'] ?? '');
            if ($nome === '') { $r['ignorados']++; continue; }
            $this->idDoLogradouro($nome, $novo);
            $novo ? $r['novos']++ : $r['ignorados']++;
        }

        $this->fecharEtapa('logradouros', $r);
    }

    public function importarRuaBairro(): void
    {
        $linhas = $this->sb->todas('rua_bairro', 'rua,bairro', 'rua.asc');
        $r = $this->novaEtapa('rua_bairro', count($linhas));

        foreach ($linhas as $v) {
            $rua = Logradouro::normalizar($v['rua'] ?? '');
            $bairro = $this->caber('logradouro_bairros', ['bairro' => Logradouro::normalizar($v['bairro'] ?? '')])['bairro'];
            if ($rua === '' || $bairro === '') { $r['ignorados']++; continue; }

            $id = $this->idDoLogradouro($rua);
            $ja = DB::table('logradouro_bairros')->where('logradouro_id', $id)->where('bairro', $bairro)->exists();
            if ($ja) { $r['ignorados']++; continue; }

            DB::table('logradouro_bairros')->insert(['logradouro_id' => $id, 'bairro' => $bairro,
                'created_at' => now(), 'updated_at' => now()]);
            $r['novos']++;
        }

        $this->fecharEtapa('logradouro_bairros', $r);
    }

    private function idDoLogradouro(string $nome, ?bool &$novo = null): int
    {
        $nome = $this->caber('logradouros', ['nome' => $nome])['nome'];
        $novo = false;
        $id = DB::table('logradouros')->where('nome', $nome)->value('id');
        if ($id) {
            return (int) $id;
        }
        $novo = true;

        return (int) DB::table('logradouros')->insertGetId(['nome' => $nome, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── CONTRIBUINTES E DECIBELÍMETROS ───────────────────────────

    public function importarContribuintes(): void
    {
        $linhas = $this->sb->todas('contribuintes', '*', 'criado_em.asc');
        $r = $this->novaEtapa('contribuintes', count($linhas));

        foreach ($linhas as $c) {
            $doc = trim((string) ($c['cpf_cnpj'] ?? ''));
            if ($doc === '') { $r['ignorados']++; continue; }

            $dados = [
                'origem_id' => $c['id'] ?? null,
                'nome' => $c['nome'] ?? null, 'inscricao_municipal' => $c['inscricao_municipal'] ?? null,
                'email' => $c['email'] ?? null, 'logradouro' => $c['logradouro'] ?? null,
                'numero' => $c['numero'] ?? null, 'bairro' => $c['bairro'] ?? null,
                'cidade' => $c['cidade'] ?? null, 'estado' => mb_substr((string) ($c['estado'] ?? ''), 0, 2) ?: null,
                'updated_at' => $this->utc($c['atualizado_em'] ?? null) ?? now(),
            ];
            $this->gravar('contribuintes', ['cpf_cnpj' => $doc], $dados + ['created_at' => $this->utc($c['criado_em'] ?? null) ?? now()], $r);
        }

        $this->fecharEtapa('contribuintes', $r);
    }

    public function importarDecibelimetros(): void
    {
        $linhas = $this->sb->todas('decibelimetros', '*', 'criado_em.asc');
        $r = $this->novaEtapa('decibelimetros', count($linhas));

        foreach ($linhas as $d) {
            $this->gravar('decibelimetros', ['origem_id' => $d['id']], [
                'tipo' => ($d['tipo'] ?? null) ?: 'Decibelímetro Digital',
                'fabricante' => $d['fabricante'] ?? null, 'modelo' => $d['modelo'] ?? null,
                'identificacao' => $d['identificacao'] ?? null, 'serie' => $d['serie'] ?? null,
                'certificado_calibracao' => $d['certificado_calibracao'] ?? null,
                'empresa_calibradora' => $d['empresa_calibradora'] ?? null,
                'data_calibracao' => $d['data_calibracao'] ?? null,
                'validade_calibracao_meses' => $d['validade_calibracao_meses'] ?? null,
                'certificado_pdf_url' => $this->certificado($d),
                'certificado_pdf_nome' => $d['certificado_pdf_nome'] ?? null,
                'created_at' => $this->utc($d['criado_em'] ?? null) ?? now(), 'updated_at' => now(),
            ], $r);
        }

        $this->fecharEtapa('decibelimetros', $r);
    }

    /**
     * O Posturas guarda o PDF do certificado DENTRO da linha, como `data:` em
     * base64 (centenas de KB). Aqui ele vira arquivo em
     * storage/app/private/decibelimetros e a coluna guarda só o caminho. Um
     * endereço http (Storage do Supabase) fica como veio.
     */
    private function certificado(array $d): ?string
    {
        $url = $d['certificado_pdf_url'] ?? null;
        if (! $url || ! str_starts_with($url, 'data:')) {
            return $url ?: null;
        }

        $pdf = base64_decode(substr($url, (int) strpos($url, ',') + 1), true);
        if (! $pdf) {
            $this->avisos[] = 'Decibelímetro ' . trim(($d['modelo'] ?? '') . ' ' . ($d['serie'] ?? '')) . ': o PDF do certificado veio corrompido e ficou de fora.';

            return null;
        }

        $caminho = 'decibelimetros/certificado-' . $d['id'] . '.pdf';
        if (! $this->simular) {
            Storage::disk('local')->put($caminho, $pdf);
        }

        return $caminho;
    }

    // ── LEIS E ARTIGOS ───────────────────────────────────────────

    /**
     * Cada lei do Posturas vira uma lei do MÓDULO Posturas, mesmo que Obras
     * tenha uma de mesmo número: as peças, os textos de ciência e os artigos
     * marcados são de cada fiscalização.
     */
    public function importarLeis(): void
    {
        $linhas = $this->sb->todas('leis', '*', 'nome.asc');
        $r = $this->novaEtapa('leis', count($linhas));
        $this->relatorio['artigos'] = $this->novaEtapa('artigos', 0);

        foreach ($linhas as $l) {
            $dados = [
                'modulo' => 'posturas',
                'numero' => mb_substr(($l['numero_lei'] ?? null) ?: ($l['nome'] ?? 'Lei'), 0, 40),
                'nome' => mb_substr((string) ($l['nome'] ?? ''), 0, 160),
                'data_publicacao' => $l['data_publicacao'] ?? null,
                'ano' => ! empty($l['data_publicacao']) ? (int) substr($l['data_publicacao'], 0, 4) : $this->anoDoNumero($l['numero_lei'] ?? $l['nome'] ?? ''),
                'prazo_defesa_dias' => (int) (($l['prazo_defesa_dias'] ?? null) ?: 5),
                'prazo_cumprimento_dias' => 0,
                'ciencia_notificacao' => $l['ciencia_notif_texto'] ?? null,
                'ciencia_auto' => $l['ciencia_auto_texto'] ?? null,
                'ativa' => (bool) ($l['ativo'] ?? true),
                'formulario_tipo' => ($l['formulario_tipo'] ?? null) ?: 'padrao',
                'faixa_db' => $this->json($l['faixa_db'] ?? null),
                'areas_db' => $this->json($l['areas_db'] ?? null),
                'lavratura_imediata_itens' => $this->json($l['lavratura_imediata_itens'] ?? null),
                'artigo_multa' => $l['artigo_multa'] ?? null,
                'aplica_apreensao' => (bool) ($l['aplica_apreensao'] ?? false),
                'aplica_retencao' => (bool) ($l['aplica_retencao'] ?? false),
                'artigo_apreensao' => $l['artigo_apreensao'] ?? null,
                'artigo_retencao' => $l['artigo_retencao'] ?? null,
                'ciencia_apreensao' => $l['ciencia_apreensao_texto'] ?? null,
                'ciencia_retencao' => $l['ciencia_retencao_texto'] ?? null,
                'aplica_reincidencia' => (bool) ($l['aplica_reincidencia'] ?? false),
                'artigo_reincidencia' => $l['artigo_reincidencia'] ?? null,
                'texto_reincidencia' => $l['texto_reincidencia'] ?? null,
                'updated_at' => now(),
            ];
            $leiId = $this->gravar('legislacoes', ['origem_id' => $l['id']], $dados + ['created_at' => $this->utc($l['criado_em'] ?? null) ?? now()], $r);

            $artigos = is_array($l['artigos'] ?? null) ? $l['artigos'] : (json_decode((string) ($l['artigos'] ?? '[]'), true) ?: []);
            $this->importarArtigos($leiId, $artigos, $dados);
        }

        $this->fecharEtapa('legislacoes', $r);
        $this->fecharEtapa('artigos', $this->relatorio['artigos']);
    }

    /** @param list<array<string,mixed>> $artigos o JSON `leis.artigos` de lá */
    private function importarArtigos(int $leiId, array $artigos, array $lei): void
    {
        $r = &$this->relatorio['artigos'];
        $r['lidos'] += count($artigos);
        $documentos = json_encode(Artigo::documentosDoModulo('posturas'));
        $ruido = $lei['formulario_tipo'] === 'lei_723_2002';
        // No AppPOSTURAS a multa do auto da Lei 723 sai SEMPRE das faixas de
        // dB da lei, qualquer que seja o artigo; `artigo_multa` é só o artigo
        // citado como penalidade no papel ("art. 17"), e nem precisa estar na
        // lista de artigos (na base real não estava: a lei tinha só o Art. 1).
        // Por isso: vale o artigo que bate com `artigo_multa`; não batendo
        // nenhum, os que têm multa (faixa de UPF) são os calculados por dB.
        $temArtigoDaMulta = $ruido && collect($artigos)->contains(
            fn ($a) => $this->mesmoArtigo(trim((string) ($a['numero'] ?? '')), $lei['artigo_multa'] ?? null));

        foreach ($artigos as $i => $a) {
            $numero = trim((string) ($a['numero'] ?? ''));
            if ($numero === '') { $r['ignorados']++; continue; }
            $min = isset($a['upf_min']) && $a['upf_min'] !== '' ? (float) $a['upf_min'] : null;
            $max = isset($a['upf_max']) && $a['upf_max'] !== '' ? (float) $a['upf_max'] : null;

            // A base da multa: na Lei 723 o artigo da multa é calculado pelos
            // decibéis; com faixa de UPF, a critério do fiscal; sem nada, só
            // fundamenta (como os artigos antigos de lá, sem upf_min/max).
            $temMulta = $min !== null || $max !== null;
            $base = match (true) {
                $ruido && $temArtigoDaMulta && $this->mesmoArtigo($numero, $lei['artigo_multa'] ?? null) => 'diferenca_db',
                $ruido && ! $temArtigoDaMulta && $temMulta => 'diferenca_db',
                $temMulta => 'intervalo',
                default => 'sem_multa',
            };

            $this->gravar('artigos', ['legislacao_id' => $leiId, 'origem_id' => (string) ($a['id'] ?? 'pos-' . $i)], [
                'numero' => mb_substr($numero, 0, 30),
                'apelido' => ($a['apelido'] ?? null) ?: null,
                'conduta' => $a['texto'] ?? null,
                'base_multa' => $base,
                'multa_min_upf' => $min, 'multa_max_upf' => $max ?? $min,
                'documentos' => $documentos,
                'termos' => json_encode([]),
                'ativo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ], $r);
        }
    }

    private function mesmoArtigo(string $a, ?string $b): bool
    {
        $limpa = fn (?string $s) => preg_replace('/[^0-9a-z]/', '', mb_strtolower(str_replace(['Art.', 'art.', 'º', '°'], '', (string) $s)));

        return $b !== null && $limpa($a) !== '' && $limpa($a) === $limpa($b);
    }

    private function anoDoNumero(string $numero): ?int
    {
        return preg_match('/\/(\d{4})\b/', $numero, $m) ? (int) $m[1] : null;
    }

    // ── CONFIGURAÇÕES ────────────────────────────────────────────

    /** Feriado é do município: o que já existe aqui na mesma data fica. */
    public function importarFeriados(): void
    {
        $linhas = $this->sb->todas('feriados', 'data,descricao', 'data.asc');
        $r = $this->novaEtapa('feriados', count($linhas));

        foreach ($linhas as $f) {
            if (empty($f['data'])) { $r['ignorados']++; continue; }
            if (DB::table('feriados')->whereDate('data', $f['data'])->exists()) { $r['ignorados']++; continue; }

            DB::table('feriados')->insert([
                'data' => $f['data'], 'nome' => mb_substr(($f['descricao'] ?? null) ?: 'Feriado', 0, 80),
                'tipo' => 'municipal', 'recorrente' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $r['novos']++;
        }

        $this->fecharEtapa('feriados', $r);
    }

    /** A UPF do ano: a que existe aqui não é trocada; a diferença é avisada. */
    public function importarUpf(): void
    {
        $linhas = $this->sb->todas('upf_anual', 'ano,valor', 'ano.asc');
        $r = $this->novaEtapa('upf', count($linhas));

        foreach ($linhas as $u) {
            $ano = (int) ($u['ano'] ?? 0);
            if ($ano < 1900) { $r['ignorados']++; continue; }
            $aqui = DB::table('upfs')->where('exercicio', $ano)->first();
            if ($aqui) {
                if (abs((float) $aqui->valor - (float) $u['valor']) > 0.00005) {
                    $this->avisos[] = "UPF {$ano}: aqui R$ {$aqui->valor}, no Posturas R$ {$u['valor']} — mantida a daqui; confira.";
                }
                $r['ignorados']++;
                continue;
            }
            DB::table('upfs')->insert([
                'exercicio' => $ano, 'valor' => $u['valor'], 'vigencia_inicio' => $ano . '-01-01',
                'norma' => 'Importada do AppPOSTURAS', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $r['novos']++;
        }

        $this->fecharEtapa('upfs', $r);
    }

    /**
     * A tabela `config` de lá não é lida em lugar nenhum do app e não tem
     * colunas definidas no repositório. Vem inteira para conferência, num
     * arquivo — não há onde encaixá-la sem saber o que é.
     */
    public function importarConfig(): void
    {
        try {
            $this->config = $this->sb->todas('config');
        } catch (\RuntimeException $e) {
            $this->avisos[] = 'Tabela config não lida: ' . $e->getMessage();
            $this->config = [];
        }
        $this->relatorio['config'] = ['lidos' => count($this->config), 'novos' => 0, 'atualizados' => 0, 'ignorados' => count($this->config)];
    }

    // ── DENÚNCIAS ────────────────────────────────────────────────

    public function importarDenuncias(): void
    {
        $linhas = $this->sb->todas('denuncia', '*', 'data.asc');
        $r = $this->novaEtapa('denuncias', count($linhas));
        if (! $this->agentes) {
            // Sem a etapa de usuários: casa pelo nome com quem já existe aqui.
            $this->agentes = User::pluck('id', 'name')->all();
        }

        foreach ($linhas as $d) {
            $quando = $this->dataLocal($d['data'] ?? null) ?? $this->local($d['criado_em'] ?? null);
            if (! $quando) {
                $this->avisos[] = 'Denúncia ' . ($d['protocolo'] ?? $d['id']) . ' sem data — não importada.';
                $r['ignorados']++;
                continue;
            }

            $protocolo = trim((string) ($d['protocolo'] ?? '')) ?: null;
            if ($protocolo && DB::table('denuncias')->where('protocolo', $protocolo)->where('origem_id', '<>', $d['id'])->exists()) {
                $this->avisos[] = "Protocolo {$protocolo} repetido no Posturas — a segunda denúncia entrou sem protocolo.";
                $protocolo = null;
            }

            $agente = trim((string) ($d['agente'] ?? ''));
            $logs = is_array($d['logs'] ?? null) ? $d['logs'] : (json_decode((string) ($d['logs'] ?? '[]'), true) ?: []);
            $tratativa = trim((string) ($d['tratativa'] ?? '')) ?: null;

            $this->gravar('denuncias', ['origem_id' => $d['id']], [
                'protocolo' => $protocolo,
                'data_hora' => $quando,
                'agente_id' => $this->agentes[$agente] ?? null,
                'agente_nome' => $agente ?: null,
                'agente_matricula' => ($d['matricula'] ?? null) ?: null,
                'logradouro' => Logradouro::normalizar($d['logradouro'] ?? '') ?: null,
                'numero' => trim((string) ($d['numero'] ?? '')) ?: null,
                'bairro' => Logradouro::normalizar($d['bairro'] ?? '') ?: null,
                'local_tipo' => ($d['local_tipo'] ?? null) ?: 'Local Exato',
                'lat' => $d['lat'] ?? null, 'lng' => $d['lon'] ?? null, 'gps_precisao' => $d['gps_precisao'] ?? null,
                'fonte_sonora' => ($d['fonte_sonora'] ?? null) ?: null,
                'resp_nome' => ($d['nome_resp'] ?? null) ?: null, 'resp_cpf' => ($d['cpf'] ?? null) ?: null,
                'empresa_nome' => ($d['nome_empresa'] ?? null) ?: null, 'empresa_cnpj' => ($d['cnpj'] ?? null) ?: null,
                'placa' => ($d['placa'] ?? null) ?: null,
                'veic_marca' => ($d['veic_marca'] ?? null) ?: null, 'veic_modelo' => ($d['veic_modelo'] ?? null) ?: null,
                'veic_cor' => ($d['veic_cor'] ?? null) ?: null,
                'tratativa' => $tratativa,
                'obs' => ($d['obs'] ?? null) ?: null,
                'cancelado_por_nome' => ($d['cancelado_por'] ?? null) ?: null,
                'cancelado_matricula' => ($d['cancelado_mat'] ?? null) ?: null,
                'cancelado_em' => $tratativa === 'Cancelado' ? $this->quandoCancelou($logs, $d['atualizado_em'] ?? null) : null,
                'logs' => json_encode($logs, JSON_UNESCAPED_UNICODE),
                'created_at' => $this->utc($d['criado_em'] ?? null) ?? now(),
                'updated_at' => $this->utc($d['atualizado_em'] ?? null) ?? now(),
            ], $r);
        }

        $semAgente = collect($linhas)->pluck('agente')->filter()->unique()
            ->reject(fn ($n) => isset($this->agentes[trim($n)]) && $this->agentes[trim($n)] !== null);
        foreach ($semAgente as $n) {
            $this->avisos[] = "Denúncias de \"{$n}\" ficaram sem usuário ligado (só o nome).";
        }

        $this->fecharEtapa('denuncias', $r);
    }

    /** O instante do cancelamento: o do log de CANCELAMENTO, ou a última alteração. */
    private function quandoCancelou(array $logs, ?string $atualizado): ?string
    {
        foreach (array_reverse($logs) as $l) {
            if (($l['acao'] ?? '') === 'CANCELAMENTO' && ! empty($l['ts'])) {
                return $this->utc($l['ts']);
            }
        }

        return $this->utc($atualizado);
    }

    // ── APOIO ────────────────────────────────────────────────────

    /**
     * Insere ou atualiza pela chave. Devolve o id.
     *
     * @param array<string,mixed> $chave
     * @param array<string,mixed> $dados  `created_at` só vale na inserção
     * @param array{lidos:int,novos:int,atualizados:int,ignorados:int} $r
     */
    private function gravar(string $tabela, array $chave, array $dados, array &$r): int
    {
        $chave = $this->caber($tabela, $chave);
        $dados = $this->caber($tabela, $dados);
        $existente = DB::table($tabela)->where($chave)->value('id');
        if ($existente) {
            unset($dados['created_at']);
            DB::table($tabela)->where('id', $existente)->update($dados);
            $r['atualizados']++;

            return (int) $existente;
        }

        $r['novos']++;

        return (int) DB::table($tabela)->insertGetId($chave + $dados);
    }

    /** @var array<string, array<string, int>> tabela → coluna → tamanho, lido do banco uma vez */
    private array $limites = [];

    /** @var array<string, int> "tabela.coluna" → quantos textos foram cortados */
    private array $cortes = [];

    /**
     * O texto que não cabe na coluna é CORTADO, e não derruba a importação.
     *
     * No AppPOSTURAS as colunas são `text`, sem limite; aqui elas têm tamanho
     * (com folga — 2026_10_29_000100). Uma denúncia com observação enfiada no
     * campo do número parava tudo, e nada era gravado. Agora ela entra, com o
     * texto cortado no tamanho da coluna, e o relatório diz quantos cortes
     * houve em cada coluna para conferir.
     */
    private function caber(string $tabela, array $dados): array
    {
        $limites = $this->limites[$tabela] ??= collect(Schema::getColumns($tabela))
            ->mapWithKeys(fn ($c) => [$c['name'] => preg_match('/^(?:var)?char\((\d+)\)/i', (string) $c['type'], $m) ? (int) $m[1] : 0])
            ->filter()->all();

        foreach ($dados as $coluna => $valor) {
            if (is_string($valor) && isset($limites[$coluna]) && mb_strlen($valor) > $limites[$coluna]) {
                $dados[$coluna] = mb_substr($valor, 0, $limites[$coluna]);
                $this->cortes["$tabela.$coluna"] = ($this->cortes["$tabela.$coluna"] ?? 0) + 1;
            }
        }

        return $dados;
    }

    /** Os cortes, um aviso por coluna (e não um por linha), no fim da importação. */
    private function avisarCortes(): void
    {
        foreach ($this->cortes as $onde => $n) {
            $this->avisos[] = "{$onde}: {$n} texto(s) maior(es) que a coluna — entraram cortados; confira.";
        }
        $this->cortes = [];
    }

    /** "2026-10-20T23:40" (hora local, sem fuso) → "2026-10-20 23:40:00". */
    private function dataLocal(?string $s): ?string
    {
        if (! $s || ! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?/', $s, $m)) {
            return null;
        }

        return $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
    }

    /** timestamptz de lá → hora local de Primavera do Leste, sem fuso. */
    private function local(?string $s): ?string
    {
        return $s ? Carbon::parse($s)->setTimezone(self::FUSO)->format('Y-m-d H:i:s') : null;
    }

    /** timestamptz de lá → UTC, como os created_at daqui. */
    private function utc(?string $s): ?string
    {
        return $s ? Carbon::parse($s)->utc()->format('Y-m-d H:i:s') : null;
    }

    private function json(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /** @return array{lidos:int,novos:int,atualizados:int,ignorados:int} */
    private function novaEtapa(string $nome, int $lidos): array
    {
        return ['lidos' => $lidos, 'novos' => 0, 'atualizados' => 0, 'ignorados' => 0];
    }

    /** Guarda a contagem e deixa UMA linha de auditoria por tabela. */
    private function fecharEtapa(string $tabela, array $r): void
    {
        $this->relatorio[$tabela] = $r;
        if ($r['novos'] + $r['atualizados'] === 0) {
            return;
        }
        $u = auth()->user();
        DB::table('auditoria')->insert([
            'user_id' => $u?->id, 'usuario_nome' => $u?->name ?? 'importação', 'matricula' => $u?->matricula,
            'acao' => 'importou', 'tabela' => $tabela,
            'descricao' => mb_substr("AppPOSTURAS: {$r['novos']} novo(s), {$r['atualizados']} atualizado(s) de {$r['lidos']} lido(s)", 0, 200),
            'created_at' => now(),
        ]);
    }
}
