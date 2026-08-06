<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaminghub_manager_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 100)->unique();
            $table->string('type', 32);
            $table->string('name', 150);
            $table->text('url');
            $table->string('trust_level', 24)->default('untrusted');
            $table->boolean('trusted')->default(false);
            $table->boolean('enabled')->default(false)->index();
            $table->boolean('allow_prereleases')->default(false);
            $table->boolean('allow_private_host')->default(false);
            $table->unsignedBigInteger('added_by')->nullable()->index();
            $table->timestamp('last_successful_refresh_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaminghub_manager_sources');
    }
};
