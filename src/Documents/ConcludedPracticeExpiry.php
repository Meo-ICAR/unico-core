<?php

namespace Unico\Core\Documents;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\PraticaClient;
use Unico\Core\Models\PraticaStato;

/**
 * Quando una pratica non è più in lavorazione (lo stato ha `is_working` spento):
 *  - le scadenze dei documenti della pratica e di tutti i suoi clienti (richiedente, coobbligati, garanti: `pratica_clients`) si azzerano;
 *  - i documenti ancora «richiesti» della pratica e dei suoi clienti passano a N/A, ma per i clienti solo se non hanno nessuna pratica
 *    ancora in lavorazione (altrimenti il documento serve a quella): non sono più da chiedere e escono dallo scadenziario.
 * Gli aggiornamenti sono diretti sul database, senza eventi del modello. Una scadenza azzerata si ricalcola solo se qualcuno risalva il
 * documento con la data di emissione (vedi `Document::computeExpiry`). Una pratica senza stato conta come in lavorazione.
 */
class ConcludedPracticeExpiry
{
    public function isConcluded(Pratica $pratica): bool
    {
        return $pratica->pratica_stato_id !== null
            && PraticaStato::query()->whereKey($pratica->pratica_stato_id)->where('is_working', false)->exists();
    }

    /** @return array{scadenze: int, richiesti: int} documenti con la scadenza azzerata, documenti «richiesti» passati a N/A */
    public function clear(Pratica $pratica): array
    {
        $clients = PraticaClient::query()->where('pratica_id', $pratica->getKey())->select('client_id');
        $ofPratica = fn (Builder $own) => $own->whereIn('documentable_type', ['pratica', Pratica::class])->where('documentable_id', $pratica->getKey());

        return [
            'scadenze' => $this->nullExpiry($this->documents($ofPratica, fn (Builder $linked) => $linked->whereIn('documentable_id', $clients))),
            'richiesti' => $this->markNotRequired($this->documents($ofPratica, fn (Builder $linked) => $linked->whereIn('documentable_id', $this->withoutOpenPractices($clients)))),
        ];
    }

    /**
     * Lo stesso per tutte le pratiche già concluse.
     *
     * @return array{scadenze: int, richiesti: int}
     */
    public function clearAll(): array
    {
        $concluded = Pratica::query()->select('pratiches.id')->whereIn('pratiches.pratica_stato_id', PraticaStato::query()->select('id')->where('is_working', false));
        $clients = PraticaClient::query()->select('client_id')->whereIn('pratica_id', $concluded);
        $ofPratiche = fn (Builder $own) => $own->whereIn('documentable_type', ['pratica', Pratica::class])->whereIn('documentable_id', $concluded);

        return [
            'scadenze' => $this->nullExpiry($this->documents($ofPratiche, fn (Builder $linked) => $linked->whereIn('documentable_id', $clients))),
            'richiesti' => $this->markNotRequired($this->documents($ofPratiche, fn (Builder $linked) => $linked->whereIn('documentable_id', $this->withoutOpenPractices($clients)))),
        ];
    }

    /**
     * I documenti della pratica (o delle pratiche) e dei clienti scelti.
     *
     * @return Builder<Document>
     */
    private function documents(callable $practice, callable $clients): Builder
    {
        return Document::query()->where(fn (Builder $documents) => $documents
            ->where(fn (Builder $own) => $practice($own))
            ->orWhere(fn (Builder $linked) => $clients($linked->whereIn('documentable_type', ['client', Client::class]))));
    }

    /**
     * Dei clienti indicati, quelli senza nessuna pratica in lavorazione (o senza stato).
     *
     * @param  Builder<PraticaClient>  $clients
     * @return Builder<PraticaClient>
     */
    private function withoutOpenPractices(Builder $clients): Builder
    {
        return PraticaClient::query()->select('client_id')->whereIn('client_id', $clients->clone()->toBase())
            ->whereNotExists(fn (QueryBuilder $open) => $open->selectRaw('1')->from('pratica_clients as other')
                ->join('pratiches as other_pratica', 'other_pratica.id', '=', 'other.pratica_id')
                ->leftJoin('pratica_stati as other_stato', 'other_stato.id', '=', 'other_pratica.pratica_stato_id')
                ->whereColumn('other.client_id', 'pratica_clients.client_id')
                ->where(fn (QueryBuilder $working) => $working->whereNull('other_pratica.pratica_stato_id')->orWhere('other_stato.is_working', true)));
    }

    /** @param  Builder<Document>  $documents */
    private function nullExpiry(Builder $documents): int
    {
        return $documents->whereNotNull('expires_at')->toBase()->update(['expires_at' => null, 'updated_at' => now()]);
    }

    /** @param  Builder<Document>  $documents */
    private function markNotRequired(Builder $documents): int
    {
        return $documents->where('status', DocumentStatus::PENDING->value)->toBase()->update(['status' => DocumentStatus::NA->value, 'updated_at' => now()]);
    }
}
