<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Concerns\HasLocalizedTranslations;
use Tipi\Translations\Contracts\JsonTranslatableModel;

final class TranslatableControlArticle extends Model implements JsonTranslatableModel
{
    use HasJsonTranslations;
    use HasLocalizedTranslations;

    protected $table = 'json_articles';

    public $timestamps = false;

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
