<?php

namespace Azuriom\Plugin\GamingHubManager\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionOperation;
use Azuriom\Plugin\GamingHubManager\Models\PackageBackup;
use Azuriom\Plugin\GamingHubManager\Services\BackupManager;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSafeMessage;
use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class BackupController extends Controller
{
    public function __construct(
        private ManagerRuntime $runtime,
        private BackupManager $backups,
        private ExtensionSafeMessage $messages,
    ) {
    }

    public function restore(Request $request, PackageBackup $backup): RedirectResponse
    {
        $this->runtime->prepare();
        $request->validate([
            'confirmation' => ['required', 'string', 'in:'.$backup->extension_id],
        ], ['confirmation.in' => 'Type the exact package ID to confirm rollback.']);

        $operation = ExtensionOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'operation' => 'rollback',
            'extension_id' => $backup->extension_id,
            'version' => $backup->version,
            'actor_id' => $request->user()->getKey(),
            'started_at' => now(),
            'result' => 'running',
            'current_stage' => 'queued',
            'events' => [[
                'at' => now()->toIso8601String(),
                'stage' => 'queued',
                'level' => 'info',
                'message' => 'Backup rollback queued.',
            ]],
        ]);

        try {
            $package = $this->backups->restore($backup, (int) $request->user()->getKey(), $operation);

            return redirect()->route('gaming-hub-manager.admin.packages.show', $package)
                ->with('warning', 'Package files restored to '.$backup->version.'. Database migrations were not reversed.');
        } catch (\Throwable $exception) {
            if ($operation->result === 'running' && $operation->finished_at === null) {
                $operation->fail($this->messages->fromThrowable($exception), 'rollback_failed');
            }

            return redirect()->route('gaming-hub-manager.admin.logs')
                ->with('error', 'Rollback failed: '.$this->messages->fromThrowable($exception));
        }
    }

    public function destroy(Request $request, PackageBackup $backup): RedirectResponse
    {
        $this->runtime->prepare();
        $request->validate(['confirmation' => ['required', 'string', 'in:'.$backup->backup_uuid]]);
        try {
            $this->backups->delete($backup);

            return back()->with('success', 'Backup deleted.');
        } catch (\Throwable $exception) {
            return back()->with('error', $this->messages->fromThrowable($exception));
        }
    }
}
