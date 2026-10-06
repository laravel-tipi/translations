<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;

final class SoftDeletingSharedArticle extends Model implements SharedTableTranslatableModel
{
    use HasSharedTableTranslations;
    use SoftDeletes;

    protected $table = 'soft_deleting_shared_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
