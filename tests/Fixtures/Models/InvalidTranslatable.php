<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasLocalizedTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

final class InvalidTranslatable extends Model implements TranslatableModel
{
    use HasLocalizedTranslations;

    protected static array $translatableAttributes = [
        'title',
    ];
}