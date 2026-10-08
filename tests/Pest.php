<?php

declare(strict_types=1);

use Tipi\Translations\Tests\TestCase;

require_once __DIR__.'/Support/Benchmarks.php';

pest()
    ->extend(TestCase::class)
    ->in('Feature', 'Benchmarks');
