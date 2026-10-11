<?php

namespace Unico\Core\Bridge;

use InvalidArgumentException;
use Unico\Core\Bridge\Jobs\RunBridgeCommand;
use Unico\Core\Models\CommandRun;

/**
 * Lancia comandi Artisan per conto di un sistema esterno: solo quelli della whitelist (`unico-core.bridge.commands`, più
 * `schedulable_commands` per le app che ce l'hanno già) e solo con le opzioni dichiarate. Il lancio è in coda e lascia un
 * `CommandRun` che il chiamante può leggere per sapere come è andata.
 */
class CommandDispatcher
{
    /** @return array<string, array{description?: string, options?: list<string>}> */
    public function commands(): array
    {
        return (array) config('unico-core.bridge.commands', []) + (array) config('schedulable_commands', []);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException comando o opzioni non consentiti
     */
    public function dispatch(string $command, array $options = [], ?string $correlationId = null, ?string $requestedBy = null): CommandRun
    {
        $allowed = $this->commands();

        if (! array_key_exists($command, $allowed)) {
            throw new InvalidArgumentException("Comando '{$command}' non schedulabile via API.");
        }

        $unknown = array_diff(array_keys($options), (array) ($allowed[$command]['options'] ?? []));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Opzioni non consentite per questo comando: '.implode(', ', $unknown));
        }

        foreach ($options as $name => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException("L'opzione '{$name}' deve avere un valore singolo (stringa, numero o booleano).");
            }
        }

        $run = CommandRun::create([
            'command' => $command,
            'options' => $options,
            'status' => CommandRun::QUEUED,
            'requested_by' => $requestedBy,
            'correlation_id' => $correlationId,
        ]);

        RunBridgeCommand::dispatch($run->id);

        return $run;
    }

    /** Elimina i lanci più vecchi di `keep_runs_days`; ritorna quanti. */
    public function prune(): int
    {
        return CommandRun::query()->where('created_at', '<', now()->subDays((int) config('unico-core.bridge.keep_runs_days', 30)))->delete();
    }
}
