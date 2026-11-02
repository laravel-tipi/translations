<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Tipi\Translations\Concerns\HasTranslationStates;

/**
 * Inherits #[Translatable] from JsonArticle.
 */
class StatefulJsonArticle extends JsonArticle
{
    use HasTranslationStates;
    use SoftDeletes;
}
