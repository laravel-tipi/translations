<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tipi\Translations\Support\MorphKeyType;

/*
 | Upgrades a v0.1 shared translations table (one row per model and
 | locale, a "values" JSON object) to v0.2 (one row per model, locale and
 | attribute, a "value" JSON value).
 |
 | Does nothing when the table is not a v0.1 table: on new installations,
 | and when you don't use shared storage and removed its migration.
 |
 | Nothing is lost: the rows are copied into a new table and a v0.1 table
 | with rows is kept as "{table}_v0_1". Drop it once you have checked the
 | new table. An empty v0.1 table (shared storage unused, or a fresh
 | database replaying 0.1 migrations) is dropped. Null values are not
 | copied: they are missing translations. Columns v0.1 kept besides
 | "values" (such as the "outdated_at" of releases before 0.1.16) stay in
 | the kept table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('translations.shared.table', 'translations');
        $legacy = "{$table}_v0_1";
        $next = "{$table}_v0_2";

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'values')) {
            return;
        }

        if (Schema::hasTable($legacy)) {
            throw new RuntimeException("Cannot upgrade [{$table}]: [{$legacy}] already exists.");
        }

        $keyType = MorphKeyType::configured();

        if ($keyType !== MorphKeyType::Int && $keyType !== MorphKeyType::String) {
            throw new RuntimeException("Cannot upgrade [{$table}]: its keys are integers, which a [{$keyType->value}] translatable_id column cannot hold. Set [translations.morph_key_type] to \"int\" or \"string\".");
        }

        // Left over by an upgrade that failed on a database without
        // transactional schema changes.
        Schema::dropIfExists($next);

        Schema::create($next, function (Blueprint $blueprint) use ($table, $keyType): void {
            $blueprint->id();

            $keyType->addColumns($blueprint, 'translatable');
            $blueprint->string('locale_code', 16);
            $blueprint->string('attribute', 64);
            $blueprint->json('value');

            $blueprint->timestamps();

            $blueprint->unique(['translatable_type', 'translatable_id', 'locale_code', 'attribute'], "{$table}_owner_unique");
        });

        DB::table($table)->orderBy('id')->chunk(500, function ($rows) use ($table, $next, $keyType): void {
            $copies = [];

            foreach ($rows as $row) {
                $values = $row->values === null ? null : json_decode((string) $row->values, true, flags: JSON_THROW_ON_ERROR);

                if ($values === null) {
                    continue;
                }

                if (! is_array($values) || ($values !== [] && array_is_list($values))) {
                    throw new RuntimeException("Cannot upgrade [{$table}]: the values of row [{$row->id}] are not a JSON object.");
                }

                foreach ($values as $attribute => $value) {
                    if ($value === null) {
                        continue;
                    }

                    $copies[] = [
                        'translatable_type' => $row->translatable_type,
                        'translatable_id' => $keyType === MorphKeyType::String ? (string) $row->translatable_id : $row->translatable_id,
                        'locale_code' => $row->locale_code,
                        'attribute' => (string) $attribute,
                        'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                }
            }

            foreach (array_chunk($copies, 500) as $chunk) {
                DB::table($next)->insert($chunk);
            }
        });

        $empty = ! DB::table($table)->exists();

        Schema::rename($table, $legacy);
        Schema::rename($next, $table);

        if ($empty) {
            Schema::drop($legacy);
        }
    }

    /**
     * Restores a kept v0.1 table. Translations written since the upgrade
     * are lost. Without a kept table there is nothing to restore: the
     * migration that created the table drops it.
     */
    public function down(): void
    {
        $table = config('translations.shared.table', 'translations');
        $legacy = "{$table}_v0_1";

        if (! Schema::hasTable($legacy)) {
            return;
        }

        Schema::dropIfExists($table);
        Schema::rename($legacy, $table);
    }
};
