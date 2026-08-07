<?php

namespace Azuriom\Plugin\GamingHubManager\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Illuminate\Database\Eloquent\Model;

final class ExtensionOperation extends Model
{
    use HasTablePrefix;

    protected $prefix = 'gaminghub_manager_';
    protected $table = 'gaminghub_manager_operations';
    protected $fillable = [
        'operation_uuid', 'operation', 'extension_id', 'version', 'source_id', 'actor_id',
        'started_at', 'finished_at', 'result', 'current_stage', 'error_category', 'summary',
        'rollback_attempted', 'rollback_succeeded', 'context', 'events',
    ];
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'rollback_attempted' => 'boolean',
        'rollback_succeeded' => 'boolean',
        'context' => 'array',
        'events' => 'array',
    ];

    public function transition(string $stage, string $message): void
    {
        $this->forceFill(['current_stage' => $stage, 'result' => 'running']);
        $this->appendEvent($stage, $message);
        $this->save();
    }

    public function appendEvent(string $stage, string $message, string $level = 'info'): void
    {
        $events = $this->events ?? [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => $stage,
            'level' => $level,
            'message' => mb_substr(strip_tags($message), 0, 1000),
        ];
        $this->events = $events;
    }

    public function mergeContext(array $values): void
    {
        $this->context = array_merge($this->context ?? [], $values);
        $this->save();
    }

    public function complete(string $summary): void
    {
        $this->forceFill([
            'current_stage' => 'completed',
            'result' => 'completed',
            'finished_at' => now(),
            'summary' => mb_substr(strip_tags($summary), 0, 1000),
            'error_category' => null,
        ]);
        $this->appendEvent('completed', 'Finished.');
        $this->save();
    }

    public function fail(string $reason, string $category = 'package_operation_failed', string $terminalStage = 'failed'): void
    {
        if ($this->finished_at !== null && in_array($this->result, ['completed', 'failed'], true)) {
            return;
        }

        $failedStage = $this->current_stage ?: 'unknown';
        $safeReason = mb_substr(strip_tags($reason), 0, 1000);
        $context = $this->context ?? [];
        $context['failed_stage'] ??= $failedStage;
        $this->context = $context;
        $this->forceFill([
            'current_stage' => $terminalStage,
            'result' => 'failed',
            'finished_at' => now(),
            'error_category' => $category,
            'summary' => $safeReason,
        ]);
        $this->appendEvent('failed', $safeReason, 'error');
        if ($terminalStage !== 'failed') {
            $this->appendEvent(
                $terminalStage,
                $terminalStage === 'rolled_back' ? 'Previous package state restored.' : 'Automatic rollback failed.',
                $terminalStage === 'rolled_back' ? 'info' : 'error',
            );
        }
        $this->save();
    }
}
