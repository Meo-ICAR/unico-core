<?php

namespace Unico\Core\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Unico\Core\Enums\SignerRole;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\PdfModule;
use Unico\Core\Pdf\PdfFormFiller;
use Unico\Core\Signature\Contracts\SignatureProvider;
use Unico\Core\Signature\FakeSignatureProvider;
use Unico\Core\Signature\SignatureProviderManager;
use Unico\Core\Signature\SignatureRequestService;
use Unico\Core\Signature\SignerInput;

/** Strumenti comuni ai test della firma elettronica. */
trait SignatureTestKit
{
    protected function bootSignature(): void
    {
        CoreDatabase::boot();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(\Unico\Core\MorphMap::all(), merge: true);
        Container::getInstance()->instance('cache', $cache = new Repository(new ArrayStore));
        Container::getInstance()->instance(\Illuminate\Contracts\Cache\Repository::class, $cache);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');
        Container::getInstance()->instance(\Illuminate\Http\Client\Factory::class, new \Illuminate\Http\Client\Factory);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        config(['app.timezone' => 'Europe/Rome', 'unico-core.signature.driver' => 'fake', 'unico-core.signature.ttl_days' => 7, 'unico-core.pdf.pdftk_binary' => 'pdftk']);
    }

    protected function slots(): array
    {
        return [
            ['slot' => 'cliente', 'role' => 'client', 'page' => 1, 'x' => 50.0, 'y' => 700.0, 'width' => 150.0, 'height' => 40.0],
            ['slot' => 'collaboratore', 'role' => 'collaborator', 'page' => 1, 'x' => 300.0, 'y' => 700.0, 'width' => 150.0, 'height' => 40.0],
        ];
    }

    protected function documentWithModule(?array $slots = null): Document
    {
        $type = DocumentType::create([
            'name' => 'QAV', 'code' => 'QAV'.uniqid(), 'is_monitored' => true, 'duration' => 12, 'duration_unit' => 'months',
        ]);
        PdfModule::create([
            'name' => 'QAV', 'file_path' => 'module/qav.pdf', 'document_type_id' => $type->id,
            'signature_slots' => $slots ?? $this->slots(),
        ]);

        return Document::create([
            'documentable_type' => 'company', 'documentable_id' => 1, 'document_type_id' => $type->id, 'name' => 'QAV Rossi',
        ]);
    }

    /** @return array<int, SignerInput> */
    protected function signers(array $overrides = []): array
    {
        return [
            new SignerInput('cliente', SignerRole::Client, 'Mario', 'Rossi', 'mario@example.com', '333 1234567', 'RSSMRA80A01H501U', 'client', '1'),
            new SignerInput('collaboratore', SignerRole::Collaborator, 'Anna', 'Bianchi', 'anna@example.com', '+39 340 7654321', null, 'user', '2'),
        ] + $overrides;
    }

    protected function service(?SignatureProvider $provider = null): SignatureTestService
    {
        $manager = new class($provider ?? new FakeSignatureProvider) extends SignatureProviderManager
        {
            public function __construct(private SignatureProvider $forced) {}

            public function provider(?string $name = null): SignatureProvider
            {
                return $this->forced;
            }
        };

        return new SignatureTestService($manager, new PdfFormFiller);
    }
}
