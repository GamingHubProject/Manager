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
        $this->runtime->prepare();
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

    public function refresh(ExtensionSource $source): RedirectResponse
    {
        $this->runtime->prepare();
        try {
            $this->manager->refresh($source, true);

            return back()->with('success', 'Package source and GitHub release metadata refreshed.');
        } catch (\Throwable $exception) {
            return back()->with('error', $this->messages->fromThrowable($exception));
        }
    }

    public function toggle(ExtensionSource $source): RedirectResponse
    {
        $this->runtime->prepare();
        if ($source->type === 'official') {
            return back()->with('error', 'The official registry cannot be disabled.');
        }
        $source->update(['enabled' => ! $source->enabled]);
        $this->manager->invalidate($source);

        return back()->with('success', 'Package source state changed.');
    }

    public function trust(ExtensionSource $source): RedirectResponse
    {
        $this->runtime->prepare();
        if ($source->type === 'official') {
            return back();
        }
        $trusted = ! $source->trusted;
        $source->update(['trusted' => $trusted, 'trust_level' => $trusted ? 'trusted' : 'untrusted']);

        return back()->with('success', 'Package source trust changed.');
    }

    public function destroy(ExtensionSource $source): RedirectResponse
    {
        $this->runtime->prepare();
        abort_if($source->type === 'official', 403);
        $this->manager->invalidate($source);
        $source->delete();

        return back()->with('success', 'Package source removed.');
    }
}
