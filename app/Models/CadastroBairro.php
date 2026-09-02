<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * O bairro do município — código do cadastro, nome oficial e o nome que o
 * desenho usa.
 *
 * São TRÊS coisas diferentes de propósito:
 *
 *   codigo         a identidade do bairro no cadastro da prefeitura
 *   nome_cadastro  como o cadastro o escreve ("JARDIM EUROPA IV")
 *   nome_gis       como o DWG o escreve ("Jardim Europa IV"), quando já foi
 *                  convertido — nulo enquanto não foi
 *
 * `nome_gis` é o que amarra ao lote: `lotes.bairro` guarda esse texto, e é por
 * ele que o sistema chega ao código do cadastro (ver CadastroCarregado).
 * Enquanto ele for nulo, o bairro existe para escolher no cadastro de lote,
 * mas nenhum lote está preso a ele.
 */
class CadastroBairro extends Model
{
    use RegistraAuditoria;

    protected $table = 'cadastro_bairros';

    protected $fillable = ['nome_gis', 'codigo', 'nome_cadastro'];

    /** Quantos lotes do desenho estão neste bairro. */
    public function lotesEmUso(): int
    {
        return $this->nome_gis
            ? Lote::where('bairro', $this->nome_gis)->count()
            : 0;
    }

    /**
     * O nome a mostrar: o do cadastro é o oficial; o do desenho socorre.
     *
     * Chama-se `rotulo` e não `nome` de propósito. `nome` é um dos nomes que o
     * Eloquent procura como ATRIBUTO — e, não achando coluna, tentou resolver
     * o método como relação e derrubou toda gravação com "must return a
     * relationship instance". Método público sem argumento e com nome de campo
     * é uma armadilha em qualquer modelo.
     */
    public function rotulo(): string
    {
        return $this->nome_cadastro ?: ($this->nome_gis ?: '(sem nome)');
    }

    /**
     * O que a trilha de auditoria mostra no lugar do id.
     *
     * Sem isto, a busca padrão do trait cairia em `codigo` e a linha diria
     * apenas "105" — que não identifica bairro nenhum para quem lê depois.
     */
    protected function descricaoAuditoria(): ?string
    {
        return trim($this->codigo . ' · ' . $this->rotulo());
    }
}
