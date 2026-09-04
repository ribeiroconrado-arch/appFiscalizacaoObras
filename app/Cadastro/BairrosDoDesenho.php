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
    /** @var array<string,string>|null nome no desenho => código no cadastro */
    private ?array $codigos = null;

    /** @return array<string,string> */
    public function codigos(): array
    {
        return $this->codigos ??= DB::table('cadastro_bairros')
            ->whereNotNull('nome_gis')
            ->pluck('codigo', 'nome_gis')
            ->all();
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
            $this->codigos()[$l->bairro] ?? null,
            $l->quadra ?? null,
            $l->numero_lote ?? null,
            $l->desmembramento ?? 0
        ));
    }
}
