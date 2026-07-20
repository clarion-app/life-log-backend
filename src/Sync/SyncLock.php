<?php

namespace ClarionApp\LifeLogBackend\Sync;

use Illuminate\Support\Facades\Cache;

/**
 * Per-account distributed lock for sync runs.
 *
 * Non-blocking: if the lock is held, attempt() returns null immediately.
 * Never uses block() — that would make a sync job wait for another to finish,
 * which defeats the purpose of the lock.
 */
class SyncLock
{
    /**
     * Attempt to acquire the lock and execute $callback.
     *
     * @param  callable(): mixed  $callback
     * @return mixed|null  callback return value, or null if lock was held
     */
    public function attempt(string $connectedAccountId, callable $callback): mixed
    {
        $lockName = sprintf('life-log:sync:%s', $connectedAccountId);
        $lockSeconds = (int) config('life-log.sync_lock_seconds', 900);

        $lock = Cache::lock($lockName, $lockSeconds);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
