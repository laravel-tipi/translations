<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('translations.states.table', 'translation_states'), function (Blueprint $table): void {
            $table->id();

            // Change translatable_id to uuid() or ulid() if your
            // translatable models use UUID or ULID keys.
            $table->string('translatable_type');
            $table->unsignedBigInteger('translatable_id');
            $table->string('locale_code', 12);

            $table->string('status')->nullable();
            $table->timestamp('outdated_at')->nullable();
            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'locale_code'], 'translation_states_owner_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('translations.states.table', 'translation_states'));
    }
};
