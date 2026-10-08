<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesTestSchema
{
    protected function createTestSchema(): void
    {
        Schema::create('dedicated_articles', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('dedicated_article_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dedicated_article_id');
            $table->string('locale_code');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique([
                'dedicated_article_id',
                'locale_code',
            ]);
        });

        Schema::create('shared_articles', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('json_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->nullable();
            $table->softDeletes();
            $table->json('title')->nullable();
            $table->json('description')->nullable();
            $table->timestamps();
        });

        Schema::create('soft_deleting_dedicated_articles', function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('soft_deleting_dedicated_article_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('soft_deleting_dedicated_article_id');
            $table->string('locale_code');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique([
                'soft_deleting_dedicated_article_id',
                'locale_code',
            ]);
        });

        Schema::create('soft_deleting_shared_articles', function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
