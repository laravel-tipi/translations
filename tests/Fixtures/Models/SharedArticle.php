<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;

final class SharedArticle extends Model implements SharedTableTranslatableModel
{
    use HasSharedTableTranslations;

    protected $table = 'shared_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}