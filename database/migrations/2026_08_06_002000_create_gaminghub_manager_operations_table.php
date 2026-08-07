<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaminghub_manager_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->string('operation', 32)->index();
            $table->string('extension_id', 100)->nullable()->index();
            $table->string('version', 50)->nullable();
            $table->string('source_id', 100)->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('result', 32)->default('running')->index();
            $table->string('current_stage', 32)->default('queued')->index();
            $table->string('error_category', 64)->nullable();
            $table->text('summary')->nullable();
            $table->boolean('rollback_attempted')->default(false);
            $table->boolean('rollback_succeeded')->nullable();
            $table->json('context')->nullable();
            $table->json('events')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaminghub_manager_operations');
    }
};
