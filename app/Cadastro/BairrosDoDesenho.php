<?php

namespace App\Cadastro;

use App\Support\InscricaoImobiliaria;
use Illuminate\Support\Facades\DB;

/**
 * A ponte entre o nome do bairro NO DESENHO e o código dele NO CADASTRO.
 *
 * O desenho vem do DWG e traz o bairro por extenso ("Residencial Buritis V");
 * o cadastro da prefeitura o identifica por um código de três dígitos, que é
 * o segundo pedaço da inscrição imobiliária. `cadastro_bairros.nome_gis` é a
 * amarração entre os dois, feita à mão em Parâmetros.
 *
 * Existe porque a mesma tradução é precisa em três telas — o mapa, a consulta
 * e a ficha —, e cada uma delas devolve MUITAS linhas de uma vez: consultar o
 * cadastro por lote faria uma ida ao banco por lote. Aqui o mapa inteiro é
 * lido uma vez e fica em memória pelo tempo da requisição.
 */
class BairrosDoDesenho
{
    /** @var array<string,string>|null chave normalizada => código no cadastro */
    private ?array $codigos = null;

    /**
     * O mapa, com as chaves NORMALIZADAS.
     *
     * ── Por que normalizar, e o que isso custou para aprender ──
     *
     * A busca por bairro sempre foi um `WHERE nome_gis = ?` no MySQL, e as
     * colunas são `utf8mb4_..._ci`: comparação SEM diferença de maiúsculas.
     * Quem digitou "RESIDENCIAL BURITIS V" em Parâmetros casava com o
     * "Residencial Buritis V" do desenho, e ninguém precisou pensar nisso.
     *
     * Trazer o mapa para a memória — que é o que evita uma consulta por lote —
     * trocou a comparação do banco por índice de array PHP, que é byte a byte.
     * A amarração feita em produção parou de valer da noite para o dia, sem
     * erro nenhum: a inscrição simplesmente sumiu de todas as telas. Otimizar
     * mudou a SEMÂNTICA junto com o desempenho, que é o modo mais silencioso
     * de quebrar uma coisa.
     *
     * `chave()` refaz aqui o que a colação fazia lá: caixa e acento não
     * distinguem bairro nenhum, e quem preenche o campo à mão não deveria ter
     * de acertar os dois.
     *
     * @return array<string,string>
     */
    public function codigos(): array
    {
        if ($this->codigos !== null) {
            return $this->codigos;
        }

        $this->codigos = [];
        foreach (DB::table('cadastro_bairros')->whereNotNull('nome_gis')->get() as $b) {
            $this->codigos[self::chave($b->nome_gis)] = $b->codigo;
        }

        return $this->codigos;
    }

    /**
     * A forma comparável de um nome de bairro: sem caixa, sem acento, sem
     * espaço sobrando. É o que a colação do banco já fazia.
     */
    public static function chave(?string $nome): string
    {
        $n = trim((string) $nome);
        $n = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n) ?: $n;

        return mb_strtolower(preg_replace('/\s+/', ' ', $n));
    }

    /**
     * A inscrição de um lote — a gravada, ou a que as partes dele formam.
     *
     * Aceita modelo ou linha crua do banco: as três telas que chamam isto
     * consultam de jeitos diferentes, e todas têm os mesmos quatro campos.
     *
     * Ver Lote::inscricao() para o porquê de derivar em vez de guardar, e
     * InscricaoImobiliaria para o formato.
     *
     * @param  object  $l  com bairro, quadra, numero_lote e desmembramento
     */
    public function inscricaoDe(object $l): ?string
    {
        $gravada = InscricaoImobiliaria::normalizar($l->inscricao_imobiliaria ?? null);
        if ($gravada !== null) {
            return InscricaoImobiliaria::formatar($gravada);
        }

        return InscricaoImobiliaria::formatar(InscricaoImobiliaria::montar(
            $this->codigos()[self::chave($l->bairro ?? null)] ?? null,
            $l->quadra ?? null,
            $l->numero_lote ?? null,
            $l->desmembramento ?? 0
        ));
    }
}
