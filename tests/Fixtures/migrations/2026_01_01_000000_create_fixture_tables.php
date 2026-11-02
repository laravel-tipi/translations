<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('json_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->nullable();
            $table->json('title')->nullable();
            $table->json('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('table_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('table_article_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('table_article_id');
            $table->string('locale_code', 12);
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->unique(['table_article_id', 'locale_code']);
        });

        Schema::create('cast_articles', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('cast_article_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cast_article_id')->constrained()->cascadeOnDelete();
            $table->string('locale_code', 12);
            $table->json('content')->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->timestamps();

            $table->unique(['cast_article_id', 'locale_code']);
        });
    }
};
