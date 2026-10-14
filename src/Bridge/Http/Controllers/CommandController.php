<?php

namespace Unico\Core\Bridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Unico\Core\Bridge\CommandDispatcher;
use Unico\Core\Models\CommandRun;

/**
 * Bridge per i sistemi esterni di scheduling (unicobpm): elenca i comandi consentiti, ne mette uno in coda (202 con l'id del lancio)
 * e permette di leggerne l'esito. Risponde subito perché i comandi (import, sincronizzazioni) possono durare minuti.
 */
class CommandController
{
    public function index(CommandDispatcher $dispatcher): JsonResponse
    {
        return new JsonResponse(['commands' => $dispatcher->commands()]);
    }

    public function store(Request $request, CommandDispatcher $dispatcher, string $command): JsonResponse
    {
        $options = $request->input('options', []);

        if (! is_array($options)) {
            return new JsonResponse(['message' => 'Il campo "options" deve essere un oggetto.'], 422);
        }

        try {
            $run = $dispatcher->dispatch($command, $options, $request->attributes->get('correlation_id'), $request->attributes->get('bridge_client'));
        } catch (InvalidArgumentException $e) {
            $unknown = ! array_key_exists($command, $dispatcher->commands());

            return new JsonResponse(['message' => $e->getMessage()] + ($unknown ? [] : ['allowed_options' => $dispatcher->commands()[$command]['options'] ?? []]), $unknown ? 404 : 422);
        }

        return new JsonResponse([
            'message' => "Comando '{$command}' accodato per l'esecuzione.",
            'command' => $command,
            'options' => $options,
            'run_id' => $run->id,
            'status_url' => route('bridge.runs.show', $run->id),
        ], 202);
    }

    public function show(int $run): JsonResponse
    {
        $run = CommandRun::query()->find($run);

        if ($run === null) {
            return new JsonResponse(['message' => 'Lancio non trovato.'], 404);
        }

        return new JsonResponse([
            'run_id' => $run->id,
            'command' => $run->command,
            'status' => $run->status,
            'finished' => $run->isFinished(),
            'exit_code' => $run->exit_code,
            'attempts' => $run->attempts,
            'output' => $run->output,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'correlation_id' => $run->correlation_id,
        ]);
    }

    public function health(): JsonResponse
    {
        return new JsonResponse(['app' => config('app.name'), 'status' => 'ok', 'time' => now()->toIso8601String()]);
    }
}
