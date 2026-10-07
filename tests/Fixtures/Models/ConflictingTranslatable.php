<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;

final class ConflictingTranslatable extends Model implements
    JsonTranslatableModel,
    SharedTableTranslatableModel
{
    use HasSharedTableTranslations;

    protected static array $translatableAttributes = [
        'title',
    ];
}
