<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Concerns\InteractsWithTranslationStates;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\Contracts\JsonTranslatableModel;

final class SoftDeletingStatefulJsonArticle extends Model implements HasTranslationStates, JsonTranslatableModel
{
    use HasJsonTranslations;
    use InteractsWithTranslationStates;
    use SoftDeletes;

    protected $table = 'json_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
