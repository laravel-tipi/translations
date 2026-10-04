<?php

declare(strict_types=1);

namespace Tipi\Translations\Enums;

enum TranslationDriver: string
{
    case Json = 'json';
    case DedicatedTable = 'dedicated_table';
    case SharedTable = 'shared_table';
}
