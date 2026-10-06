<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tipi\Translations\TranslationServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TranslationServiceProvider::class,
        ];
    }
}
