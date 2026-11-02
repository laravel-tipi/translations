<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Tipi\Translations\Models\Translation;

/**
 * A translation model pinned to another connection than its owners.
 */
class ElsewhereTranslation extends Translation
{
    protected $connection = 'elsewhere';
}
