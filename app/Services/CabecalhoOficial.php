<?php

namespace App\Services;

use App\Models\Parametro;

/**
 * O cabeçalho institucional das peças impressas — de onde quer que elas saiam.
 *
 * Nasceu dentro de DocumentoImpressao, porque só o documento imprimia. Quando
 * a ordem de serviço passou a imprimir também, copiar estas vinte linhas para
 * lá criaria dois cabeçalhos que envelhecem separados: mudar o nome da
 * secretaria em Parâmetros passaria a corrigir um papel e não o outro.
 *
 * Nada aqui é fixo no código: tudo vem de Parâmetros, porque outra prefeitura
 * — ou a mesma, depois de uma reforma administrativa — muda o cabeçalho sem
 * tocar em arquivo nenhum.
 */
class CabecalhoOficial
{
    public function orgao(): array
    {
        return [
            'nome'         => Parametro::get('orgao_nome'),
            'secretaria'   => Parametro::get('orgao_secretaria'),
            'departamento' => Parametro::get('orgao_departamento'),
            'divisao'      => Parametro::get('orgao_divisao'),
            'municipio'    => Parametro::get('orgao_municipio'),
            'endereco'     => Parametro::get('orgao_endereco'),
            'telefone'     => Parametro::get('orgao_telefone'),
            'cnpj'         => Parametro::get('orgao_cnpj'),
            'selo'         => Parametro::get('impressao_selo'),
        ];
    }

    /**
     * Brasão do município. Ausente, o cabeçalho fecha sem ele — travar a
     * emissão de uma peça porque falta uma imagem seria pior do que emiti-la
     * sem o símbolo.
     *
     * O dompdf não segue URL: recebe o caminho do arquivo no disco.
     */
    public function brasao(bool $paraPdf): ?string
    {
        foreach (['img/brasao-prefeitura.png', 'img/brasao.png'] as $relativo) {
            $caminho = public_path($relativo);
            if (is_file($caminho)) {
                return $paraPdf ? $caminho : '/' . $relativo;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public function rodape(): array
    {
        return array_values(array_filter([
            Parametro::get('rodape_protocolo'),
            Parametro::get('rodape_ouvidoria'),
        ]));
    }
}
