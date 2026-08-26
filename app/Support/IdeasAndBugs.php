<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ideas + Bugs reporter. Drop this file into a project, set IB_KEY, done.
 *
 * Two rules it exists to honour:
 *
 *   1. Reporting must never make the caller's problem worse. Every failure
 *      here is swallowed — an app that is already broken must not then break
 *      on telling us it is broken.
 *   2. Nothing sensitive leaves the box. Context is allow-listed by the caller
 *      and scrubbed again at the hub; request bodies are never sent.
 *
 * Usage:
 *
 *   IdeasAndBugs::fault($e, ['tenant' => $t->id]);
 *   IdeasAndBugs::degradation('agent-task waiting', ['task' => $id, 'why' => 'timeout']);
 *
 * Or as a Log channel so every warning and error goes automatically — see
 * `docs/install-sdk.md`.
 */
class IdeasAndBugs
{
    public static function enabled(): bool
    {
        return (bool) config('ib.key') && (bool) config('ib.url');
    }

    /** Something threw. */
    public static function fault(\Throwable $e, array $context = []): void
    {
        self::send([
            'kind' => 'fault',
            'level' => 'error',
            'message' => get_class($e).': '.$e->getMessage(),
            'culprit' => basename($e->getFile()).':'.$e->getLine(),
            'context' => $context + ['trace' => self::frames($e)],
        ]);
    }

    /** Something is repeating that should not be. */
    public static function degradation(string $message, array $context = []): void
    {
        self::send(['kind' => 'degradation', 'level' => 'warning', 'message' => $message, 'context' => $context]);
    }

    /** An assertion about the world came back false. */
    public static function invariant(string $key, string $says, array $context = []): void
    {
        self::send([
            'kind' => 'invariant', 'level' => 'error',
            'fingerprint' => 'invariant:'.$key,
            'title' => $says, 'message' => $says, 'context' => $context,
        ]);
    }

    private static function send(array $event): void
    {
        if (! self::enabled()) {
            return;
        }

        // Stamped so the hub can tell a rich SDK report from a tailed log line.
        $event['context'] = ($event['context'] ?? []) + ['via' => 'sdk'];

        $event += [
            'environment' => app()->environment(),
            'release' => config('ib.release'),
            'occurred_at' => now()->toIso8601String(),
        ];

        try {
            // Short timeout on purpose: this runs inline, and the hub being slow
            // must never become the caller's latency problem.
            Http::withHeaders(['X-Ib-Key' => config('ib.key')])
                ->timeout(3)
                ->connectTimeout(2)
                ->post(rtrim(config('ib.url'), '/').'/api/events', $event);
        } catch (\Throwable $e) {
            // Deliberately swallowed, and logged locally at debug so a broken
            // reporter cannot fill the log it is meant to be watching.
            Log::debug('[ib] could not report', ['error' => $e->getMessage()]);
        }
    }

    /** Our own frames only — vendor noise makes every trace look the same. */
    private static function frames(\Throwable $e): array
    {
        $out = [];
        foreach ($e->getTrace() as $f) {
            $file = $f['file'] ?? '';
            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }
            $out[] = basename($file).':'.($f['line'] ?? '?').' '.($f['function'] ?? '');
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }
}
