<?php

namespace Tests\Feature;

use App\Jobs\SealActivityJob;
use App\Jobs\SealNightJob;
use Tests\TestCase;

/**
 * The seal jobs run the biosignal inference for a whole workout / night and can take ~2min. Two silent
 * failure modes if the timing isn't set right:
 *  - No job $timeout → the job inherits the worker's 60s default, gets KILLED mid-seal, retries, times out
 *    again, and the workout/night is SILENTLY DROPPED (this actually lost gym workouts in prod).
 *  - $timeout >= the redis retry_after → the still-running job is redelivered at retry_after and a second
 *    worker DOUBLE-seals it.
 * Both are load-bearing and easy to regress in a refactor, so pin them.
 */
class SealJobTimeoutTest extends TestCase
{
    public function test_seal_jobs_have_a_timeout_that_gives_room_and_avoids_double_processing(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ([SealActivityJob::class, SealNightJob::class] as $job) {
            $timeout = (new \ReflectionClass($job))->getDefaultProperties()['timeout'] ?? null;

            $this->assertNotNull($timeout, "{$job} must declare a \$timeout so a slow seal isn't killed at the worker's 60s default");
            $this->assertGreaterThanOrEqual(120, (int) $timeout, "{$job} \$timeout must give a ~2min seal room");
            $this->assertLessThan($retryAfter, (int) $timeout, "{$job} \$timeout ({$timeout}) must be < redis retry_after ({$retryAfter}) or the job is redelivered mid-run and double-seals");
        }
    }
}
