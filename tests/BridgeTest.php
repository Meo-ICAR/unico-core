<?php

namespace Unico\Core\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Unico\Core\Bridge\ArtisanExecutor;
use Unico\Core\Bridge\CommandDispatcher;
use Unico\Core\Bridge\Http\Controllers\CommandController;
use Unico\Core\Bridge\Http\Middleware\CorrelationId;
use Unico\Core\Bridge\Http\Middleware\VerifyBridgeKey;
use Unico\Core\Bridge\Jobs\RunBridgeCommand;
use Unico\Core\Models\CommandRun;
use Unico\Core\Tests\Support\CoreDatabase;

class BridgeTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::boot();
        CoreDatabase::truncate('command_runs');
        $this->dispatched = [];
        $container = Container::getInstance();
        $container->instance('cache', $cache = new Repository(new ArrayStore));
        $container->instance(\Illuminate\Contracts\Cache\Repository::class, $cache);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');
        $container->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new class($this)
        {
            public function __construct(private BridgeTest $test) {}

            public function dispatch($job): void
            {
                $this->test->record($job);
            }
        });
        config([
            'unico-core.bridge.commands' => ['pratiche:import' => ['description' => 'Import', 'options' => ['start-date', 'end-date']], 'primanota:genera' => ['options' => []]],
            'unico-core.bridge.api_key' => 'chiave-di-prova', 'unico-core.bridge.queue' => null, 'schedulable_commands' => [],
        ]);
    }

    public function record(object $job): void
    {
        $this->dispatched[] = $job;
    }

    private function executor(int $exitCode, string $output = 'ok'): ArtisanExecutor
    {
        return new class($exitCode, $output) extends ArtisanExecutor
        {
            public array $calls = [];

            public function __construct(private int $exitCode, private string $output) {}

            public function run(string $command, array $options): array
            {
                $this->calls[] = [$command, $options];

                return [$this->exitCode, $this->output];
            }
        };
    }

    // --- Lancio ---

    public function test_a_whitelisted_command_is_queued_and_leaves_a_run(): void
    {
        $run = (new CommandDispatcher)->dispatch('pratiche:import', ['start-date' => '2026-01-01'], 'corr-12345678', 'unicobpm');

        $this->assertSame('queued', $run->status);
        $this->assertSame(['start-date' => '2026-01-01'], $run->options);
        $this->assertSame('corr-12345678', $run->correlation_id);
        $this->assertCount(1, $this->dispatched);
        $this->assertSame($run->id, $this->dispatched[0]->runId);
    }

    public function test_unknown_commands_and_options_are_refused_without_creating_a_run(): void
    {
        $dispatcher = new CommandDispatcher;

        foreach ([['db:wipe', []], ['pratiche:import', ['force' => 1]], ['pratiche:import', ['start-date' => ['a']]]] as [$command, $options]) {
            try {
                $dispatcher->dispatch($command, $options);
                $this->fail('Doveva essere rifiutato: '.$command);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, CommandRun::count());
        $this->assertSame([], $this->dispatched);
    }

    public function test_the_schedulable_commands_of_the_app_are_included(): void
    {
        config(['schedulable_commands' => ['coge:sync' => ['options' => ['month']]]]);

        $this->assertArrayHasKey('coge:sync', (new CommandDispatcher)->commands());
        $this->assertSame('queued', (new CommandDispatcher)->dispatch('coge:sync', ['month' => '2026-09'])->status);
    }

    // --- Esecuzione ---

    public function test_the_job_records_success_and_passes_the_options_as_flags(): void
    {
        $run = (new CommandDispatcher)->dispatch('pratiche:import', ['start-date' => '2026-01-01']);
        $executor = $this->executor(0, 'Importate 12 pratiche');

        (new RunBridgeCommand($run->id))->handle($executor);

        $run->refresh();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame('Importate 12 pratiche', $run->output);
        $this->assertSame(1, $run->attempts);
        $this->assertNotNull($run->finished_at);
        $this->assertSame([['pratiche:import', ['--start-date' => '2026-01-01']]], $executor->calls);
    }

    public function test_a_failure_keeps_the_run_queued_until_the_attempts_are_over(): void
    {
        $run = (new CommandDispatcher)->dispatch('primanota:genera');
        $job = new class($run->id) extends RunBridgeCommand
        {
            public function attempts(): int
            {
                return 1;
            }
        };

        try {
            $job->handle($this->executor(2, 'errore'));
            $this->fail('Doveva lanciare.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exit code 2', $e->getMessage());
        }

        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertSame(2, $run->exit_code);
        $this->assertNull($run->finished_at);

        $job->failed($e);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_the_last_failed_attempt_closes_the_run_as_failed(): void
    {
        $run = (new CommandDispatcher)->dispatch('primanota:genera');
        $job = new class($run->id) extends RunBridgeCommand
        {
            public function attempts(): int
            {
                return 3;
            }
        };

        try {
            $job->handle($this->executor(1));
        } catch (\RuntimeException) {
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_an_overlapping_run_of_the_same_command_is_skipped(): void
    {
        $run = (new CommandDispatcher)->dispatch('primanota:genera');
        $lock = \Illuminate\Support\Facades\Cache::lock('bridge-command:primanota:genera', 60);
        $lock->get();
        $executor = $this->executor(0);

        (new RunBridgeCommand($run->id))->handle($executor);

        $this->assertSame('skipped', $run->fresh()->status);
        $this->assertSame([], $executor->calls);
        $lock->release();
    }

    // --- Controller e middleware ---

    public function test_the_controller_answers_202_with_the_run_id_and_404_422_for_bad_requests(): void
    {
        $controller = new CommandController;
        $dispatcher = new CommandDispatcher;
        $request = Request::create('/api/commands/pratiche:import', 'POST', ['options' => ['start-date' => '2026-01-01']]);
        $request->attributes->set('correlation_id', 'corr-12345678');

        $routes = new \Illuminate\Routing\RouteCollection;
        $routes->add((new \Illuminate\Routing\Route('GET', 'api/commands/runs/{run}', fn () => null))->name('bridge.runs.show'));
        Container::getInstance()->instance('url', new \Illuminate\Routing\UrlGenerator($routes, $request));

        $ok = $controller->store($request, $dispatcher, 'pratiche:import');
        $this->assertSame(202, $ok->getStatusCode());
        $this->assertNotNull($ok->getData(true)['run_id']);

        $this->assertSame(404, $controller->store($request, $dispatcher, 'db:wipe')->getStatusCode());
        $bad = Request::create('/x', 'POST', ['options' => ['force' => 1]]);
        $response = $controller->store($bad, $dispatcher, 'pratiche:import');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['start-date', 'end-date'], $response->getData(true)['allowed_options']);
        $this->assertSame(422, $controller->store(Request::create('/x', 'POST', ['options' => 'x']), $dispatcher, 'pratiche:import')->getStatusCode());
    }

    public function test_the_run_can_be_read_back(): void
    {
        $run = CommandRun::create(['command' => 'pratiche:import', 'status' => 'succeeded', 'exit_code' => 0, 'output' => 'ok', 'attempts' => 1, 'finished_at' => now()]);
        $controller = new CommandController;

        $data = $controller->show($run->id)->getData(true);

        $this->assertTrue($data['finished']);
        $this->assertSame('succeeded', $data['status']);
        $this->assertSame(404, $controller->show(999999)->getStatusCode());
    }

    public function test_the_key_is_required_and_a_missing_configuration_closes_the_bridge(): void
    {
        $middleware = new VerifyBridgeKey;
        $next = fn () => new \Illuminate\Http\Response('ok');

        $ok = Request::create('/api/commands', 'GET', server: ['HTTP_X_API_KEY' => 'chiave-di-prova']);
        $this->assertSame(200, $middleware->handle($ok, $next)->getStatusCode());
        $this->assertSame('bridge', $ok->attributes->get('bridge_client'));

        foreach (['', 'sbagliata'] as $key) {
            try {
                $middleware->handle(Request::create('/api/commands', 'GET', server: ['HTTP_X_API_KEY' => $key]), $next);
                $this->fail('Doveva rifiutare.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(401, $e->getStatusCode());
            }
        }

        config(['unico-core.bridge.api_key' => null, 'services.bpm.api_key' => 'chiave-app']);
        $legacy = Request::create('/api/commands', 'GET', server: ['HTTP_X_API_KEY' => 'chiave-app']);
        $this->assertSame(200, $middleware->handle($legacy, $next)->getStatusCode());

        config(['services.bpm.api_key' => null]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $middleware->handle($ok, $next);
    }

    public function test_the_correlation_id_is_kept_or_created_and_returned(): void
    {
        $middleware = new CorrelationId;
        $next = fn () => new \Illuminate\Http\Response('ok');

        $given = Request::create('/x', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'abc-12345678']);
        $this->assertSame('abc-12345678', $middleware->handle($given, $next)->headers->get('X-Correlation-Id'));
        $this->assertSame('abc-12345678', $given->attributes->get('correlation_id'));

        $invalid = Request::create('/x', 'GET', server: ['HTTP_X_CORRELATION_ID' => "x\ny"]);
        $created = $middleware->handle($invalid, $next)->headers->get('X-Correlation-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $created);
    }

    public function test_old_runs_are_pruned(): void
    {
        $old = CommandRun::create(['command' => 'a', 'status' => 'succeeded']);
        $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        CommandRun::create(['command' => 'b', 'status' => 'succeeded']);

        $this->assertSame(1, (new CommandDispatcher)->prune());
        $this->assertSame(1, CommandRun::count());
    }
}
