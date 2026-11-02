<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Tipi\Translations\Concerns\HasTranslationStates;

class StatefulSharedArticle extends SharedArticle
{
    use HasTranslationStates;
    use SoftDeletes;
}
