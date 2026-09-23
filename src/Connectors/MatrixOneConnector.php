<?php

namespace MatrixOne\Connectors;

use Illuminate\Database\Connectors\MySqlConnector;
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
}
