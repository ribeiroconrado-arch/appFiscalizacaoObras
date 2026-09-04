<?php

namespace App\Services;

use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Vistoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A mecânica comum do desmembramento e da unificação.
 *
 * Os dois atos são a mesma coisa vista de dois lados: N lotes deixam de
 * existir, M passam a existir, e um registro amarra os dois conjuntos ao
 * processo administrativo que autorizou a mudança.
 *
 * Nenhum lote é excluído. O antigo fica INATIVO, apontando para os sucessores.
 * Excluir seria destrutivo em silêncio: `vistorias` e `obras` têm FK em CASCADE
 * e iriam junto sem aviso, e o auto de infração já lavrado (FK RESTRICT)
 * trancaria a operação pela metade. Além disso o auto se refere ÀQUELE imóvel,
 * e a peça de processo precisa continuar apontando para o que existia quando
 * foi lavrada.
 */
class SucessaoDeLotes
{
    /**
     * Abre o ato, baixa os antecessores e amarra os sucessores. Tudo numa
     * transação: um ato pela metade é pior do que nenhum.
     *
     * @param  list<int>  $anteriores  lotes que deixam de existir
     * @param  list<int>  $posteriores lotes já criados que passam a existir
     * @param  'desmembramento'|'unificacao'  $tipo
     */
    public function registrar(
        string $tipo,
        array $anteriores,
        array $posteriores,
        ?Protocolo $protocolo = null,
        ?string $modo = null,
        ?string $observacao = null,
    ): int {
        return DB::transaction(function () use ($tipo, $anteriores, $posteriores, $protocolo, $modo, $observacao) {
            $ato = DB::table('lote_atos')->insertGetId([
                'tipo'         => $tipo,
                'protocolo_id' => $protocolo?->id,
                'user_id'      => Auth::id(),
                'modo'         => $modo,
                'observacao'   => $observacao,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            // A área é CONGELADA no momento do ato. Permite conferir anos
            // depois que a soma bateu, mesmo que alguém retifique a geometria
            // de um sucessor no meio do caminho.
            $vincular = function (array $ids, string $papel) use ($ato) {
                foreach (Lote::whereIn('id', $ids)->get() as $lote) {
                    DB::table('lote_ato_lotes')->insert([
                        'ato_id'  => $ato,
                        'lote_id' => $lote->id,
                        'papel'   => $papel,
                        'area_m2' => $lote->area_gis_m2,
                    ]);
                }
            };

            $vincular($anteriores, 'anterior');
            $vincular($posteriores, 'posterior');

            // Pelo Eloquent, para cada baixa deixar a linha "baixou" na
            // trilha de auditoria (ver App\Models\Lote::acaoAuditoria).
            foreach (Lote::whereIn('id', $anteriores)->get() as $lote) {
                $lote->update(['situacao' => 'inativo', 'inativado_em' => now()]);
            }

            return $ato;
        });
    }

    /**
     * O protocolo cujo desmembramento ou unificação esta vistoria atende.
     *
     * O vínculo mora em `protocolos.vistoria_id` — uma vistoria atende um
     * protocolo. Lido daqui, e não do lado da vistoria, porque a coluna já
     * existia e duplicá-la criaria duas verdades.
     */
    public function protocoloDaVistoria(Vistoria|int $vistoria): ?Protocolo
    {
        $id = $vistoria instanceof Vistoria ? $vistoria->id : $vistoria;

        return Protocolo::where('vistoria_id', $id)
            ->whereIn('tipo', ['desmembramento', 'unificacao'])
            ->first();
    }

    /**
     * Esta vistoria autoriza um ato cadastral?
     *
     * A vistoria é o portão, e não o protocolo direto, porque o ato altera o
     * terreno: alguém tem de ter ido lá ver. O deferimento diz que o pedido
     * procede no papel; a vistoria diz que o que está no papel é o que está no
     * chão.
     *
     * Devolve o retrato do que a tela precisa saber — inclusive o motivo de
     * não poder, que é o que ensina o usuário a destravar.
     *
     * @return array{pode:bool, tipo:?string, protocolo:?array, motivo:?string}
     */
    public function atoDaVistoria(Vistoria $vistoria): array
    {
        $vazio = ['pode' => false, 'tipo' => null, 'protocolo' => null, 'motivo' => null];

        $protocolo = $this->protocoloDaVistoria($vistoria);
        if (! $protocolo) {
            return $vazio;   // vistoria comum: nem menciona o assunto
        }

        $retrato = $vazio;
        $retrato['tipo'] = $protocolo->tipo;
        $retrato['protocolo'] = [
            'id' => $protocolo->id, 'numero' => $protocolo->numero,
            'tipo' => $protocolo->tipo, 'rotulo' => $protocolo->rotuloTipo(),
        ];

        // Só vistoria REGULAR libera. Vistoria que apontou problema não é
        // fundamento para alterar o cadastro — é fundamento para exigir
        // correção antes.
        if ($vistoria->situacao !== 'regular') {
            $retrato['motivo'] = 'A vistoria está como "' . $vistoria->situacaoRotulo()
                . '". Só vistoria Regular autoriza alterar o cadastro do imóvel.';
            return $retrato;
        }

        if ($erro = $this->impedimentoDoProtocolo($protocolo, $protocolo->tipo)) {
            $retrato['motivo'] = $erro;
            return $retrato;
        }

        $retrato['pode'] = true;

        return $retrato;
    }

    /**
     * O protocolo autoriza este ato?
     *
     * Devolve a mensagem do impedimento, ou null. As três condições são
     * cumulativas e cada uma tem razão própria:
     *
     *   tipo         desmembrar a partir de um protocolo de habite-se seria
     *                ato sem fundamento no processo que o pediu;
     *   deferido     o ato executa uma decisão; antes dela não há o que
     *                executar, e a decisão exige parecer (ProtocoloController);
     *   sem ato      um deferimento, um ato. A trava REAL é o índice único em
     *                lote_atos.protocolo_id; esta prova existe para a mensagem
     *                ser legível em vez de uma violação de chave.
     */
    public function impedimentoDoProtocolo(?Protocolo $protocolo, string $tipoAto, bool $direto = false): ?string
    {
        // ATO DIRETO — sem protocolo, e de propósito.
        //
        // O caminho normal é o de cima: o contribuinte requer, o setor defere,
        // o fiscal vai a campo, e só então o desenho muda. Mas o mapa vem de um
        // DWG que nem sempre acompanha o cartório: há lotes já unificados ou
        // desmembrados no mundo real e inteiros no desenho. Aí não há
        // protocolo a esperar — o ato não DECIDE nada, apenas põe o mapa em dia
        // com o que já aconteceu.
        //
        // Quem pode é o curador do cadastro, e o ato fica registrado com o
        // usuário e a justificativa em `lote_atos`: sem protocolo, a
        // responsabilidade é de quem executou, e por isso ela é nomeada.
        if ($direto) {
            return null;
        }

        if (! $protocolo) {
            return 'Desmembramento e unificação só acontecem a partir de um protocolo.';
        }

        $esperado = $tipoAto === 'unificacao' ? 'unificacao' : 'desmembramento';
        if ($protocolo->tipo !== $esperado) {
            return sprintf('O protocolo %s é do tipo "%s". Para este ato ele precisa ser "%s".',
                $protocolo->numero, $protocolo->rotuloTipo(), Protocolo::TIPOS[$esperado]);
        }

        if ($protocolo->situacao !== 'deferido') {
            return sprintf('O protocolo %s está como "%s". O ato executa uma decisão: '
                . 'defira o protocolo, com parecer, antes de executá-lo.',
                $protocolo->numero, $protocolo->situacaoBadge()[0]);
        }

        $ato = DB::table('lote_atos')->where('protocolo_id', $protocolo->id)->first();
        if ($ato) {
            return sprintf('O protocolo %s já foi executado em %s. Um deferimento, um ato.',
                $protocolo->numero, date('d/m/Y', strtotime($ato->created_at)));
        }

        return null;
    }
}
