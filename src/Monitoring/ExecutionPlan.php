<?php

namespace MatrixOne\Monitoring;

/**
 * An execution plan recorded in system.statement_info.exec_plan.
 */
final class ExecutionPlan
{
    /**
     * @param  array<string, mixed>  $plan  the decoded JSON plan
     */
    public function __construct(public readonly array $plan) {}

    /**
     * Decode a recorded plan; null when the statement kept none ("{}").
     */
    public static function fromJson(?string $json): ?self
    {
        $plan = $json === null ? null : json_decode($json, true);

        return is_array($plan) && isset($plan['steps']) ? new self($plan) : null;
    }

    /**
     * The operators of every step, flattened, with their main statistics.
     *
     * @return list<array{step: int, id: string, name: string, title: string, time_ms: float, wait_ms: float, input_rows: int, output_rows: int, scan_bytes: int, memory_bytes: int}>
     */
    public function nodes(): array
    {
        $nodes = [];

        foreach ((array) $this->plan['steps'] as $position => $step) {
            if (! is_array($step)) {
                continue;
            }

            $graph = is_array($step['graphData'] ?? null) ? $step['graphData'] : [];

            foreach ((array) ($graph['nodes'] ?? []) as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $statistics = is_array($node['statistics'] ?? null) ? $node['statistics'] : [];

                $nodes[] = [
                    'step' => is_int($step['step'] ?? null) ? $step['step'] : (int) $position,
                    'id' => is_scalar($node['id'] ?? null) ? (string) $node['id'] : '',
                    'name' => is_string($node['name'] ?? null) ? $node['name'] : '',
                    'title' => is_string($node['title'] ?? null) ? $node['title'] : '',
                    'time_ms' => self::statistic($statistics, 'Time', 'Time Consumed') / 1_000_000,
                    'wait_ms' => self::statistic($statistics, 'Time', 'Wait Time') / 1_000_000,
                    'input_rows' => (int) self::statistic($statistics, 'Throughput', 'Input Rows'),
                    'output_rows' => (int) self::statistic($statistics, 'Throughput', 'Output Rows'),
                    'scan_bytes' => (int) self::statistic($statistics, 'IO', 'Scan Bytes'),
                    'memory_bytes' => (int) self::statistic($statistics, 'Memory', 'Memory Size'),
                ];
            }
        }

        return $nodes;
    }

    /**
     * @param  array<mixed>  $statistics
     */
    private static function statistic(array $statistics, string $group, string $name): float
    {
        foreach ((array) ($statistics[$group] ?? []) as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name && is_numeric($entry['value'] ?? null)) {
                return (float) $entry['value'];
            }
        }

        return 0.0;
    }
}
