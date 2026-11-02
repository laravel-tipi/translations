<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tipi\Translations\Support\MorphKeyType;

/*
 | Shared table storage (HasSharedTableTranslations): one row per model,
 | locale and attribute, the value as JSON.
 |
 | The translatable_id column follows [translations.morph_key_type]. Run
 | this migration on every connection whose models use the storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = config('translations.shared.table', 'translations');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->id();

            MorphKeyType::configured()->addColumns($table, 'translatable');
            $table->string('locale_code', 16);
            $table->string('attribute', 64);
            $table->json('value');

            $table->timestamps();

            // Finds a model's rows, and makes each value unique, so
            // concurrent writers update one row instead of adding two.
            $table->unique(['translatable_type', 'translatable_id', 'locale_code', 'attribute'], "{$name}_owner_unique");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('translations.shared.table', 'translations'));
    }
};
