<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Concerns\InteractsWithTranslationStates;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Contracts\HasTranslationStates;

final class JsonArticle extends Model implements JsonTranslatableModel, HasTranslationStates
{
    use HasJsonTranslations;
    use InteractsWithTranslationStates;

    protected $table = 'json_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
