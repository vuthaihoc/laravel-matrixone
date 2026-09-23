<?php

namespace MatrixOne\Schema;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Fluent;

class Blueprint extends BaseBlueprint
{
    /**
     * Create a new float64 vector (vecf64) column on the table.
     */
    public function vector64(string $column, int $dimensions): ColumnDefinition
    {
        return $this->addColumn('vector64', $column, ['dimensions' => $dimensions]);
    }

    /**
     * Specify a vector index for the table.
     *
     * MatrixOne builds IVF-Flat indexes by default; pass "hnsw" to build an
     * HNSW index (an experimental feature enabled per session). The operator
     * class selects the distance: vector_cosine_ops (default), vector_l2_ops
     * or vector_ip_ops. Tune the index with ->lists(), ->m() or
     * ->efConstruction() on the returned definition.
     *
     * @param  string|string[]  $column
     * @param  string|null  $name
     * @return IndexDefinition
     */
    public function vectorIndex($column, $name = null, string $algorithm = 'ivfflat', string $operatorClass = 'vector_cosine_ops')
    {
        // @phpstan-ignore return.type
        return $this->indexCommand('vectorIndex', $column, (string) $name, $algorithm, $operatorClass);
    }

    /**
     * Indicate that the given vector index should be dropped.
     *
     * @param  string|string[]  $index
     * @return Fluent<string, mixed>
     */
    public function dropVectorIndex($index): Fluent
    {
        return $this->dropIndexCommand('dropVectorIndex', 'vectorIndex', $index);
    }
}
