<?php

namespace MatrixOne\Tests\Feature\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Records its execution in the database cache store.
 */
class RecordJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public string $key,
        public string $behaviour = 'record',
    ) {}

    public function handle(): void
    {
        if ($this->behaviour === 'fail') {
            throw new RuntimeException("Job {$this->key} failed.");
        }

        if ($this->behaviour === 'release-once' && $this->attempts() === 1) {
            $this->release(0);

            return;
        }

        Cache::store('database')->forever("job:{$this->key}", $this->attempts());
    }
}
