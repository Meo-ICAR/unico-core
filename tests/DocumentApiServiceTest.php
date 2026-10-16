<?php

namespace Unico\Core\Tests;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\Api\DocumentApiFetcher;
use Unico\Core\Documents\Api\DocumentApiRequested;
use Unico\Core\Documents\Api\DocumentApiResult;
use Unico\Core\Documents\Api\DocumentApiService;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Tests\Support\CoreDatabase;

class DocumentApiServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(\Unico\Core\MorphMap::all(), merge: true);
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('documents', 'document_types');
    }

    private function fetcher(DocumentApiResult $result): DocumentApiFetcher
    {
        return new class($result) implements DocumentApiFetcher
        {
            public function __construct(private DocumentApiResult $result) {}

            public function key(): string
            {
                return 'finto';
            }

            public function label(): string
            {
                return 'Servizio finto';
            }

            public function fetch(Document $document): DocumentApiResult
            {
                return $this->result;
            }
        };
    }

    private function document(?string $service): Document
    {
        $type = DocumentType::create(['name' => 'Visura', 'code' => 'VI'.uniqid(), 'api_service' => $service]);

        return Document::create(['documentable_type' => 'company', 'documentable_id' => '1', 'document_type_id' => $type->id, 'name' => 'Visura', 'status' => DocumentStatus::PENDING]);
    }

    public function test_only_types_with_a_registered_service_can_be_requested(): void
    {
        $service = new DocumentApiService([$this->fetcher(DocumentApiResult::failed('no'))]);

        $this->assertSame(['finto' => 'Servizio finto'], $service->services());
        $this->assertTrue($service->supports($this->document('finto')->documentType));
        $this->assertFalse($service->supports($this->document('altro')->documentType));
        $this->assertFalse($service->supports($this->document(null)->documentType));
        $this->assertFalse($service->request($this->document(null))->ok);
    }

    public function test_a_simulated_or_failed_request_leaves_the_document_pending_and_is_announced(): void
    {
        Event::swap(new \Illuminate\Events\Dispatcher);
        $heard = [];
        Event::listen(DocumentApiRequested::class, function (DocumentApiRequested $event) use (&$heard): void {
            $heard[] = [$event->service, $event->result->ok, $event->result->simulated];
        });

        $simulated = $this->document('finto');
        (new DocumentApiService([$this->fetcher(new DocumentApiResult(true, 'simulata', simulated: true))]))->request($simulated);
        $failed = $this->document('finto');
        (new DocumentApiService([$this->fetcher(DocumentApiResult::failed('ko'))]))->request($failed);

        $this->assertSame(DocumentStatus::PENDING, $simulated->refresh()->status);
        $this->assertSame(DocumentStatus::PENDING, $failed->refresh()->status);
        $this->assertSame([['finto', true, true], ['finto', false, false]], $heard);
    }
}
