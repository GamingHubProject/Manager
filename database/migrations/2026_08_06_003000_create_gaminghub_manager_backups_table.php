<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaminghub_manager_backups', function (Blueprint $table) {
            $table->id();
            $table->string('backup_uuid', 36)->unique();
            $table->string('extension_id', 100)->index();
            $table->string('version', 50);
            $table->string('relative_path', 500)->unique();
            $table->string('integrity_hash', 64);
            $table->boolean('enabled_snapshot')->default(false);
            $table->json('manifest_snapshot');
            $table->string('reason', 32)->default('manual')->index();
            $table->string('source_operation_uuid', 36)->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('restored_at')->nullable();
            $table->unsignedBigInteger('restored_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaminghub_manager_backups');
    }
};
