<?php

namespace MatrixOne;

use RuntimeException;

/**
 * Thrown by the outermost commit when a nested transaction rolled back and
 * 'nested_transactions' => 'rollback_only' is set.
 */
class NestedTransactionRolledBackException extends RuntimeException {}
