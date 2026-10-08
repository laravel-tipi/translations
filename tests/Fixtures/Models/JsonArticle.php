<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\JsonTranslatableModel;

final class JsonArticle extends Model implements JsonTranslatableModel
{
    use HasJsonTranslations;

    protected $table = 'json_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
