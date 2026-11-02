# Upgrade Guide

## From 0.1 to 0.2

0.2 is a redesign. The model API replaces the actions, managers and stores, Localization 0.2 replaces the package's own locale provider, and the shared-table storage is gone. There are no compatibility aliases. This guide maps the old API to the new one.

### Requirements

- `laravel-tipi/localization` ^0.2 (was ^0.1). Configure locales there.
- The service provider is now `Tipi\Translations\TranslationsServiceProvider` (auto-discovered).

### Declaring a translatable model

| 0.1 | 0.2 |
|---|---|
| `protected static array $translatableAttributes = [...]` | `#[Translatable([...])]` on the class |
| `implements DedicatedTableTranslatableModel` + `use HasDedicatedTableTranslations` | `implements TranslatableModel` + `use HasTableTranslations` |
| `implements JsonTranslatableModel` + `use HasJsonTranslations` | `implements TranslatableModel` + `use HasJsonTranslations` |
| `implements SharedTableTranslatableModel` + `use HasSharedTableTranslations` | removed: use table or JSON storage |
| `static::$translationModel` | define the `translations()` relation on the model |
| translation model `implements TranslationModel` + `use IsTranslation` | a plain Eloquent model: remove both |
| `implements HasTranslationStates` + `use InteractsWithTranslationStates` | `use HasTranslationStates` (no interface) |

```php
// 0.1
class Wine extends Model implements DedicatedTableTranslatableModel, HasTranslationStates
{
    use HasDedicatedTableTranslations, InteractsWithTranslationStates;

    protected static array $translatableAttributes = ['name', 'description'];
}

// 0.2
#[Translatable(['name', 'description'])]
class Wine extends Model implements TranslatableModel
{
    use HasTableTranslations, HasTranslationStates;
}
```

Remove `'array'` casts that 0.1 merged for JSON translation columns if you added them yourself. The trait reads and writes those columns.

### Relations

| 0.1 | 0.2 |
|---|---|
| `translationRecords()` / `with('translationRecords')` | `translations()` / `with('translations')` or `withTranslations()` |
| `defaultTranslationRecord()` | removed: read values with `getTranslation()` |

The relation is hidden from `toArray()`. Use `makeVisible('translations')` to serialize the rows.

### Reading

| 0.1 | 0.2 |
|---|---|
| `$wine->name` (current locale, **no** fallback) | `$wine->name` (current locale, **with** fallback) |
| `$wine->translated('name', $default, 'ka')` | `$wine->getTranslation('name', 'ka') ?? $default` |
| `$wine->getTranslation('ka')?->attributes` (a `Translation` DTO) | `$wine->getTranslations('ka')` (an array) |
| `$wine->getTranslations()` (a collection of DTOs) | `$wine->getAllTranslations()` (locale ⇒ attribute ⇒ value) |
| `$wine->translationExists('ka')` | `in_array('ka', $wine->getTranslatedLocales())` or `$wine->hasTranslation('name', 'ka')` |
| `$wine->isTranslationMissing('ka')` | `! in_array('ka', $wine->getTranslatedLocales())` |
| `$wine->hasMissingTranslations()` / `canBeTranslated()` | `array_diff(Localization::context()->codes(), $wine->getTranslatedLocales()) !== []` |
| `$translation->localeCode`, `$translation->translatable` | the array key, the model itself |

**Behavior change:** plain attribute access now falls back to the context default and then to the global default. Code that relied on `null` for a missing current-locale value must use `getTranslation($attribute)`, which stays exact.

**Behavior change:** `null` is now always "missing". JSON columns no longer store `null` values, and a table row whose values are all `null` is deleted. Empty strings, `0` and `false` are translations and no longer fall back to a default (0.1's `??` treated stored `null` as missing, but not `''`).

### Writing

The `CreateTranslation`, `UpdateTranslation`, `DeleteTranslation`, `MarkOthersAsOutdated` and `LockTranslatable` actions are removed. Writes go through the model and `save()`, which is transactional and concurrency-safe.

```php
// 0.1
resolve(CreateTranslation::class)->execute(translatable: $wine, attributes: ['name' => 'საფერავი'], localeCode: 'ka');
resolve(UpdateTranslation::class)->execute(translatable: $wine, attributes: ['name' => 'Saperavi'], localeCode: 'en', markOthersAsOutdated: true);
resolve(DeleteTranslation::class)->execute(translatable: $wine, localeCode: 'ka');

// 0.2
$wine->setTranslations('ka', ['name' => 'საფერავი'])->save();

$wine->setTranslations('en', ['name' => 'Saperavi'])->save();
$wine->markOtherTranslationsOutdated('en');

$wine->forgetTranslations('ka')->save();
```

- There is no separate create and update. `setTranslations()` creates or updates, so `TranslationAlreadyExistsException` and `TranslationDoesNotExistException` are gone.
- The `dbTransaction` option is gone: `save()` always uses a transaction and joins yours.
- Deleting the default locale's translation is no longer forbidden by the package (`DefaultTranslationCannotBeDeletedException` is gone). Enforce it with validation if your application requires it.
- `CreateTranslation` without a locale used the current locale. Plain assignment (`$wine->name = ...`) does that now.
- Translations of unsaved table-storage models no longer throw: they are stored when the model is first saved.
- Table rows are written with upsert queries against the required unique index on the foreign key and `locale_code`. The translation model's casts and mutators apply, but **its model events and `$touches` no longer run**. Move listeners on translation rows to the translatable model's events.
- Translations are written inside Eloquent's save, so model events see them: changes made in `saving`, `creating` and `updating` listeners are saved, `created`, `updated` and `saved` listeners see them stored, and `isDirty()` / `wasChanged()` report translated attributes. See *Change tracking and model events* in the README.

### Translation states

| 0.1 | 0.2 |
|---|---|
| `TranslationStateManager::get($model, 'ka')` | `$model->getTranslationState('ka')` |
| `TranslationStateManager::markAsOutdated / markAsCurrent` | `$model->markTranslationOutdated('ka')` / `markTranslationCurrent('ka')` |
| `TranslationStateManager::markOthersAsOutdated` / `MarkOthersAsOutdated` action | `$model->markOtherTranslationsOutdated('en')` |
| `TranslationStateManager::setStatus` | `$model->setTranslationStatus('ka', $status)` |
| state created by `CreateTranslation`, current on `UpdateTranslation` | kept in sync by `save()` for every changed locale |
| `markOthersAsOutdated` overwrote `outdated_at` | an already outdated locale keeps its original `outdated_at` |

The `translation_states` table is unchanged. Config keys moved:

| 0.1 | 0.2 |
|---|---|
| `translations.translation_states_table` | `translations.states.table` |
| `translations.translation_state_model` | `translations.states.model` |
| `translations.translation_state_status_enum` | `translations.states.status_enum` |
| `translations.translations_table`, `translation_model`, `locale_provider` | removed |

### Querying

| 0.1 | 0.2 |
|---|---|
| `whereTranslation($attr, $op, $value, $locale)` | unchanged, plus `fallback:` |
| `orWhereTranslation(...)`, `orderByTranslation(...)` | unchanged, plus `fallback:`. Missing values now sort last on every database |
| `Builder::pluckTranslation($attr, $key, $locale, $fallbackColumn)` (global macro) | `Wine::pluckTranslation($attr, $key, $locale, fallback: true)` (model scope); `$fallbackColumn` is gone |
| | new: `whereHasTranslation()`, `whereDoesntHaveTranslation()`, `withTranslations()` |
| `where('name', ...)`, `orderBy('name')`, `firstOrCreate(['name' => ...])` on a translatable attribute | throw `TranslatableColumnQueryException`: use the scopes above, and look up `firstOrCreate()` by a column |

**Behavior change:** JSON-storage queries compare JSON numbers numerically with numeric operands and sort them numerically, on every database. Operands must be strings, finite numbers, backed enums or `Stringable` objects. Booleans and arrays throw `InvalidArgumentException`.

### Locales

| 0.1 | 0.2 |
|---|---|
| `Tipi\Translations\Contracts\LocaleProvider` | `Tipi\Localization\Facades\Localization` |
| `->current()` | `Localization::current()` |
| `->default()` | `Localization::locales()->default()` (global) or `Localization::context()->default()` |
| `->supported()` | `Localization::context()->supported()` or `Localization::locales()->active()` |
| `->supportedLocale($code)` | `Localization::locales()->get($code)` |
| `translation->locale()`, `isDefault()` | `Localization::locales()->get($code)`, `$code === Localization::locales()->defaultCode()` |

Unknown locales now throw Localization's `LocaleNotFoundException`.

### Validation and policies

`TranslatableRules`, `TranslationRules` and `HasTranslatablePolicy` are removed. Use `Tipi\Translations\Rules\Translations` for translation payloads and ordinary Laravel rules for the values (see the README). The old `exists:locales,code` rule did not work with config-driven locales.

### Removed classes

Actions (`CreateTranslation`, `UpdateTranslation`, `DeleteTranslation`, `LockTranslatable`, `MarkOthersAsOutdated`), `TranslationManager`, `TranslationQueryManager`, `TranslationStateManager`, `TranslationDriverResolver`, `Enums\TranslationDriver`, `Config\TranslationConfig`, `Translation` (DTO), `Models\Translation`, the three stores and three query drivers, `Providers\LocalizationLocaleProvider`, the contracts `LocaleProvider`, `TranslatableModel` (old shape), `TableTranslatableModel`, `DedicatedTableTranslatableModel`, `SharedTableTranslatableModel`, `JsonTranslatableModel`, `TranslationModel`, `HasTranslationStates` (interface), `TranslationQueryDriver`, the traits `HasDedicatedTableTranslations`, `HasSharedTableTranslations`, `HasLocalizedTranslations`, `HasTranslationQueries`, `InteractsWithTranslationStates`, `IsTranslation`, `HasTranslatablePolicy`, and the exceptions `DefaultTranslationCannotBeDeletedException`, `EmptyTranslationException`, `InvalidTranslationAttributeException` (now `AttributeNotTranslatableException`), `InvalidTranslationConfigurationException` (now `InvalidTranslatableModelException`), `TranslationAlreadyExistsException`, `TranslationDoesNotExistException`, `TranslationConfigurationException`. `TranslationException` is now an interface.

### Shared-table data

If you used shared-table storage, move the data before upgrading: create a translation table per model (or JSON columns) and copy each `translations` row's `values` into it. The package no longer ships the `translations` table migration, and it does not drop your existing table.
