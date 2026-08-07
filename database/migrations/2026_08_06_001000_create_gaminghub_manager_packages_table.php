<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaminghub_manager_packages', function (Blueprint $table) {
            $table->id();
            $table->string('extension_id', 100)->unique();
            $table->string('installed_version', 50);
            $table->string('source_type', 32)->nullable();
            $table->string('source_id', 100)->nullable()->index();
            $table->text('source_url')->nullable();
            $table->text('repository_url')->nullable();
            $table->text('release_url')->nullable();
            $table->string('release_id', 100)->nullable();
            $table->string('asset_name', 255)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->boolean('checksum_verified')->default(false);
            $table->string('integrity_hash', 64)->nullable();
            $table->string('integrity_status', 24)->default('unknown')->index();
            $table->timestamp('integrity_checked_at')->nullable();
            $table->string('trust_level', 24)->default('untrusted');
            $table->unsignedBigInteger('installed_by')->nullable()->index();
            $table->timestamp('installed_at')->nullable();
            $table->boolean('enabled_snapshot')->default(false);
            $table->json('manifest_snapshot');
            $table->string('last_operation_result', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaminghub_manager_packages');
    }
};
