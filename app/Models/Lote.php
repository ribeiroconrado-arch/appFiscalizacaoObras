<?php

namespace App\Models;

use App\Cadastro\BairrosDoDesenho;
use App\Models\Concerns\RegistraAuditoria;
use App\Support\InscricaoImobiliaria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * A inscrição imobiliária: a gravada, ou a que as partes do lote formam.
     *
     * O desenho vem do DWG e não traz inscrição — os 2.239 lotes estão com a
     * coluna vazia, e é por isso que a busca por inscrição da tela de Consulta
     * nunca achou nada. Mas a inscrição não é um dado à parte: ela É bairro +
     * quadra + lote + variação (ver App\Support\InscricaoImobiliaria), e o
     * sistema já tem os quatro.
     *
     * DERIVADA, e não gravada: guardar cópia do que se calcula cria duas
     * verdades, que divergem na primeira renumeração de quadra. A coluna
     * continua existindo e TEM PRECEDÊNCIA — é onde entra a inscrição que a
     * prefeitura informar e que, por qualquer motivo, não siga a fórmula.
     *
     * Devolve null quando o bairro do desenho ainda não foi amarrado a um do
     * cadastro: sem o código não há o que montar, e inventar número de imóvel
     * é pior do que não ter nenhum.
     */
    public function inscricao(): ?string
    {
        $gravada = InscricaoImobiliaria::normalizar($this->inscricao_imobiliaria);
        if ($gravada !== null) {
            return $gravada;
        }

        $codigo = DB::table('cadastro_bairros')
            ->where('nome_gis', $this->bairro)
            ->value('codigo');

        return InscricaoImobiliaria::montar(
            $codigo, $this->quadra, $this->numero_lote, $this->desmembramento ?? 0
        );
    }

    /** A inscrição pronta para ler: 01.124.002.0001.000. */
    public function inscricaoFormatada(): ?string
    {
        return InscricaoImobiliaria::formatar($this->inscricao());
    }

    /**
     * O NOME OFICIAL do bairro — o que vale fora do mapa.
     *
     * `bairro` guarda o nome que veio do DWG ("Residencial Buritis V"); o
     * registro do município chama o mesmo lugar de "RESIDENCIAL BURITIS
     * PRIMAVERA V - PRIME". Busca, documento e peça usam o oficial: auto de
     * infração que cite bairro por apelido de planta cita bairro que não
     * existe no cadastro. O mapa continua mostrando o do desenho, que é o que
     * está escrito na planta à frente do fiscal.
     *
     * Sem amarração devolve o do desenho — é o único nome que se conhece.
     */
    public function bairroOficial(): ?string
    {
        return (new BairrosDoDesenho())->oficial($this->bairro);
    }

    /**
     * "Qd. 24 · Lt. 9 — JARDIM EUROPA IV", como sai em lista e em peça.
     *
     * ABREVIADO porque a coluna é estreita e o que ela precisa dizer é o
     * BAIRRO: "Quadra" e "Lote" por extenso comiam 9 caracteres cada, e o
     * nome oficial do bairro — mais longo que o do desenho — saía cortado com
     * reticências. Quadra e lote são números de duas casas; o que identifica
     * o imóvel na leitura corrida é o bairro.
     */
    public function rotuloCompleto(): string
    {
        return sprintf(
            'Qd. %s · Lt. %s — %s',
            $this->quadra ?? '—',
            $this->numero_lote ?? '—',
            $this->bairroOficial() ?? '—'
        );
    }
}
