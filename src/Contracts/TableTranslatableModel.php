<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Illuminate\Database\Eloquent\Relations\Relation;

interface TableTranslatableModel extends TranslatableModel, TracksOutdatedTranslations
{
    public function translationRecords(): Relation;
}
