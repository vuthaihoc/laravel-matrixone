<?php

namespace MatrixOne\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MatrixOne\MatrixOneConnection;
use RuntimeException;

class SlowQueriesCommand extends Command
{
    /** @var string */
    protected $signature = 'matrixone:slow-queries
        {--since=1h : Time range: a number followed by s, m, h or d}
        {--min= : Minimum duration in milliseconds (default 1000, or 0 with --failed)}
        {--failed : Show failed statements with their errors}
        {--type=* : Statement types, e.g. --type=Select --type=Update}
        {--limit=20 : Maximum number of statements}
        {--all-databases : Include every database, not only the connection\'s}
        {--internal : Include MatrixOne\'s internal SQL}
        {--plan= : Show the execution plan of a statement id}
        {--database= : The database connection to use}';

    /** @var string */
    protected $description = 'Show slow or failed statements from MatrixOne\'s statement history';

    public function handle(ConnectionResolverInterface $db): int
    {
        $connection = $db->connection(is_string($this->option('database')) ? $this->option('database') : null);

        if (! $connection instanceof MatrixOneConnection) {
            throw new RuntimeException('The matrixone:slow-queries command needs a matrixone connection.');
        }

        $plan = $this->option('plan');

        if (is_string($plan) && $plan !== '') {
            return $this->showPlan($connection, $plan);
        }

        $since = $this->option('since');
        $min = $this->option('min');
        $limit = $this->option('limit');
        $failed = (bool) $this->option('failed');

        try {
            $query = $connection->statementLog()->since(is_string($since) ? $since : '1h')->summary();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('internal')) {
            $query->onlyApplication();
        } else {
            $query->includeInternal();
        }

        if ($this->option('all-databases')) {
            $query->allDatabases();
        }

        if ($failed) {
            $query->failed();
        }

        $threshold = is_numeric($min) ? (float) $min : ($failed ? 0.0 : 1000.0);

        if ($threshold > 0) {
            $query->slowerThan($threshold);
        }

        $types = array_filter((array) $this->option('type'), 'is_string');

        if ($types !== []) {
            $query->ofType(...$types);
        }

        $rows = ($failed ? $query->latest('request_at') : $query->slowest())
            ->limit(is_numeric($limit) ? (int) $limit : 20)
            ->get();

        if ($rows->isEmpty()) {
            $this->components->info('No matching statements.');

            return self::SUCCESS;
        }

        $this->table(
            ['Time (UTC)', 'ms', 'Type', $failed ? 'Error' : 'Rows read', 'Statement', 'Statement id'],
            $rows->map(fn ($row) => [
                $row->request_at,
                $row->duration_ms,
                $row->statement_type,
                $failed ? Str::limit($this->oneLine($row->error), 60) : $row->rows_read,
                Str::limit($this->oneLine($row->statement), 80),
                $row->statement_id,
            ])->all(),
        );

        $this->line('  Show a plan with --plan=<statement id>. Times are UTC.');

        return self::SUCCESS;
    }

    protected function showPlan(MatrixOneConnection $connection, string $statementId): int
    {
        $plan = $connection->getStatementPlan($statementId);

        if ($plan === null) {
            $this->components->warn('No plan recorded for this statement: MatrixOne keeps plans of statements running for at least 1 second (longQueryTime).');

            return self::FAILURE;
        }

        $this->table(
            ['Step', 'Node', 'Operator', 'Detail', 'Time ms', 'Wait ms', 'Rows in', 'Rows out', 'Scan bytes'],
            array_map(fn ($node) => [
                $node['step'], $node['id'], $node['name'], Str::limit($node['title'], 50),
                round($node['time_ms'], 3), round($node['wait_ms'], 3),
                $node['input_rows'], $node['output_rows'], $node['scan_bytes'],
            ], $plan->nodes()),
        );

        return self::SUCCESS;
    }

    private function oneLine(mixed $value): string
    {
        return is_scalar($value) ? (string) preg_replace('/\s+/', ' ', (string) $value) : '';
    }
}
