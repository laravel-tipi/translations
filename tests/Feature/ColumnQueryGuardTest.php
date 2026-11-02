<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Tipi\Translations\Exceptions\TranslatableColumnQueryException;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;

/*
 | A translatable attribute is not a column: comparing or sorting it as
 | one would compare a JSON map (JSON storage) or fail on a missing
 | column (table storage). Such queries are rejected with a pointer to the
 | translation scopes.
 */

it('rejects translatable attributes used as columns', function (string $class, string $clause): void {
    article($class, ['en' => ['title' => 'Saperavi']], ['slug' => 'saperavi']);

    $query = $class::query();

    match ($clause) {
        'where' => $query->where('title', 'Saperavi'),
        'qualified where' => $query->where($query->qualifyColumn('title'), 'Saperavi'),
        'where in' => $query->whereIn('title', ['Saperavi']),
        'where null' => $query->whereNull('title'),
        'nested where' => $query->where('slug', 'saperavi')->where(fn (Builder $nested) => $nested->orWhere('title', 'Saperavi')),
        'where column' => $query->whereColumn('slug', 'title'),
        'order by' => $query->orderBy('title'),
    };

    expect(fn () => $query->get())->toThrow(
        TranslatableColumnQueryException::class,
        "Cannot query the translatable attribute [title] of [{$class}] as a column. Use whereTranslation() or orderByTranslation().",
    );
})->with('storages')->with(['where', 'qualified where', 'where in', 'where null', 'nested where', 'where column', 'order by']);

it('rejects translatable attributes in firstOrCreate() lookups instead of creating duplicates', function (string $class): void {
    article($class, ['en' => ['title' => 'Saperavi']]);

    expect(fn () => $class::firstOrCreate(['title' => 'Saperavi']))->toThrow(TranslatableColumnQueryException::class)
        ->and($class::query()->count())->toBe(1);
})->with('storages');

it('rejects translatable attributes in mass updates and deletes', function (string $class): void {
    article($class, ['en' => ['title' => 'Saperavi']]);

    expect(fn () => $class::query()->where('title', 'Saperavi')->update(['slug' => 'changed']))->toThrow(TranslatableColumnQueryException::class)
        ->and(fn () => $class::query()->where('title', 'Saperavi')->delete())->toThrow(TranslatableColumnQueryException::class)
        ->and($class::query()->value('slug'))->toBeNull();
})->with('storages');

it('allows columns, translation scopes and JSON paths', function (): void {
    $article = article(JsonArticle::class, ['en' => ['title' => 'Saperavi']], ['slug' => 'saperavi']);

    expect(JsonArticle::query()->where('slug', 'saperavi')->whereTranslation('title', '=', 'Saperavi')->orderByTranslation('title')->pluck('id')->all())->toBe([$article->id])
        ->and(JsonArticle::query()->whereNotNull('title->en')->pluck('id')->all())->toBe([$article->id])
        ->and(JsonArticle::query()->firstOrCreate(['slug' => 'saperavi'], ['title' => 'Other'])->is($article))->toBeTrue();
});
