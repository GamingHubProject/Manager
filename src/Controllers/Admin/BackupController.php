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

    public function restore(Request $request, string $backup): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $backupModel = PackageBackup::query()->findOrFail($backup);
        $request->validate([
            'confirmation' => ['required', 'string', 'in:'.$backupModel->extension_id],
        ], ['confirmation.in' => 'Type the exact package ID to confirm rollback.']);

        $operation = ExtensionOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'operation' => 'restore',
            'extension_id' => $backupModel->extension_id,
            'version' => $backupModel->version,
            'actor_id' => $request->user()->getKey(),
            'started_at' => now(),
            'result' => 'running',
            'current_stage' => 'queued',
            'events' => [[
                'at' => now()->toIso8601String(),
                'stage' => 'queued',
                'level' => 'info',
                'message' => 'Backup restore queued.',
            ]],
        ]);

        try {
            $package = $this->backups->restore($backupModel, (int) $request->user()->getKey(), $operation);

            return redirect()->route('gaming-hub-manager.admin.packages.show', $package)
                ->with('warning', 'Package files restored to '.$backupModel->version.'. Database migrations were not reversed.');
        } catch (\Throwable $exception) {
            if ($operation->result === 'running' && $operation->finished_at === null) {
                $operation->fail($this->messages->fromThrowable($exception), 'restore_failed');
            }

            return redirect()->route('gaming-hub-manager.admin.logs')
                ->with('error', 'Restore failed: '.$this->messages->fromThrowable($exception));
        }
    }

    public function destroy(Request $request, string $backup): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $backupModel = PackageBackup::query()->findOrFail($backup);
        $request->validate(['confirmation' => ['required', 'string', 'in:'.$backupModel->backup_uuid]]);
        try {
            $this->backups->delete($backupModel);

            return back()->with('success', 'Backup deleted.');
        } catch (\Throwable $exception) {
            return back()->with('error', $this->messages->fromThrowable($exception));
        }
    }

    private function notReady(): ?RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if ($this->runtime->isReady($runtimeStatus)) {
            return null;
        }

        return redirect()->route('gaming-hub-manager.admin.overview')
            ->with('warning', $this->runtime->recoveryMessage($runtimeStatus));
    }
}
