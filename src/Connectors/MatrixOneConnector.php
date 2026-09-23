<?php

namespace MatrixOne\Connectors;

use Illuminate\Database\Connectors\MySqlConnector;
use InvalidArgumentException;
use PDO;

class MatrixOneConnector extends MySqlConnector
{
    /**
     * Get the PDO options based on the configuration.
     *
     * MatrixOne rejects placeholders in some positions that MySQL accepts
     * (for example inside MATCH ... AGAINST), and its server-side prepared
     * statements have known metadata caching issues. Emulated prepares are
     * therefore enabled by default; set `emulate_prepares` to false in the
     * connection config to use native server-side prepares instead.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    public function getOptions(array $config)
    {
        $options = parent::getOptions($config);

        // Laravel's defaults disable emulation; only an explicit PDO option
        // set by the user takes precedence over `emulate_prepares`.
        $userOptions = is_array($config['options'] ?? null) ? $config['options'] : [];

        if (! array_key_exists(PDO::ATTR_EMULATE_PREPARES, $userOptions)) {
            $options[PDO::ATTR_EMULATE_PREPARES] = (bool) ($config['emulate_prepares'] ?? true);
        }

        return $options;
    }

    /**
     * Configure the given PDO connection.
     *
     * Besides Laravel's MySQL session settings, every entry of the
     * `variables` config option is applied with `SET SESSION`, e.g.
     * `['ft_relevancy_algorithm' => 'BM25', 'experimental_hnsw_index' => 1]`.
     *
     * @param  array<string, mixed>  $config
     * @return void
     */
    protected function configureConnection(PDO $connection, array $config)
    {
        parent::configureConnection($connection, $config);

        $variables = $config['variables'] ?? [];

        if (is_array($variables) && $variables !== []) {
            /** @var array<string, mixed> $variables */
            $connection->exec(static::compileSetSessionVariables($connection, $variables));
        }
    }

    /**
     * Compile a `SET SESSION a = ..., SESSION b = ...` statement.
     *
     * @param  array<string, mixed>  $variables
     */
    public static function compileSetSessionVariables(PDO $connection, array $variables): string
    {
        $assignments = [];

        foreach ($variables as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
                throw new InvalidArgumentException("Invalid MatrixOne session variable name [{$name}].");
            }

            $assignments[] = 'session '.$name.' = '.match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => $connection->quote($value),
                default => throw new InvalidArgumentException("Invalid value for MatrixOne session variable [{$name}]."),
            };
        }

        return 'set '.implode(', ', $assignments);
    }
}
