<?php

namespace Unico\Core\Signature;

use Unico\Core\Enums\SignerRole;
use Unico\Core\Models\Client;
use Unico\Core\Models\Fornitore;
use Unico\Core\Models\User;

final readonly class SignerInput
{
    public function __construct(
        public string $slot,
        public SignerRole $role,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $phone,
        public ?string $taxCode,
        public ?string $signerType,
        public ?string $signerRef,
    ) {}

    /**
     * Per una societa' firma il legale rappresentante, se presente.
     */
    public static function fromClient(Client $client, string $slot): self
    {
        $signer = $client;

        if (! $client->is_person && $client->legalRepresentative !== null) {
            $signer = $client->legalRepresentative;
        }

        return new self(
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

    public static function fromAgent(Fornitore $agent, string $slot): self
    {
        [$firstName, $lastName] = self::splitName((string) $agent->name);

        return new self(
            $slot,
            SignerRole::Collaborator,
            $firstName,
            $lastName,
            $agent->email,
            $agent->tel,
            $agent->cf,
            'agent',
            (string) $agent->getKey(),
        );
    }

    public static function fromUser(User $user, string $slot): self
    {
        [$firstName, $lastName] = self::splitName((string) $user->name);

        return new self($slot, SignerRole::Collaborator, $firstName, $lastName, $user->email, null, null, 'user', (string) $user->getKey());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
