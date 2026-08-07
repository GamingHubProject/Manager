<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Data\ExtensionManifest;
use Azuriom\Plugin\GamingHubManager\Exceptions\UnsafeExtensionArchive;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class ExtensionArchiveInspector
{
    public function __construct(private ExtensionManifestValidator $manifests)
    {
    }

    public function inspect(string $archive, string $extractTo, ?array $registryMetadata = null): ExtensionManifest
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new UnsafeExtensionArchive('Invalid ZIP archive.');
        }

        try {
            $maxFiles = (int) config('gaming-hub-manager.manager.max_files', 10000);
            if ($zip->numFiles < 1 || $zip->numFiles > $maxFiles) {
                throw new UnsafeExtensionArchive('Archive file-count limit exceeded.');
            }

            $roots = [];
            $seenPaths = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $normalizedName = rtrim($name, '/');
                $parts = explode('/', $normalizedName);
                if ($normalizedName === ''
                    || str_contains($normalizedName, "\0")
                    || str_starts_with($normalizedName, '/')
                    || preg_match('/^[A-Za-z]:\//', $normalizedName)
                    || array_filter($parts, static fn (string $part): bool => $part === '' || $part === '.' || $part === '..') !== []) {
                    throw new UnsafeExtensionArchive('Unsafe archive path.');
                }
                if (isset($seenPaths[$normalizedName])) {
                    throw new UnsafeExtensionArchive('Duplicate archive path.');
                }
                $seenPaths[$normalizedName] = true;

                $roots[$parts[0]] = true;
                $total += (int) ($stat['size'] ?? 0);

                $operatingSystem = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                    $mode = ($attributes >> 16) & 0170000;
                    if ($mode === 0120000) {
                        throw new UnsafeExtensionArchive('Symlinks are not allowed.');
                    }
                    if ($mode !== 0 && ! in_array($mode, [0040000, 0100000], true)) {
                        throw new UnsafeExtensionArchive('Special filesystem entries are not allowed.');
                    }
                }
                if (preg_match('/\.(zip|tar|gz|tgz|bz2|xz|phar)$/i', $normalizedName) && count($parts) > 1) {
                    throw new UnsafeExtensionArchive('Nested archives are not allowed.');
                }
            }

            if (count($roots) !== 1) {
                throw new UnsafeExtensionArchive('Package archive must contain exactly one root directory.');
            }
            if ($total > (int) config('gaming-hub-manager.manager.max_extracted_bytes', 314572800)) {
                throw new UnsafeExtensionArchive('Extracted size limit exceeded.');
            }

            $root = (string) array_key_first($roots);
            $pluginPath = $root.'/plugin.json';
            if ($zip->locateName($pluginPath) === false) {
                throw new UnsafeExtensionArchive('Missing plugin.json.');
            }

            $plugin = json_decode((string) $zip->getFromName($pluginPath), true);
            $manifest = null;
            $manifestPath = $root.'/gaming-hub-extension.json';
            if ($zip->locateName($manifestPath) !== false) {
                $manifest = json_decode((string) $zip->getFromName($manifestPath), true);
            }
            if (! is_array($plugin) || ($manifest !== null && ! is_array($manifest))) {
                throw new UnsafeExtensionArchive('Invalid package metadata JSON.');
            }

            $normalized = $this->manifests->validate($manifest, $plugin, $registryMetadata);
            if ($root !== $normalized->pluginDirectory) {
                throw new UnsafeExtensionArchive('Archive root does not match the plugin identifier.');
            }

            if (is_dir($extractTo)) {
                $this->delete($extractTo);
            }
            if (! mkdir($extractTo, 0755, true) && ! is_dir($extractTo)) {
                throw new UnsafeExtensionArchive('Unable to create extraction staging.');
            }
            if (! $zip->extractTo($extractTo)) {
                throw new UnsafeExtensionArchive('Unable to extract archive.');
            }
            $this->assertExtractedTreeSafe($extractTo);

            return $normalized;
        } finally {
            $zip->close();
        }
    }


    private function assertExtractedTreeSafe(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (! $entry->isDir() && ! $entry->isFile())) {
                throw new UnsafeExtensionArchive('Extracted package contains an unsafe filesystem entry.');
            }
        }
    }

    private function delete(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
