<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use RuntimeException;

/**
 * Rolls back the save transaction when a "saving" listener halts the save.
 *
 * @internal
 */
final class SaveHalted extends RuntimeException {}
