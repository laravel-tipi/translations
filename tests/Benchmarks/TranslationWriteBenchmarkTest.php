<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;

it('benchmarks translation creation', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $result = benchmark(function () use ($article, $create): void {
        $create->execute(
            translatable: $article,
            localeCode: 'en',
            attributes: [
                'title' => 'English title',
                'description' => 'English description',
            ],
        );
    });

    test()->note(sprintf(
        '%s: create translation | %.3f ms | memory %+s | peak +%s',
        $article::class,
        $result['milliseconds'],
        formatBytes($result['memory_bytes']),
        formatBytes($result['peak_memory_bytes']),
    ));
})->with('translation storage strategies');

it('benchmarks translation update', function (
    Model&TranslatableModel $article,
): void {
    $article = createTranslatedArticle($article);

    $update = resolve(UpdateTranslation::class);

    // Warm framework / driver paths before measuring where applicable.
    $article->getTranslation('en');

    $result = benchmark(function () use ($article, $update): void {
        $update->execute(
            translatable: $article,
            localeCode: 'en',
            attributes: [
                'title' => 'Updated English title',
                'description' => 'Updated English description',
            ],
        );
    });

    test()->note(sprintf(
        '%s: update translation | %.3f ms | memory %+s | peak +%s',
        $article::class,
        $result['milliseconds'],
        formatBytes($result['memory_bytes']),
        formatBytes($result['peak_memory_bytes']),
    ));
})->with('translation storage strategies');

it('benchmarks intensive translation creation', function (
    Model&TranslatableModel $article,
): void {
    $create = resolve(CreateTranslation::class);

    $iterations = 1_000;
    $runs = 5;

    $milliseconds = [];

    for ($run = 0; $run < $runs; $run++) {
        gc_collect_cycles();

        $startedAt = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $model = new ($article::class);
            $model->save();

            $create->execute(
                translatable: $model,
                localeCode: 'en',
                attributes: [
                    'title' => 'English title',
                    'description' => 'English description',
                ],
            );
        }

        $milliseconds[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    $sortedMilliseconds = $milliseconds;
    sort($sortedMilliseconds);

    $medianMilliseconds = $sortedMilliseconds[
        intdiv(count($sortedMilliseconds), 2)
    ];

    test()->note(sprintf(
        '%s:',
        $article::class,
    ));

    foreach ($milliseconds as $index => $runMilliseconds) {
        test()->note(sprintf(
            '  run %d: %8.3f ms | %.3f ms/write',
            $index + 1,
            $runMilliseconds,
            $runMilliseconds / $iterations,
        ));
    }

    test()->note(sprintf(
        '  median: %8.3f ms | %.3f ms/write',
        $medianMilliseconds,
        $medianMilliseconds / $iterations,
    ));

    expect($model->exists)->toBeTrue()
        ->and($model->getTranslation('en'))->not->toBeNull();
})->with('translation storage strategies');

it('benchmarks complete translated model creation', function (
    Model&TranslatableModel $article,
): void {
    $create = resolve(CreateTranslation::class);

    $result = benchmark(function () use ($article, $create): void {
        $article->save();

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
    });

    test()->note(sprintf(
        '%s: model + 2 translations | %.3f ms | memory %+s | peak +%s',
        $article::class,
        $result['milliseconds'],
        formatBytes($result['memory_bytes']),
        formatBytes($result['peak_memory_bytes']),
    ));
})->with('translation storage strategies');

it('benchmarks intensive translation updates', function (
    Model&TranslatableModel $article,
): void {
    $create = resolve(CreateTranslation::class);
    $update = resolve(UpdateTranslation::class);

    $iterations = 1_000;
    $runs = 5;

    $models = [];

    for ($i = 0; $i < $iterations; $i++) {
        $model = new ($article::class);
        $model->save();

        $create->execute(
            translatable: $model,
            localeCode: 'en',
            attributes: [
                'title' => 'English title',
                'description' => 'English description',
            ],
        );

        $models[] = $model;
    }

    $milliseconds = [];

    for ($run = 0; $run < $runs; $run++) {
        gc_collect_cycles();

        $startedAt = hrtime(true);

        foreach ($models as $model) {
            $update->execute(
                translatable: $model,
                localeCode: 'en',
                attributes: [
                    'title' => "Updated title {$run}",
                    'description' => "Updated description {$run}",
                ],
            );
        }

        $milliseconds[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    $sortedMilliseconds = $milliseconds;
    sort($sortedMilliseconds);

    $medianMilliseconds = $sortedMilliseconds[
        intdiv(count($sortedMilliseconds), 2)
    ];

    test()->note(sprintf(
        '%s:',
        $article::class,
    ));

    foreach ($milliseconds as $index => $runMilliseconds) {
        test()->note(sprintf(
            '  run %d: %8.3f ms | %.3f ms/write',
            $index + 1,
            $runMilliseconds,
            $runMilliseconds / $iterations,
        ));
    }

    test()->note(sprintf(
        '  median: %8.3f ms | %.3f ms/write',
        $medianMilliseconds,
        $medianMilliseconds / $iterations,
    ));

    $lastModel = $models[array_key_last($models)];

    expect($lastModel->getTranslation('en'))->not->toBeNull();
})->with('translation storage strategies');

it('benchmarks single translation updates with 10 locales', function (
    Model&TranslatableModel $article,
): void {
    $create = resolve(CreateTranslation::class);
    $update = resolve(UpdateTranslation::class);

    $iterations = 1_000;
    $runs = 5;

    $locales = [
        'en',
        'ka',
        'de',
        'fr',
        'es',
        'it',
        'pt',
        'nl',
        'pl',
        'uk',
    ];

    $models = [];

    for ($i = 0; $i < $iterations; $i++) {
        $model = new ($article::class);
        $model->save();

        foreach ($locales as $localeCode) {
            $create->execute(
                translatable: $model,
                localeCode: $localeCode,
                attributes: [
                    'title' => "Title {$localeCode}",
                    'description' => "Description {$localeCode}",
                ],
            );
        }

        $models[] = $model;
    }

    $milliseconds = [];

    for ($run = 0; $run < $runs; $run++) {
        gc_collect_cycles();

        $startedAt = hrtime(true);

        foreach ($models as $model) {
            $update->execute(
                translatable: $model,
                localeCode: 'en',
                attributes: [
                    'title' => "Updated title {$run}",
                    'description' => "Updated description {$run}",
                ],
            );
        }

        $milliseconds[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    $sortedMilliseconds = $milliseconds;
    sort($sortedMilliseconds);

    $medianMilliseconds = $sortedMilliseconds[
        intdiv(count($sortedMilliseconds), 2)
    ];

    test()->note(sprintf(
        '%s:',
        $article::class,
    ));

    foreach ($milliseconds as $index => $runMilliseconds) {
        test()->note(sprintf(
            '  run %d: %8.3f ms | %.3f ms/write',
            $index + 1,
            $runMilliseconds,
            $runMilliseconds / $iterations,
        ));
    }

    test()->note(sprintf(
        '  median: %8.3f ms | %.3f ms/write',
        $medianMilliseconds,
        $medianMilliseconds / $iterations,
    ));

    $lastModel = $models[array_key_last($models)];

    expect($lastModel->getTranslation('en'))->not->toBeNull()
        ->and($lastModel->getTranslation('ka'))->not->toBeNull();
})->with('translation storage strategies');
