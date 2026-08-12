<?php

namespace Spawn\Laravel\Database;

use Closure;
use Illuminate\Database\SqlServerConnection;

class AsyncSqlServerConnection extends SqlServerConnection
{
    use CoroutineTransactions {
        transaction as coroutineTransaction;
    }

    /**
     * Run the callback inside a transaction and return its result, retrying it up to
     * $attempts times.
     *
     * Only sqlsrv reaches PDO's transaction control and needs the coroutine-scoped counter.
     * The other drivers get BEGIN TRAN / COMMIT TRAN from SqlServerConnection::transaction(),
     * which the trait's method would otherwise replace without a word.
     *
     * @param  int  $attempts
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        if ($this->getDriverName() === 'sqlsrv') {
            return $this->coroutineTransaction($callback, $attempts);
        }

        return parent::transaction($callback, $attempts);
    }
}
