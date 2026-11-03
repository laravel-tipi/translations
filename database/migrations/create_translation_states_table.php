<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tipi\Translations\Support\MorphKeyType;

/*
 | Translation states (HasTranslationStates): one row per model and
 | locale, with its workflow data: outdated flag, status, drafts and source
 | fingerprints.
 |
 | The translatable_id column follows [translations.morph_key_type]. Run
 | this migration on every connection whose models use states.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = config('translations.states.table', 'translation_states');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->id();

            MorphKeyType::configured()->addColumns($table, 'translatable');
            $table->string('locale_code', 16);

            $table->string('status')->nullable();
            $table->timestamp('outdated_at')->nullable();

            // Unpublished drafts and the source each translation was made
            // from: attribute => value. Never read as translations.
            $table->json('drafts')->nullable();
            $table->json('sources')->nullable();
            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'locale_code'], "{$name}_owner_locale_unique");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('translations.states.table', 'translation_states'));
    }
};
