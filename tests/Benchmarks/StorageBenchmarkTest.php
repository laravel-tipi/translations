<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedCastArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\TestCase;

/*
 | Compares the storages on one database. Not part of the test suite:
 |
 |   vendor/bin/pest tests/Benchmarks
 |   BENCH_MODELS=20000 BENCH_NOISE=30000 TRANSLATIONS_TEST_DB=pgsql ... vendor/bin/pest tests/Benchmarks
 |
 | BENCH_MODELS models per storage, each with two attributes in three
 | locales; BENCH_NOISE models of another type add rows to the shared
 | table. Prints the median of seven runs per operation.
 */

define('BENCH_MODELS', (int) (getenv('BENCH_MODELS') ?: 2000));
define('BENCH_NOISE', (int) (getenv('BENCH_NOISE') ?: 8000));

function benchSeed(): void
{
    $now = now()->toDateTimeString();
    $locales = ['en' => 'Wine', 'ka' => 'ღვინო', 'de' => 'Wein'];

    foreach (array_chunk(range(1, BENCH_MODELS), 500) as $ids) {
        DB::table('shared_articles')->insert(array_map(fn ($id) => ['id' => $id, 'slug' => "w{$id}", 'created_at' => $now, 'updated_at' => $now], $ids));
    }

    foreach (array_chunk(range(1, BENCH_NOISE), 500) as $ids) {
        DB::table('shared_cast_articles')->insert(array_map(fn ($id) => ['id' => $id, 'created_at' => $now, 'updated_at' => $now], $ids));
    }

    foreach (array_chunk(range(1, BENCH_MODELS), 250) as $ids) {
        $json = $table = $tableRows = $shared = [];

        foreach ($ids as $id) {
            $title = $body = [];
            foreach ($locales as $locale => $word) {
                $title[$locale] = "{$word} {$id}";
                $body[$locale] = "Body {$id}";
                $tableRows[] = ['table_article_id' => $id, 'locale_code' => $locale, 'title' => "{$word} {$id}", 'body' => "Body {$id}", 'created_at' => $now, 'updated_at' => $now];
                foreach (['title' => "{$word} {$id}", 'body' => "Body {$id}"] as $attribute => $value) {
                    $shared[] = ['translatable_type' => SharedArticle::class, 'translatable_id' => $id, 'locale_code' => $locale, 'attribute' => $attribute, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now];
                }
            }
            $json[] = ['id' => $id, 'slug' => "w{$id}", 'title' => json_encode($title, JSON_UNESCAPED_UNICODE), 'body' => json_encode($body), 'created_at' => $now, 'updated_at' => $now];
            $table[] = ['id' => $id, 'slug' => "w{$id}", 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('json_articles')->insert($json);
        DB::table('table_articles')->insert($table);
        DB::table('table_article_translations')->insert($tableRows);
        foreach (array_chunk($shared, 500) as $chunk) {
            DB::table('translations')->insert($chunk);
        }
    }

    // Other models' rows in the shared table.
    foreach (array_chunk(range(1, BENCH_NOISE), 80) as $ids) {
        $noise = [];
        foreach ($ids as $id) {
            foreach (['en', 'ka', 'de'] as $locale) {
                foreach (['content', 'position'] as $attribute) {
                    $noise[] = ['translatable_type' => SharedCastArticle::class, 'translatable_id' => $id, 'locale_code' => $locale, 'attribute' => $attribute, 'value' => '"Noise"', 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        DB::table('translations')->insert($noise);
    }

    if (TestCase::databaseDriver() === 'pgsql') {
        foreach (['json_articles', 'table_articles', 'table_article_translations', 'translations', 'shared_articles', 'shared_cast_articles'] as $t) {
            DB::statement("select setval(pg_get_serial_sequence('{$t}', 'id'), (select max(id) from {$t}))");
        }
        DB::statement('analyze');
    } elseif (TestCase::databaseDriver() !== 'sqlite') {
        foreach (['json_articles', 'table_articles', 'table_article_translations', 'translations', 'shared_articles', 'shared_cast_articles'] as $t) {
            DB::statement("analyze table {$t}");
        }
    }
}

/** Median milliseconds of $runs runs. */
function benchTime(Closure $callback, int $runs = 7): float
{
    $callback();
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $callback();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return round($times[intdiv($runs, 2)], 2);
}

it('benchmarks the storages', function (): void {
    benchSeed();
    $results = [];
    $n = 0;

    foreach (['json' => JsonArticle::class, 'table' => TableArticle::class, 'shared' => SharedArticle::class] as $storage => $class) {
        useLocale('ka');
        $results[$storage] = [
            'eager load 100 + read' => benchTime(function () use ($class) {
                foreach ($class::withTranslations()->limit(100)->get() as $a) {
                    $a->title;
                    $a->getAllTranslations();
                }
            }),
            'where = (exact)' => benchTime(fn () => $class::whereTranslation('title', '=', 'Wine 1500', 'en')->pluck('id')),
            'where like' => benchTime(fn () => $class::whereTranslation('title', 'like', '%ine 15%', 'en')->count()),
            'where = (fallback)' => benchTime(fn () => $class::whereTranslation('title', '=', 'Wine 1500', 'fr', fallback: true)->pluck('id')),
            'order by, first 20' => benchTime(fn () => $class::orderByTranslation('title', 'asc', 'en')->limit(20)->pluck('id')),
            'pluck all (fallback)' => benchTime(fn () => $class::pluckTranslation('title', locale: 'fr', fallback: true)),
            'save 1 value' => benchTime(function () use ($class, &$n) {
                $class::query()->find(1000)->setTranslation('title', 'fr', 'v'.$n++)->save();
            }),
            'create with 6 values' => benchTime(fn () => article($class, ['en' => ['title' => 'a', 'body' => 'b'], 'ka' => ['title' => 'c', 'body' => 'd'], 'de' => ['title' => 'e', 'body' => 'f']])),
            'delete' => benchTime(fn () => $class::query()->latest('id')->first()->delete()),
        ];
    }

    $plan = match (TestCase::databaseDriver()) {
        'sqlite' => 'explain query plan ',
        'pgsql' => 'explain ',
        default => 'explain ',
    };
    $query = SharedArticle::whereTranslation('title', '=', 'Wine 1500', 'en');
    $explain = collect(DB::select($plan.$query->toSql(), $query->getBindings()))->map(fn ($r) => json_encode(array_values((array) $r)))->implode("\n");

    $lines = ['DB: '.TestCase::databaseDriver().' ('.BENCH_MODELS.' models per storage; shared table has '.DB::table('translations')->count().' rows)'];
    $lines[] = str_pad('operation (median ms)', 26).str_pad('json', 10).str_pad('table', 10).'shared';
    foreach (array_keys($results['json']) as $op) {
        $lines[] = str_pad($op, 26).str_pad((string) $results['json'][$op], 10).str_pad((string) $results['table'][$op], 10).$results['shared'][$op];
    }
    $lines[] = 'Plan of shared whereTranslation =:';
    $lines[] = $explain;
    fwrite(STDOUT, "\n".implode("\n", $lines)."\n");
    expect($results)->toHaveCount(3);
});
