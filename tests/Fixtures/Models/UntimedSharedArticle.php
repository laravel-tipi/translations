<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

/**
 * Shared table storage without timestamps on the model.
 */
class UntimedSharedArticle extends SharedArticle
{
    public $timestamps = false;
}
