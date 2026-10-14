<?php

namespace Unico\Core\Signature;

use Unico\Core\Enums\SignerRole;
use Unico\Core\Models\Client;
use Unico\Core\Models\Document;
use Unico\Core\Models\Fornitore;
use Unico\Core\Models\Pratica;
use Unico\Core\Models\User;
use Unico\Core\Pdf\PracticeModuleData;

/**
 * Firmatari proposti per un documento; gli slot non risolvibili mancano e li compila l'operatore.
 */
class SignerDefaults
{
    /**
     * @return array<string, SignerInput> indicizzato per slot
     */
    public static function for(Document $document): array
    {
        $documentable = $document->documentable;
        $signers = [];

        if ($documentable instanceof Client) {
            $signers['cliente'] = self::fromClient($documentable, 'cliente');
        }

        if ($documentable instanceof Pratica) {
            $client = app(PracticeModuleData::class)->findClient($documentable);

            if ($client !== null) {
                $signers['cliente'] = self::fromClient($client, 'cliente');
            }

            $agent = method_exists($documentable, 'agente')
                ? $documentable->agente
                : $documentable->fornitori;

            if ($agent instanceof Fornitore) {
                $signers['collaboratore'] = self::fromAgent($agent, 'collaboratore');
            }
        }

        return $signers;
    }

    public static function fromClient(Client $client, string $slot): SignerInput
    {
        $signer = ! $client->is_person && $client->legalRepresentative !== null
            ? $client->legalRepresentative
            : $client;

        return new SignerInput(
            $slot,
            SignerRole::Client,
            (string) $signer->first_name,
            (string) $signer->name,
            $signer->email,
            $signer->phone,
            $signer->tax_code,
            'client',
            (string) $signer->getKey(),
        );
    }

    public static function fromAgent(Fornitore $agent, string $slot): SignerInput
    {
        [$firstName, $lastName] = array_pad(preg_split('/\s+/', trim((string) $agent->name), 2) ?: [], 2, '');

        return new SignerInput(
            $slot,
            SignerRole::Fornitore,
            (string) $firstName,
            (string) $lastName,
            $agent->email,
            $agent->tel,
            $agent->cf,
            'agent',
            (string) $agent->getKey(),
        );
    }

    public static function fromUser(User $user, string $slot): SignerInput
    {
        [$firstName, $lastName] = array_pad(preg_split('/\s+/', trim((string) $user->name), 2) ?: [], 2, '');

        return new SignerInput(
            $slot,
            SignerRole::Fornitore,
            (string) $firstName,
            (string) $lastName,
            $user->email,
            null,
            null,
            'user',
            (string) $user->getKey(),
        );
    }
}
