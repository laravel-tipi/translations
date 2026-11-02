<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

/**
 * JSON storage without timestamps.
 */
class UntimedJsonArticle extends JsonArticle
{
    public $timestamps = false;
}
