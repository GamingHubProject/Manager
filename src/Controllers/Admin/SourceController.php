<?php

namespace Azuriom\Plugin\GamingHubManager\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\GamingHubManager\Http\Requests\SaveExtensionSourceRequest;
use Azuriom\Plugin\GamingHubManager\Models\ExtensionSource;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSafeMessage;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSourceManager;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionUrlGuard;
use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
use Illuminate\Http\RedirectResponse;

final class SourceController extends Controller
{
    public function __construct(
        private ExtensionSourceManager $manager,
        private ExtensionUrlGuard $guard,
        private ManagerRuntime $runtime,
        private ExtensionSafeMessage $messages,
    ) {
    }

    public function store(SaveExtensionSourceRequest $request): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $data = $request->validated();

        try {
            $allowPrivate = (bool) ($data['allow_private_host'] ?? false);
            if ($allowPrivate && ! (bool) config('gaming-hub-manager.manager.allow_private_hosts', false)) {
                return back()->with('error', 'Private-host package sources are disabled in Manager settings.')->withInput();
            }
            if ($data['type'] === 'github') {
                $this->guard->assertGithubRepository($data['url'], $allowPrivate);
            } else {
                $this->guard->assertSafe($data['url'], $allowPrivate);
                if ($this->manager->isOfficialRegistryUrl($data['url'])) {
                    return back()->with('error', 'The GamingHubProject official registry is built in and already managed by Gaming Hub Manager.')->withInput();
                }
            }

            ExtensionSource::create([
                'source_id' => $this->manager->makeId($data['name']),
                'type' => $data['type'],
                'name' => $data['name'],
                'url' => $data['url'],
                'trust_level' => ($data['trusted'] ?? false) ? 'trusted' : 'untrusted',
                'trusted' => (bool) ($data['trusted'] ?? false),
                'enabled' => (bool) ($data['enabled'] ?? false),
                'allow_prereleases' => (bool) ($data['allow_prereleases'] ?? false),
                'allow_private_host' => $allowPrivate,
                'added_by' => $request->user()->getKey(),
                'metadata' => $data['type'] === 'github' ? [
                    'release_asset' => $data['release_asset'] ?? '*.zip',
                    'checksum_asset' => $data['checksum_asset'] ?? null,
                ] : null,
            ]);

            return back()->with('success', 'Package source added.');
        } catch (\Throwable $exception) {
            return back()->with('error', $this->messages->fromThrowable($exception))->withInput();
        }
    }

    public function refresh(string $source): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $sourceModel = ExtensionSource::query()->findOrFail($source);
        try {
            $this->manager->refresh($sourceModel, true);

            return back()->with('success', 'Package source and GitHub release metadata refreshed.');
        } catch (\Throwable $exception) {
            return back()->with('error', $this->messages->fromThrowable($exception));
        }
    }

    public function toggle(string $source): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $sourceModel = ExtensionSource::query()->findOrFail($source);
        if ($sourceModel->type === 'official') {
            return back()->with('error', 'The official registry cannot be disabled.');
        }
        $sourceModel->update(['enabled' => ! $sourceModel->enabled]);
        $this->manager->invalidate($sourceModel);

        return back()->with('success', 'Package source state changed.');
    }

    public function trust(string $source): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $sourceModel = ExtensionSource::query()->findOrFail($source);
        if ($sourceModel->type === 'official') {
            return back();
        }
        $trusted = ! $sourceModel->trusted;
        $sourceModel->update(['trusted' => $trusted, 'trust_level' => $trusted ? 'trusted' : 'untrusted']);

        return back()->with('success', 'Package source trust changed.');
    }

    public function destroy(string $source): RedirectResponse
    {
        if ($notReady = $this->notReady()) {
            return $notReady;
        }
        $sourceModel = ExtensionSource::query()->findOrFail($source);
        abort_if($sourceModel->type === 'official', 403);
        $this->manager->invalidate($sourceModel);
        $sourceModel->delete();

        return back()->with('success', 'Package source removed.');
    }

    private function notReady(): ?RedirectResponse
    {
        $runtimeStatus = $this->runtime->prepare();
        if ($this->runtime->isReady($runtimeStatus)) {
            return null;
        }

        return redirect()->route('gaming-hub-manager.admin.overview')
            ->with('warning', 'Run the pending Gaming Hub Manager migrations before managing package sources.');
    }
}
