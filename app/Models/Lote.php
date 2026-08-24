<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lote extends Model
{
    /**
     * A identificação do imóvel é a chave que amarra o sistema ao cadastro
     * imobiliário: mudá-la muda a que imóvel um auto lavrado se refere. Ainda
     * assim o Lote era o único registro dessa gravidade fora da trilha — a
     * correção de quadra alterava a identificação sem deixar rastro de quem,
     * quando e do quê para quê.
     */
    use RegistraAuditoria {
        // O apelido é obrigatório: método de trait sobrescrito na classe some
        // do alcance, e `parent::` não chega nele (o pai é o Model, que não
        // tem o método). Sem isto, `acaoAuditoria()` abaixo não teria como
        // cair no comportamento padrão para os campos que não trata.
        acaoAuditoria as private acaoAuditoriaPadrao;
    }

    protected $table = 'lotes';
    protected $guarded = [];

    /**
     * Sem esta declaracao o Eloquent devolve `baixado_em` como STRING, e
     * qualquer `->format()` sobre ela estoura em tempo de execucao — o que
     * so aparece quando alguem abre a ficha de um lote baixado.
     */
    protected function casts(): array
    {
        return ['baixado_em' => 'datetime', 'area_gis_m2' => 'float'];
    }

    /**
     * Colunas seguras para o Eloquent.
     *
     * `geom` fica FORA de propósito: um `SELECT *` traria a geometria como
     * binário WKB, que estoura na serialização para JSON e ainda carrega
     * kilobytes por linha sem necessidade. Consulta espacial é assunto do
     * LoteRepository, que devolve GeoJSON quando o mapa precisa.
     */
    public const COLUNAS = [
        'id', 'bairro', 'quadra', 'numero_lote', 'desmembramento', 'chave',
        'inscricao_imobiliaria', 'area_gis_m2', 'fonte', 'origem',
        'situacao', 'baixado_em',
        'created_at', 'updated_at',
    ];

    /**
     * O escopo restringe o SELECT à lista acima, e é aí que mora a armadilha:
     * COLUNA NOVA QUE NÃO ENTRAR EM `COLUNAS` FICA INVISÍVEL AO ELOQUENT. Não
     * dá erro — `$lote->situacao` simplesmente devolve null, o filtro passa
     * como se estivesse tudo certo, e a auditoria compara contra um original
     * que não tem o campo. Quem acrescentar coluna em `lotes` acrescenta aqui,
     * no mesmo commit.
     *
     * `chave_identidade` fica DE FORA de propósito: é coluna gerada pelo
     * banco, e expô-la ao Eloquent convidaria alguém a tentar escrevê-la.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('sem_geometria', function (Builder $q) {
            $q->select(array_map(fn ($c) => 'lotes.' . $c, self::COLUNAS));
        });
    }

    /**
     * Imóveis que existem hoje.
     *
     * Deliberadamente NÃO é escopo global. Como global, quebraria o
     * route-model binding da ficha de um lote baixado — que é justamente o que
     * a sucessão precisa mostrar — e ainda daria falsa sensação de segurança,
     * porque não alcança o LoteRepository (SQL cru) nem os pontos que usam
     * `DB::table()`. Filtro tem de ser explícito em cada consulta.
     */
    public function scopeAtivos(Builder $q): Builder
    {
        return $q->where('lotes.situacao', 'ativo');
    }

    /** Imóveis que deixaram de existir por desmembramento ou unificação. */
    public function scopeBaixados(Builder $q): Builder
    {
        return $q->where('lotes.situacao', 'baixado');
    }

    public function ehAtivo(): bool
    {
        return $this->situacao === 'ativo';
    }

    public function vistorias(): HasMany
    {
        return $this->hasMany(Vistoria::class)->orderByDesc('data_hora');
    }

    public function obras(): HasMany
    {
        return $this->hasMany(Obra::class);
    }

    /**
     * Dá nome ao que aconteceu com o imóvel, em vez de gravar tudo como
     * "alterou".
     *
     * Sem isto, corrigir a quadra de 300 lotes produz 300 linhas iguais
     * dizendo "alterou", e descobrir na trilha qual delas foi a renumeração
     * que quebrou a chave de integração exige abrir uma por uma.
     *
     * @param  array<string,mixed>  $novos
     */
    protected function acaoAuditoria(array $novos): string
    {
        if (array_key_exists('situacao', $novos)) {
            return $novos['situacao'] === 'baixado' ? 'baixou' : 'reativou';
        }
        if (array_key_exists('quadra', $novos)) {
            return 'corrigiu quadra';
        }
        if (array_key_exists('numero_lote', $novos)) {
            return 'renumerou';
        }
        if (array_key_exists('inscricao_imobiliaria', $novos)) {
            return 'alterou inscrição';
        }

        return $this->acaoAuditoriaPadrao($novos);
    }

    /** Rótulo curto usado em listas e títulos de modal. */
    public function rotulo(): string
    {
        return sprintf('Quadra %s · Lote %s', $this->quadra ?? '—', $this->numero_lote ?? '—');
    }
}
