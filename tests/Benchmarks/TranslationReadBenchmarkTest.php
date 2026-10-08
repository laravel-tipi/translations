<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Models\ControlArticle;
use Tipi\Translations\Tests\Fixtures\Models\DelegatingArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\PlainArticle;
use Tipi\Translations\Tests\Fixtures\Models\TranslatableControlArticle;

it('benchmarks intensive translated attribute reads', function (
    Model&TranslatableModel $article,
): void {
    $article = createTranslatedArticle($article);

    // Warm caches before measuring.
    $article->title;

    $result = benchmark(function () use ($article): void {
        for ($i = 0; $i < 100_000; $i++) {
            $article->title;
            $article->description;
        }
    });

    test()->note(sprintf(
        '%s: 200,000 reads | %.3f ms | memory %+s | peak +%s',
        $article::class,
        $result['milliseconds'],
        formatBytes($result['memory_bytes']),
        formatBytes($result['peak_memory_bytes']),
    ));
})->with('translation storage strategies');

it('benchmarks intensive translation reads', function (
    Model&TranslatableModel $article,
): void {
    $article = createTranslatedArticle($article);

    // Warm translation cache before measuring.
    $article->getTranslation('en');

    $result = benchmark(function () use ($article): void {
        for ($i = 0; $i < 100_000; $i++) {
            $article->getTranslation('en');
        }
    });

    test()->note(sprintf(
        '%s: 100,000 reads | %.3f ms | memory %+s | peak +%s',
        $article::class,
        $result['milliseconds'],
        formatBytes($result['memory_bytes']),
        formatBytes($result['peak_memory_bytes']),
    ));
})->with('translation storage strategies');

it('benchmarks translation cache memory', function (
    Model&TranslatableModel $article,
): void {
    $article = createTranslatedArticle($article);

    gc_collect_cycles();

    $before = memory_get_usage();

    $english = $article->getTranslation('en');
    $georgian = $article->getTranslation('ka');

    $after = memory_get_usage();

    // Keep references alive until after measurement.
    expect($english)->not->toBeNull()
        ->and($georgian)->not->toBeNull();

    test()->note(sprintf(
        '%s: 2 cached translations retain %s',
        $article::class,
        formatBytes($after - $before),
    ));
})->with('translation storage strategies');

it('benchmarks translated attribute read layers', function (
    Model&TranslatableModel $article,
): void {
    $article = createTranslatedArticle($article);

    // Warm the translation cache.
    $translation = $article->getTranslation('en');

    expect($translation)->not->toBeNull();

    $iterations = 100_000;

    $benchmarks = [
        'direct DTO array access' => function () use (
            $translation,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                $translation->attributes['title'] ?? null;
            }
        },

        'getTranslation' => function () use (
            $article,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                $article->getTranslation('en');
            }
        },

        'translated' => function () use (
            $article,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                $article->translated(
                    attribute: 'title',
                    localeCode: 'en',
                );
            }
        },

        'getAttribute' => function () use (
            $article,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                $article->getAttribute('title');
            }
        },

        'property access' => function () use (
            $article,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                $article->title;
            }
        },
    ];

    test()->note(sprintf(
        '%s:',
        $article::class,
    ));

    foreach ($benchmarks as $name => $callback) {
        gc_collect_cycles();

        $startedAt = hrtime(true);

        $callback();

        $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        test()->note(sprintf(
            '  %-24s %8.3f ms',
            $name,
            $milliseconds,
        ));
    }
})->with('translation storage strategies');

it('benchmarks translated attribute lookup operations', function (
    Model&TranslatableModel $article,
): void {
    $article = createTranslatedArticle($article);

    $translation = $article->getTranslation('en');

    expect($translation)->not->toBeNull();

    $iterations = 100_000;

    $translatableAttributes = $article::getTranslatableAttributes();

    $translatableAttributeMap = array_fill_keys(
        $translatableAttributes,
        true,
    );

    $benchmarks = [
        'in_array' => function () use (
            $translatableAttributes,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                in_array('title', $translatableAttributes, true);
            }
        },

        'isset map' => function () use (
            $translatableAttributeMap,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                isset($translatableAttributeMap['title']);
            }
        },

        'data_get' => function () use (
            $translation,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                data_get(
                    target: $translation->attributes,
                    key: 'title',
                );
            }
        },

        'array access' => function () use (
            $translation,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                $translation->attributes['title'] ?? null;
            }
        },
    ];

    test()->note(sprintf(
        '%s:',
        $article::class,
    ));

    foreach ($benchmarks as $name => $callback) {
        gc_collect_cycles();

        $startedAt = hrtime(true);

        $callback();

        $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        test()->note(sprintf(
            '  %-24s %8.3f ms',
            $name,
            $milliseconds,
        ));
    }
})->with('translation storage strategies');

it('benchmarks eloquent versus translated attribute access', function (
    Model&TranslatableModel $article,
): void {
    $article = createTranslatedArticle($article);

    // Warm translation cache.
    $article->getTranslation('en');

    $iterations = 100_000;

    $startedAt = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $article->id;
    }

    $eloquentMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

    $startedAt = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $article->title;
    }

    $translatedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

    test()->note(sprintf(
        '%s: Eloquent %.3f ms | translated %.3f ms | overhead %.2fx',
        $article::class,
        $eloquentMilliseconds,
        $translatedMilliseconds,
        $translatedMilliseconds / $eloquentMilliseconds,
    ));

    expect($article->id)->not->toBeNull();
})->with('translation storage strategies');

it('benchmarks overhead on non-translatable attributes', function (): void {
    $iterations = 100_000;

    $plain = new PlainArticle;
    $plain->setRawAttributes([
        'id' => 1,
        'slug' => 'article',
    ]);

    $translatable = new JsonArticle;
    $translatable->setRawAttributes([
        'id' => 1,
        'slug' => 'article',
    ]);

    $plainMilliseconds = benchmarkAverage(
        callback: function () use ($plain, $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                $plain->slug;
            }
        },
    );

    $translatableMilliseconds = benchmarkAverage(
        callback: function () use ($translatable, $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                $translatable->slug;
            }
        },
    );

    $plainDirectMilliseconds = benchmarkAverage(
        callback: function () use ($plain, $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                $plain->getAttribute('slug');
            }
        },
    );

    $translatableDirectMilliseconds = benchmarkAverage(
        callback: function () use ($translatable, $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                $translatable->getAttribute('slug');
            }
        },
    );

    test()->note(sprintf(
        'Direct getAttribute: plain %.3f ms | translatable %.3f ms | %.2fx',
        $plainDirectMilliseconds,
        $translatableDirectMilliseconds,
        $translatableDirectMilliseconds / $plainDirectMilliseconds,
    ));

    test()->note(sprintf(
        'Property access: plain %.3f ms | translatable %.3f ms | %.2fx',
        $plainMilliseconds,
        $translatableMilliseconds,
        $translatableMilliseconds / $plainMilliseconds,
    ));

    test()->note(sprintf(
        '%s non-translatable reads: plain Eloquent %.3f ms | translatable %.3f ms | overhead %.2fx',
        number_format($iterations),
        $plainMilliseconds,
        $translatableMilliseconds,
        $translatableMilliseconds / $plainMilliseconds,
    ));

    expect($plain->slug)->toBe('article')
        ->and($translatable->slug)->toBe('article');
});

it('benchmarks non-translatable attribute interception', function (): void {
    $article = new JsonArticle;

    $iterations = 100_000;

    $attributes = $article::getTranslatableAttributes();

    $benchmarks = [
        'getTranslatableAttributes' => function () use (
            $article,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                $article::getTranslatableAttributes();
            }
        },

        'local in_array' => function () use (
            $attributes,
            $iterations,
        ): void {
            for ($i = 0; $i < $iterations; $i++) {
                in_array('slug', $attributes, true);
            }
        },

        'actual condition' => function () use (
            $article,
            $iterations,
        ): void {
            $key = 'slug';

            for ($i = 0; $i < $iterations; $i++) {
                is_string($key)
                    && in_array(
                        $key,
                        $article::getTranslatableAttributes(),
                        true,
                    );
            }
        },
    ];

    foreach ($benchmarks as $name => $callback) {
        $milliseconds = benchmarkAverage($callback);

        test()->note(sprintf(
            '%-28s %.3f ms',
            $name,
            $milliseconds,
        ));
    }

    expect($article::getTranslatableAttributes())->not->toBeEmpty();
});

it('benchmarks getAttribute interception overhead', function (): void {
    $iterations = 100_000;

    $control = new ControlArticle;
    $control->setRawAttributes([
        'id' => 1,
        'slug' => 'article',
    ]);

    $delegating = new DelegatingArticle;
    $delegating->setRawAttributes([
        'id' => 1,
        'slug' => 'article',
    ]);

    $translatable = new TranslatableControlArticle;
    $translatable->setRawAttributes([
        'id' => 1,
        'slug' => 'article',
    ]);

    $controlMilliseconds = benchmarkAverage(
        callback: function () use ($control, $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                $control->getAttribute('slug');
            }
        },
    );

    $delegatingMilliseconds = benchmarkAverage(
        callback: function () use ($delegating, $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                $delegating->getAttribute('slug');
            }
        },
    );

    $translatableMilliseconds = benchmarkAverage(
        callback: function () use ($translatable, $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                $translatable->getAttribute('slug');
            }
        },
    );

    test()->note(sprintf(
        '%s getAttribute calls:',
        number_format($iterations),
    ));

    test()->note(sprintf(
        '  Control:      %.3f ms',
        $controlMilliseconds,
    ));

    test()->note(sprintf(
        '  Delegating:   %.3f ms | %.2fx control | +%.3f ms',
        $delegatingMilliseconds,
        $delegatingMilliseconds / $controlMilliseconds,
        $delegatingMilliseconds - $controlMilliseconds,
    ));

    test()->note(sprintf(
        '  Translatable: %.3f ms | %.2fx control | +%.3f ms',
        $translatableMilliseconds,
        $translatableMilliseconds / $controlMilliseconds,
        $translatableMilliseconds - $controlMilliseconds,
    ));

    test()->note(sprintf(
        '  Translation condition cost beyond delegation: +%.3f ms',
        $translatableMilliseconds - $delegatingMilliseconds,
    ));

    expect($control->slug)->toBe('article')
        ->and($delegating->slug)->toBe('article')
        ->and($translatable->slug)->toBe('article');
});
