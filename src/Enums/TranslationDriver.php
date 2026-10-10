<?php

declare(strict_types=1);

namespace Tipi\Translations\Enums;

enum TranslationDriver: string
{
    case Json = 'json';
    case DedicatedTable = 'dedicated';
    case SharedTable = 'shared';
}
