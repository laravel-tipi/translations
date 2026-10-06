<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();

            $table->string('translatable_type');
            $table->unsignedBigInteger('translatable_id');

            $table->string('locale_code');
            $table->json('values');

            $table->timestamp('outdated_at')->nullable();
            $table->timestamps();

            $table->unique([
                'translatable_type',
                'translatable_id',
                'locale_code',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
