<?php

namespace Azuriom\Plugin\GamingHubManager\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSafeMessage;
use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
use Azuriom\Plugin\GamingHubManager\Services\PackageReleaseResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ReleaseController extends Controller
{
    public function __construct(
        private ManagerRuntime $runtime,
        private PackageReleaseResolver $releases,
        private ExtensionSafeMessage $messages,
    ) {
    }

    public function show(string $source, string $packageId): View|RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if (! $this->runtime->isReady($runtimeStatus)) {
            return view('gaming-hub-manager::admin.migration-required', compact('runtimeStatus'));
        }
        $sourceModel = ExtensionSource::query()->findOrFail($source);
        abort_unless($sourceModel->enabled, 404);

        try {
            $resolved = $this->releases->resolve($sourceModel, $packageId);

            return view('gaming-hub-manager::admin.release', [
                'source' => $sourceModel,
                'packageId' => $packageId,
                ...$resolved,
            ]);
        } catch (\Throwable $exception) {
            return redirect()->route('gaming-hub-manager.admin.available')
                ->with('error', $this->messages->fromThrowable($exception));
        }
    }
}
