<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anexo de um documento: foto ou PDF juntado na própria peça, ou trazido — por
 * escolha do fiscal — da vistoria vinculada ou da peça de origem.
 *
 * Ver a migração 2026_10_20_000100 para o desenho; as regras de quem junta e
 * de quem exclui estão em {@see Documento::podeJuntarAnexo()} e
 * {@see self::podeSerExcluidoPor()}.
 */
class DocumentoAnexo extends Model
{
    use RegistraAuditoria;

    protected $table = 'documento_anexos';
    protected $guarded = [];

    /** Teto de anexos por documento. */
    public const MAXIMO = 20;

    protected function casts(): array
    {
        return [
            'data_hora'      => 'datetime',
            'imprime'        => 'boolean',
            'juntado_depois' => 'boolean',
            'ordem'          => 'integer',
        ];
    }

    public function documento(): BelongsTo { return $this->belongsTo(Documento::class); }
    public function autor(): BelongsTo     { return $this->belongsTo(User::class, 'criado_por'); }

    public function ehFoto(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /**
     * O arquivo é DESTE anexo (e some com ele), ou é de quem o cedeu — a
     * vistoria ou a peça de origem —, e então fica onde está?
     */
    public function donoDoArquivo(): bool
    {
        // A foto da vistoria preparada na tela (carimbo e marca d'água) sobe
        // como imagem nova, na pasta desta peça: também é dela.
        return $this->origem === 'proprio'
            || str_starts_with((string) $this->arquivo, 'documentos/' . $this->documento_id . '/');
    }

    /**
     * Quem EXCLUI (e quem altera título, ordem e "sai na impressão").
     *
     * Em rascunho, o autor da peça. Com a peça LAVRADA, exclusivamente quem a
     * lavrou — e o administrador NÃO é exceção: a regra é de autoria, não de
     * perfil, a mesma das evidências da vistoria. Quem lavra responde pelo que
     * está nos autos; ninguém tira prova da peça de outro fiscal.
     * Peça anulada não se altera mais.
     */
    public function podeSerExcluidoPor(User $u): bool
    {
        $doc = $this->documento;

        return $doc && $doc->status !== 'anulado' && $doc->agente_id === $u->id;
    }
}
