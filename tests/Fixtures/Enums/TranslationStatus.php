<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Enums;

enum TranslationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
