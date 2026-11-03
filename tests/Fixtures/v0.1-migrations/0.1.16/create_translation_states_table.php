<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tipi\Translations\Config\TranslationConfig;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            table: resolve(TranslationConfig::class)->translationStatesTable,
            callback: function (Blueprint $table): void {
                $table->id();

                $table->string('translatable_type');
                $table->unsignedBigInteger('translatable_id');

                $table->string('locale_code');
                $table->string('status')->nullable();
                $table->timestamp('outdated_at')->nullable();
                $table->timestamps();

                $table->unique(
                    [
                        'translatable_type',
                        'translatable_id',
                        'locale_code',
                    ],
                    'translation_states_owner_locale_unique',
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            table: resolve(TranslationConfig::class)->translationStatesTable,
        );
    }
};
