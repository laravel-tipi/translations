<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasDedicatedTableTranslations;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;

final class ConflictingOutdatedTrackingTranslatable extends Model implements
    DedicatedTableTranslatableModel,
    SharedTableTranslatableModel
{
    use HasDedicatedTableTranslations;

    protected static array $translatableAttributes = [
        'title',
    ];
}