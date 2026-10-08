<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Concerns\InteractsWithTranslationStates;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\Contracts\JsonTranslatableModel;

final class StatefulJsonArticle extends Model implements HasTranslationStates, JsonTranslatableModel
{
    use HasJsonTranslations;
    use InteractsWithTranslationStates;

    protected $table = 'json_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
