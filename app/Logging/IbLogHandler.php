<?php

namespace App\Logging;

use App\Support\IdeasAndBugs;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Every warning and above, reported without a single call site.
 *
 * This is the door that would have caught the 435 repeated timeouts: they were
 * Log::warning, so nothing exception-shaped ever existed to hook.
 */
class IbLogHandler extends AbstractProcessingHandler
{
    public function __construct($level = Level::Warning, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        /*
         * Nothing in here may throw.
         *
         * Laravel's `stack` channel is configured with ignore_exceptions
         * false, so an exception raised while WRITING a log entry propagates
         * into whatever was being logged about. A reporting handler that can
         * break the app it reports on is worse than no handler.
         */
        try {
            $this->report($record);
        } catch (\Throwable) {
            // Swallowed on purpose. See above.
        }
    }

    private function report(LogRecord $record): void
    {
        // Our own chatter must never be reportable, or one failure to report
        // becomes an unbounded loop of failures to report.
        if (str_contains($record->message, '[ib]')) {
            return;
        }

        IdeasAndBugs::degradation($record->message, [
            'channel' => $record->channel,
            'level' => $record->level->getName(),
        ] + array_slice($record->context, 0, 10));
    }
}
