<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class SafeExtensionHttpClient
{
    private const GITHUB_DOWNLOAD_HOSTS = [
        'github.com',
        'api.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
        'raw.githubusercontent.com',
        'githubusercontent.com',
    ];

    public function __construct(private ExtensionUrlGuard $guard)
    {
    }

    public function json(string $url, bool $allowPrivate = false): array
    {
        $allowPrivate = $this->privateHostsAllowed($allowPrivate);
        $this->guard->assertSafe($url, $allowPrivate);

        $response = Http::acceptJson()
            ->timeout((int) config('gaming-hub-manager.manager.http_timeout', 10))
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if ($response->redirect()) {
            throw new ExtensionOperationFailed('Redirects must be resolved and validated explicitly.');
        }

        if (! $response->successful()) {
            throw new ExtensionOperationFailed('Remote source returned HTTP '.$response->status().'.');
        }

        if (strlen($response->body()) > 2_000_000) {
            throw new ExtensionOperationFailed('Registry response is too large.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ExtensionOperationFailed('Remote response is not valid JSON.');
        }

        return $json;
    }


    public function text(string $url, bool $allowPrivate = false, int $maxBytes = 2_000_000): string
    {
        $directory = storage_path('app/gaming-hub-manager/staging/http');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ExtensionOperationFailed('Unable to create HTTP staging.');
        }
        $path = $directory.'/'.bin2hex(random_bytes(12)).'.txt';

        try {
            $this->download($url, $path, $allowPrivate);
            $size = filesize($path);
            if ($size === false || $size > $maxBytes) {
                throw new ExtensionOperationFailed('Remote text asset is too large.');
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new ExtensionOperationFailed('Unable to read the downloaded text asset.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    public function download(string $url, string $path, bool $allowPrivate = false): void
    {
        $allowPrivate = $this->privateHostsAllowed($allowPrivate);
        $maxBytes = (int) config('gaming-hub-manager.manager.max_download_bytes', 52_428_800);
        $redirectLimit = (int) config('gaming-hub-manager.manager.github_redirect_limit', 5);
        $currentUrl = $url;

        $this->assertGithubDownloadHost($currentUrl);

        for ($redirects = 0; $redirects <= $redirectLimit; $redirects++) {
            $this->guard->assertSafe($currentUrl, $allowPrivate);
            $this->assertGithubDownloadHost($currentUrl);
            @unlink($path);

            $response = Http::timeout((int) config('gaming-hub-manager.manager.download_timeout', 30))
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $path,
                ])
                ->get($currentUrl);

            if ($response->redirect()) {
                if ($redirects === $redirectLimit) {
                    @unlink($path);
                    throw new ExtensionOperationFailed('GitHub download exceeded the redirect limit.');
                }

                $location = $response->header('Location');
                if (! is_string($location) || trim($location) === '') {
                    @unlink($path);
                    throw new ExtensionOperationFailed('GitHub download redirect did not include a target.');
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                $this->assertGithubDownloadHost($currentUrl);
                continue;
            }

            if (! $response->successful()) {
                @unlink($path);
                throw new ExtensionOperationFailed('Download failed with HTTP '.$response->status().'.');
            }

            if (! is_file($path)) {
                throw new ExtensionOperationFailed('Download completed without creating an archive.');
            }

            $size = filesize($path);
            if ($size === false || $size <= 0) {
                @unlink($path);
                throw new ExtensionOperationFailed('Downloaded archive is empty.');
            }

            if ($size > $maxBytes) {
                @unlink($path);
                throw new ExtensionOperationFailed('Downloaded archive exceeds size limit.');
            }

            return;
        }

        @unlink($path);
        throw new ExtensionOperationFailed('GitHub download redirect validation failed.');
    }


    private function privateHostsAllowed(bool $sourceAllowsPrivate): bool
    {
        return $sourceAllowsPrivate
            && (bool) config('gaming-hub-manager.manager.allow_private_hosts', false);
    }

    private function assertGithubDownloadHost(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            throw new ExtensionOperationFailed('GitHub downloads must remain on HTTPS.');
        }

        if (! in_array($host, self::GITHUB_DOWNLOAD_HOSTS, true)) {
            throw new ExtensionOperationFailed('GitHub redirected to an untrusted host: '.($host ?: 'unknown').'.');
        }
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        $parts = parse_url($location);

        if (is_array($parts) && isset($parts['scheme'])) {
            if (strtolower((string) $parts['scheme']) !== 'https') {
                throw new ExtensionOperationFailed('GitHub download redirect attempted a protocol downgrade.');
            }

            return $location;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base) || empty($base['host'])) {
            throw new ExtensionOperationFailed('Unable to validate the GitHub redirect target.');
        }

        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        if (str_starts_with($location, '/')) {
            return 'https://'.$base['host'].$location;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return 'https://'.$base['host'].($directory === '' ? '' : $directory).'/'.$location;
    }
}
