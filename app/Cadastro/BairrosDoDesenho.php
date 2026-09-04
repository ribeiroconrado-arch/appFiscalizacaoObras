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

    /** @var array<string,string>|null chave normalizada => nome oficial */
    private ?array $oficiais = null;

    /**
     * O NOME OFICIAL do bairro, a partir do nome que está no desenho.
     *
     * ── Dois nomes para o mesmo lugar, e qual vale onde ──
     *
     * O desenho vem do DWG e traz o nome que o topógrafo escreveu
     * ("Residencial Buritis V"). O cadastro da prefeitura tem o nome de
     * registro ("RESIDENCIAL BURITIS PRIMAVERA V - PRIME"). São o mesmo
     * bairro, e a amarração entre eles é `nome_gis`.
     *
     * Fora do mapa, vale o OFICIAL: é ele que identifica o bairro em busca,
     * documento e peça — o nome do desenho é apelido de trabalho, e um auto de
     * infração que cite bairro por apelido cita um bairro que não existe no
     * registro do município. No mapa continua o do desenho, que é o que está
     * escrito na planta que o fiscal tem à frente.
     *
     * Sem amarração devolve o nome do desenho: é o único que se conhece, e uma
     * lista com a coluna de bairro vazia seria pior do que uma com o apelido.
     */
    public function oficial(?string $nomeDoDesenho): ?string
    {
        if ($nomeDoDesenho === null || $nomeDoDesenho === '') {
            return null;
        }

        if ($this->oficiais === null) {
            $this->oficiais = [];
            foreach (DB::table('cadastro_bairros')->whereNotNull('nome_gis')->get() as $b) {
                $this->oficiais[self::chave($b->nome_gis)] = $b->nome_cadastro;
            }
        }

        return $this->oficiais[self::chave($nomeDoDesenho)] ?? $nomeDoDesenho;
    }

    /**
     * O caminho de volta: os nomes DE DESENHO de um bairro oficial.
     *
     * É o que faz o filtro funcionar. Quem escolhe "RESIDENCIAL BURITIS
     * PRIMAVERA V - PRIME" na tela precisa achar lotes gravados como
     * "Residencial Buritis V" — a coluna `lotes.bairro` guarda o do desenho, e
     * é nela que a consulta bate.
     *
     * Devolve o próprio termo junto, para o caso de alguém filtrar pelo nome do
     * desenho (uma URL antiga, um favorito) — continuar funcionando é de graça.
     *
     * @return array<int,string>
     */
    public function nomesDeDesenhoDe(?string $oficial): array
    {
        if ($oficial === null || $oficial === '') {
            return [];
        }

        $alvo = self::chave($oficial);
        $nomes = DB::table('cadastro_bairros')
            ->whereNotNull('nome_gis')->get()
            ->filter(fn ($b) => self::chave($b->nome_cadastro) === $alvo)
            ->pluck('nome_gis')
            ->all();

        return array_values(array_unique([...$nomes, $oficial]));
    }

    /**
     * Os nomes de desenho cujo nome OFICIAL contém o texto procurado.
     *
     * Serve ao campo único da busca, onde se digita um pedaço do nome. Sem
     * isto, procurar por "Primavera" — que é como o bairro se chama no
     * registro — não acharia nada, porque a coluna guarda "Buritis V".
     *
     * @return array<int,string>
     */
    public function desenhosQueCasam(string $termo): array
    {
        $t = self::chave($termo);
        if ($t === '') {
            return [];
        }

        return DB::table('cadastro_bairros')
            ->whereNotNull('nome_gis')->get()
            ->filter(fn ($b) => str_contains(self::chave($b->nome_cadastro), $t))
            ->pluck('nome_gis')
            ->unique()->values()->all();
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
