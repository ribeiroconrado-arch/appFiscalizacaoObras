<?php

namespace App\Cadastro;

use App\Models\CadastroCarga;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A CARGA MENSAL DO CADASTRO MUNICIPAL — grava só o que mudou.
 *
 * A prefeitura manda o município inteiro; não há como receber só a
 * diferença, mas dá para GRAVAR só a diferença:
 *
 *   1. lê a planilha uma vez e monta, por inscrição, o registro canônico e a
 *      impressão digital (hash) dele com os proprietários;
 *   2. compara com o hash gravado:
 *        igual       → nada (só o ponteiro "vista nesta carga");
 *        diferente   → atualiza e registra em `cadastro_alteracoes` os campos
 *                      que mudaram, com o valor de antes e o de depois;
 *        novo        → insere e registra "novo";
 *        sem hash    → linha de antes desta mecânica: vira BASE, sem histórico
 *                      (não há "antes" confiável);
 *   3. o que estava no banco e não veio fica AUSENTE — marcado, nunca apagado.
 *      Só nos bairros que vieram no arquivo: uma planilha de um bairro não
 *      pode dar o resto do município como desaparecido.
 *
 * Trava de planilha cortada: se mais de 20% dos imóveis desses bairros
 * ficariam ausentes, a carga para em `aguardando_confirmacao` e só segue com
 * a confirmação de quem enviou.
 */
final class CargaDoCadastro
{
    /** Acima desta fração de ausentes, a carga pede confirmação. */
    public const LIMITE_AUSENCIA = 0.20;

    private const BLOCO = 500;

    private const TABELA = 'cadastro_externo_imoveis';

    /** @var array<string,string> inscrição => registro canônico (JSON) */
    private array $registros = [];

    /** @var array<string, array<string,array>> inscrição => (chave => dono) */
    private array $donos = [];

    /** @var array<string,true> códigos de bairro presentes, sem zeros à esquerda */
    private array $bairros = [];

    public function processar(CadastroCarga $carga, string $arquivo, bool $confirmarAusencias = false): CadastroCarga
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $carga->update(['status' => 'processando', 'iniciada_em' => $carga->iniciada_em ?? now(), 'mensagem' => null]);

        $this->ler($carga, $arquivo);

        $atuais = $this->atuais();
        $plano = $this->classificar($atuais);

        $carga->fill([
            'bairros'      => array_keys($this->bairros),
            'novos'        => count($plano['novos']),
            'alterados'    => count($plano['alterados']),
            'iguais'       => count($plano['iguais']),
            'ausentes'     => count($plano['ausentes']),
            'reaparecidos' => count($plano['reaparecidos']),
            'primeira'     => count($plano['base']) > 0,
        ]);

        $universo = $plano['universo'];
        if (! $confirmarAusencias && $universo > 0 && count($plano['ausentes']) / $universo > self::LIMITE_AUSENCIA) {
            $carga->status = 'aguardando_confirmacao';
            $carga->mensagem = sprintf(
                '%s de %s imóveis destes bairros não vieram na planilha (%d%%). Se a planilha estiver completa, confirme as ausências.',
                number_format(count($plano['ausentes']), 0, ',', '.'),
                number_format($universo, 0, ',', '.'),
                round(100 * count($plano['ausentes']) / $universo)
            );
            $carga->save();

            return $carga;
        }
        $carga->save();

        $this->gravarNovos($carga, $plano['novos']);
        $this->gravarAlterados($carga, $plano['alterados'], $atuais);
        $this->gravarBase($carga, $plano['base']);
        $this->marcarVistos($carga, $plano['iguais']);
        $this->marcarReaparecidos($carga, $plano['reaparecidos']);
        $this->marcarAusentes($carga, $plano['ausentes']);

        $carga->update(['status' => 'concluida', 'concluida_em' => now(), 'mensagem' => null]);

        return $carga;
    }

    // ── leitura ─────────────────────────────────────────────────

    private function ler(CadastroCarga $carga, string $arquivo): void
    {
        $leitor = new LeitorXlsx($arquivo);
        $posicao = null;
        $lidas = 0;

        foreach ($leitor->linhas() as $celulas) {
            if ($posicao === null) {
                $posicao = ColunasDaExportacao::cabecalho($celulas);
                continue;
            }

            $ler = fn (string $col) => isset($posicao[$col]) ? trim($celulas[$posicao[$col]] ?? '') : '';
            $r = ColunasDaExportacao::linha($ler);
            if ($r === null) {
                continue;
            }

            $insc = $r['inscricao'];
            // Linha por unidade: a última linha da inscrição vale para o imóvel
            // (como no upsert de antes), e os donos de todas as linhas somam.
            $this->registros[$insc] = json_encode($r, JSON_UNESCAPED_UNICODE);
            if ($dono = ColunasDaExportacao::proprietario($ler)) {
                $this->donos[$insc][mb_strtolower($dono['nome'] . '|' . $dono['documento'])] = $dono;
            }
            if ($r['codigo_bairro'] !== null) {
                $this->bairros[ltrim($r['codigo_bairro'], '0')] = true;
            }

            if (++$lidas % 2000 === 0) {
                $carga->update(['linhas_lidas' => $lidas]);
            }
        }

        if ($posicao === null) {
            throw new RuntimeException('Não achei a linha de cabeçalho (a que tem a coluna "Inscrição").');
        }
        if (! $this->registros) {
            throw new RuntimeException('Nenhuma linha com inscrição. A planilha está vazia ou é de outro formato.');
        }

        $carga->update(['linhas_lidas' => $lidas]);
    }

    // ── comparação ──────────────────────────────────────────────

    /** @return array<string, object{hash:?string, ausente_desde_carga_id:?int, codigo_bairro:?string}> */
    private function atuais(): array
    {
        $atuais = [];
        foreach (DB::table(self::TABELA)->select('inscricao', 'hash', 'ausente_desde_carga_id', 'codigo_bairro')->cursor() as $l) {
            $atuais[$l->inscricao] = $l;
        }

        return $atuais;
    }

    /** @return array<string, mixed> */
    private function classificar(array $atuais): array
    {
        $p = ['novos' => [], 'alterados' => [], 'base' => [], 'iguais' => [], 'reaparecidos' => [], 'ausentes' => [], 'universo' => 0];

        foreach ($this->registros as $insc => $json) {
            $hash = DiferencaDoCadastro::hash(json_decode($json, true), array_values($this->donos[$insc] ?? []));
            $atual = $atuais[$insc] ?? null;

            if ($atual === null) {
                $p['novos'][$insc] = $hash;
                continue;
            }
            if ($atual->ausente_desde_carga_id !== null) {
                $p['reaparecidos'][] = $insc;
            }
            if ($atual->hash === null) {
                $p['base'][$insc] = $hash;
            } elseif ($atual->hash !== $hash) {
                $p['alterados'][$insc] = $hash;
            } else {
                $p['iguais'][] = $insc;
            }
        }

        foreach ($atuais as $insc => $atual) {
            if (! isset($this->bairros[ltrim((string) $atual->codigo_bairro, '0')])) {
                continue;   // bairro que não veio no arquivo: fora do alcance desta carga
            }
            $p['universo']++;
            if (! isset($this->registros[$insc]) && $atual->ausente_desde_carga_id === null) {
                $p['ausentes'][] = $insc;
            }
        }

        return $p;
    }

    // ── gravação ────────────────────────────────────────────────

    /** @param array<string,string> $novos inscrição => hash */
    private function gravarNovos(CadastroCarga $carga, array $novos): void
    {
        foreach (array_chunk($novos, self::BLOCO, true) as $bloco) {
            DB::transaction(function () use ($carga, $bloco) {
                $linhas = $alteracoes = [];
                foreach ($bloco as $insc => $hash) {
                    $linhas[] = $this->registro($insc) + $this->controle($carga, $hash) + [
                        'alterado_na_carga_id' => $carga->id,
                        'created_at'           => now(),
                    ];
                    $alteracoes[] = $this->alteracao($carga, $insc, 'novo');
                }
                DB::table(self::TABELA)->insert($linhas);
                $this->substituirDonos(array_keys($bloco));
                DB::table('cadastro_alteracoes')->insert($alteracoes);
            });
        }
    }

    /** @param array<string,string> $alterados inscrição => hash */
    private function gravarAlterados(CadastroCarga $carga, array $alterados, array $atuais): void
    {
        foreach (array_chunk($alterados, self::BLOCO, true) as $bloco) {
            DB::transaction(function () use ($carga, $bloco, $atuais) {
                $inscricoes = array_keys($bloco);
                $antigas = DB::table(self::TABELA)->whereIn('inscricao', $inscricoes)->get()->keyBy('inscricao');
                $donosAntigos = $this->donosGravados($inscricoes);

                $alteracoes = [];
                foreach ($bloco as $insc => $hash) {
                    $novo = $this->registro($insc);
                    foreach (DiferencaDoCadastro::campos((array) $antigas[$insc], $novo) as $campo => [$antes, $depois]) {
                        $alteracoes[] = $this->alteracao($carga, $insc, 'alterado', $campo, $antes, $depois);
                    }
                    $antesDonos = DiferencaDoCadastro::proprietarios($donosAntigos[$insc] ?? []);
                    $depoisDonos = DiferencaDoCadastro::proprietarios(array_values($this->donos[$insc] ?? []));
                    if ($antesDonos !== $depoisDonos) {
                        $alteracoes[] = $this->alteracao($carga, $insc, 'alterado', 'proprietarios', $antesDonos, $depoisDonos);
                    }

                    DB::table(self::TABELA)->where('inscricao', $insc)->update(
                        $novo + $this->controle($carga, $hash) + ['alterado_na_carga_id' => $carga->id]
                    );
                }
                $this->substituirDonos($inscricoes);
                foreach (array_chunk($alteracoes, self::BLOCO) as $parte) {
                    DB::table('cadastro_alteracoes')->insert($parte);
                }
            });
        }
    }

    /**
     * Linhas de antes desta mecânica (sem hash): recebem o dado e o hash, e
     * viram a base da comparação seguinte. Sem histórico — não há "antes".
     *
     * @param array<string,string> $base inscrição => hash
     */
    private function gravarBase(CadastroCarga $carga, array $base): void
    {
        foreach (array_chunk($base, self::BLOCO, true) as $bloco) {
            DB::transaction(function () use ($carga, $bloco) {
                foreach ($bloco as $insc => $hash) {
                    DB::table(self::TABELA)->where('inscricao', $insc)
                        ->update($this->registro($insc) + $this->controle($carga, $hash));
                }
                $this->substituirDonos(array_keys($bloco));
            });
        }
    }

    /** "Últ. integração" de quem veio igual: só o ponteiro, em blocos. */
    private function marcarVistos(CadastroCarga $carga, array $inscricoes): void
    {
        foreach (array_chunk($inscricoes, 1000) as $bloco) {
            DB::table(self::TABELA)->whereIn('inscricao', $bloco)
                ->update(['vista_na_carga_id' => $carga->id, 'updated_at' => now()]);
        }
    }

    private function marcarReaparecidos(CadastroCarga $carga, array $inscricoes): void
    {
        foreach (array_chunk($inscricoes, self::BLOCO) as $bloco) {
            DB::transaction(function () use ($carga, $bloco) {
                DB::table(self::TABELA)->whereIn('inscricao', $bloco)->update(['ausente_desde_carga_id' => null]);
                DB::table('cadastro_alteracoes')->insert(
                    array_map(fn ($i) => $this->alteracao($carga, $i, 'reapareceu'), $bloco)
                );
            });
        }
    }

    private function marcarAusentes(CadastroCarga $carga, array $inscricoes): void
    {
        foreach (array_chunk($inscricoes, self::BLOCO) as $bloco) {
            DB::transaction(function () use ($carga, $bloco) {
                DB::table(self::TABELA)->whereIn('inscricao', $bloco)
                    ->update(['ausente_desde_carga_id' => $carga->id, 'updated_at' => now()]);
                DB::table('cadastro_alteracoes')->insert(
                    array_map(fn ($i) => $this->alteracao($carga, $i, 'ausente'), $bloco)
                );
            });
        }
    }

    // ── peças ───────────────────────────────────────────────────

    /** @return array<string,?string> */
    private function registro(string $insc): array
    {
        return json_decode($this->registros[$insc], true);
    }

    /** @return array<string,mixed> */
    private function controle(CadastroCarga $carga, string $hash): array
    {
        return [
            'hash'                   => $hash,
            'vista_na_carga_id'      => $carga->id,
            'ausente_desde_carga_id' => null,
            'arquivo_origem'         => mb_substr($carga->arquivo_nome, 0, 255),
            'importado_em'           => now(),
            'updated_at'             => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function alteracao(CadastroCarga $carga, string $insc, string $tipo,
                               ?string $campo = null, ?string $antes = null, ?string $depois = null): array
    {
        return ['carga_id' => $carga->id, 'inscricao' => $insc, 'tipo' => $tipo,
            'campo' => $campo, 'antes' => $antes, 'depois' => $depois, 'created_at' => now()];
    }

    /** @return array<string, list<array>> inscrição => donos gravados, em ordem */
    private function donosGravados(array $inscricoes): array
    {
        $donos = [];
        foreach (DB::table('cadastro_proprietarios')->whereIn('inscricao', $inscricoes)
                     ->orderBy('inscricao')->orderBy('ordem')->get() as $d) {
            $donos[$d->inscricao][] = ['nome' => $d->nome, 'documento' => $d->documento, 'endereco' => $d->endereco];
        }

        return $donos;
    }

    private function substituirDonos(array $inscricoes): void
    {
        DB::table('cadastro_proprietarios')->whereIn('inscricao', $inscricoes)->delete();

        $linhas = [];
        foreach ($inscricoes as $insc) {
            foreach (array_values($this->donos[$insc] ?? []) as $ordem => $dono) {
                $linhas[] = $dono + ['inscricao' => $insc, 'ordem' => $ordem,
                    'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($linhas, self::BLOCO) as $parte) {
            DB::table('cadastro_proprietarios')->insert($parte);
        }
    }
}
