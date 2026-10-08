<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasDedicatedTableTranslations;
use Tipi\Translations\Concerns\InteractsWithTranslationStates;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\HasTranslationStates;

final class DedicatedArticle extends Model implements DedicatedTableTranslatableModel, HasTranslationStates
{
    use HasDedicatedTableTranslations;
    use InteractsWithTranslationStates;

    protected $table = 'dedicated_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
