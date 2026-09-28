<?php

namespace MatrixOne\Concerns;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Sleep;
use PDOException;
use Throwable;

/**
 * MatrixOne aborts the whole transaction on a write conflict, a deadlock (it may pick every
 * party as a victim), a lock wait timeout or a CN rolling restart, with SQLSTATE HY000 and
 * its own error numbers. These are retried like Laravel's concurrency errors:
 *
 * - DB::transaction() without an explicit attempt count uses the `retry_attempts` option.
 * - A statement that fails with one of them outside a transaction is retried.
 * - Retries wait an exponential backoff with jitter (`retry_base_delay` / `retry_max_delay`, ms).
 */
trait RetriesTransactionConflicts
{
    /**
     * MatrixOne errors after which the transaction was rolled back and can run again
     * (pkg/common/moerr, rolled back as a whole by pkg/frontend errCodeRollbackWholeTxn).
     * "txn commit status is unknown" (20638) is left out: the commit may have happened.
     *
     * @var array<int, string>
     */
    protected array $retryableErrors = [
        20619 => 'w-w conflict',
        20628 => 'txn need retry in rc mode',
        20631 => 'txn need retry in rc mode, def changed',
        20634 => 'retry for CN rolling restart',
        20701 => 'deadlock detected',
        20702 => 'lock table bind changed',
        20703 => 'lock table not found on remote lock service',
        20704 => 'deadlock check is busy',
        20709 => 'remote lock wait timeout',
        20710 => 'Lock wait timeout exceeded',
    ];

    /**
     * @param  int  $attempts
     * @return mixed
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        if (func_num_args() < 2) {
            $attempts = $this->retryAttempts();
        }

        return parent::transaction($callback, $attempts);
    }

    /**
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     */
    protected function handleTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        // Throws unless the transaction is to be retried.
        parent::handleTransactionException($e, $currentAttempt, $maxAttempts);

        $this->backOffBeforeRetry($currentAttempt);
    }

    /**
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        // Throws unless the transaction is to be retried.
        parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);

        $this->backOffBeforeRetry($currentAttempt);
    }

    /**
     * Retry a statement that failed with a conflict outside a transaction; inside one,
     * transaction() retries the whole transaction.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    protected function retryConflictingStatement(QueryException $e, string $query, array $bindings, Closure $callback): mixed
    {
        if ($this->transactions >= 1 || ! $this->causedByConcurrencyError($e)) {
            return parent::handleQueryException($e, $query, $bindings, $callback);
        }

        for ($attempt = 1; $attempt < $this->retryAttempts(); $attempt++) {
            $this->backOffBeforeRetry($attempt);

            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $retry) {
                if (! $this->causedByConcurrencyError($retry)) {
                    return $this->handleQueryException($retry, $query, $bindings, $callback);
                }

                $e = $retry;
            }
        }

        throw $e;
    }

    /**
     * @return bool
     */
    protected function causedByConcurrencyError(Throwable $e)
    {
        if (parent::causedByConcurrencyError($e)) {
            return true;
        }

        $previous = $e->getPrevious() instanceof PDOException ? $e->getPrevious() : $e;
        $code = $previous instanceof PDOException ? (int) ($previous->errorInfo[1] ?? 0) : 0;

        if (isset($this->retryableErrors[$code])) {
            return true;
        }

        $message = $e->getMessage();

        foreach ($this->retryableErrors as $number => $text) {
            if (str_contains($message, "General error: {$number} ") || str_contains($message, $text)) {
                return true;
            }
        }

        return false;
    }

    protected function retryAttempts(): int
    {
        $attempts = $this->getConfig('retry_attempts');

        return max(1, is_numeric($attempts) ? (int) $attempts : 3);
    }

    /**
     * Wait before the retry following $attempt: half of the capped exponential delay plus a random half.
     */
    protected function backOffBeforeRetry(int $attempt): void
    {
        $base = $this->getConfig('retry_base_delay');
        $max = $this->getConfig('retry_max_delay');
        $base = is_numeric($base) ? (int) $base : 50;
        $max = is_numeric($max) ? (int) $max : 1000;
        $delay = min($max, $base * 2 ** min($attempt - 1, 20)) * 1000;

        if ($delay > 0) {
            Sleep::usleep(intdiv($delay, 2) + random_int(0, intdiv($delay, 2)));
        }
    }
}
