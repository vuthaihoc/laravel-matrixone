<?php

namespace MatrixOne;

use Exception;
use Illuminate\Database\MySqlConnection;
use Illuminate\Filesystem\Filesystem;
use MatrixOne\Query\Builder as QueryBuilder;
use MatrixOne\Query\Grammar as QueryGrammar;
use MatrixOne\Query\Processors\MatrixOneProcessor;
use MatrixOne\Schema\Builder as SchemaBuilder;
use MatrixOne\Schema\Grammar as SchemaGrammar;
use RuntimeException;

class MatrixOneConnection extends MySqlConnection
{
    /** {@inheritDoc} */
    public function getDriverTitle()
    {
        return 'MatrixOne';
    }

    /** {@inheritDoc} */
    public function isMaria()
    {
        return false;
    }

    /**
     * Get the MatrixOne release version, e.g. "3.0.9" from the server
     * version string "8.0.30-MatrixOne-v3.0.9".
     */
    public function getMatrixOneVersion(): ?string
    {
        if (preg_match('/MatrixOne-v?([0-9][^\s-]*)/i', $this->getServerVersion(), $matches)) {
            return $matches[1];
        }

        return null;
    }

    /** {@inheritDoc} */
    public function query()
    {
        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /** {@inheritDoc} */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return (bool) preg_match('#(Integrity constraint violation|General error): 1062#i', $exception->getMessage());
    }

    /** {@inheritDoc} */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /** {@inheritDoc} */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /** {@inheritDoc} */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /** {@inheritDoc} */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        throw new RuntimeException('Schema dumping is not supported when using MatrixOne.');
    }

    /** {@inheritDoc} */
    protected function getDefaultPostProcessor()
    {
        return new MatrixOneProcessor;
    }
}
