<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;

dataset('translation storage strategies', [
    'dedicated table' => fn (): DedicatedArticle => new DedicatedArticle,
    'shared table' => fn (): SharedArticle => new SharedArticle,
    'json' => fn (): JsonArticle => new JsonArticle,
]);

/**
 * @return array{
 *     milliseconds: float,
 *     memory_bytes: int,
 *     peak_memory_bytes: int,
 * }
 */
function benchmark(Closure $callback): array
{
    gc_collect_cycles();

    $memoryBefore = memory_get_usage();
    $peakBefore = memory_get_peak_usage();

    $startedAt = hrtime(true);

    $callback();

    $elapsedNanoseconds = hrtime(true) - $startedAt;

    $memoryAfter = memory_get_usage();
    $peakAfter = memory_get_peak_usage();

    return [
        'milliseconds' => $elapsedNanoseconds / 1_000_000,
        'memory_bytes' => $memoryAfter - $memoryBefore,
        'peak_memory_bytes' => max(0, $peakAfter - $peakBefore),
    ];
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return sprintf('%.2f MB', $bytes / 1024 / 1024);
    }

    if ($bytes >= 1024) {
        return sprintf('%.2f KB', $bytes / 1024);
    }

    return sprintf('%d B', $bytes);
}

function createTranslatedArticle(Model&TranslatableModel $article): Model&TranslatableModel
{
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'English title',
            'description' => 'English description',
        ],
    );

    $create->execute(
        translatable: $article,
        localeCode: 'ka',
        attributes: [
            'title' => 'ქართული სათაური',
            'description' => 'ქართული აღწერა',
        ],
    );

    return $article;
}

/**
 * @param  callable(): void  $callback
 */
function benchmarkAverage(callable $callback, int $runs = 5): float
{
    $milliseconds = [];

    for ($run = 0; $run < $runs; $run++) {
        $startedAt = hrtime(true);

        $callback();

        $milliseconds[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    return array_sum($milliseconds) / count($milliseconds);
}
