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

    public function show(ExtensionSource $source, string $packageId): View|RedirectResponse
    {
        $this->runtime->prepare();
        abort_unless($source->enabled, 404);

        try {
            $resolved = $this->releases->resolve($source, $packageId);

            return view('gaming-hub-manager::admin.release', [
                'source' => $source,
                'packageId' => $packageId,
                ...$resolved,
            ]);
        } catch (\Throwable $exception) {
            return redirect()->route('gaming-hub-manager.admin.available')
                ->with('error', $this->messages->fromThrowable($exception));
        }
    }
}
