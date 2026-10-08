<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Concerns\InteractsWithTranslationStates;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Contracts\HasTranslationStates;

final class SharedArticle extends Model implements SharedTableTranslatableModel, HasTranslationStates
{
    use HasSharedTableTranslations;
    use InteractsWithTranslationStates;

    protected $table = 'shared_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
