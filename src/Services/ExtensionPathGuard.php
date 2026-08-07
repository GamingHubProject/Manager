<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ExtensionPathGuard
{
    public function validateId(string $extensionId): string
    {
        if ($extensionId === ''
            || str_contains($extensionId, '/')
            || str_contains($extensionId, '\\')
            || str_contains($extensionId, '..')
            || ! preg_match('/^[a-z0-9][a-z0-9-]{1,98}[a-z0-9]$/', $extensionId)) {
            throw new ExtensionOperationFailed('Unsafe extension identifier rejected.');
        }

        return $extensionId;
    }

    public function pluginsRoot(bool $writable = false): string
    {
        $configured = base_path('plugins');
        $real = realpath($configured);

        if ($real === false || ! is_dir($real)) {
            throw new ExtensionOperationFailed('The Azuriom plugins directory is unavailable.');
        }

        if ($writable && ! is_writable($real)) {
            throw new ExtensionOperationFailed('The Azuriom plugins directory is not writable.');
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function destination(string $extensionId, bool $mustExist = false): string
    {
        $extensionId = $this->validateId($extensionId);
        $root = $this->pluginsRoot();
        $expected = $root.DIRECTORY_SEPARATOR.$extensionId;

        if (is_link($expected)) {
            throw new ExtensionOperationFailed('Symlinked extension destinations are not allowed.');
        }

        if (file_exists($expected)) {
            $real = realpath($expected);
            if ($real === false || $real !== $expected || ! is_dir($real)) {
                throw new ExtensionOperationFailed('Extension destination escaped the plugins directory.');
            }
        } elseif ($mustExist) {
            throw new ExtensionOperationFailed('The installed extension directory is missing.');
        }

        if (dirname($expected) !== $root) {
            throw new ExtensionOperationFailed('Extension destination is outside the plugins directory.');
        }

        return $expected;
    }

    public function assertStagedDirectory(string $path, string $root, string $extensionId): void
    {
        $extensionId = $this->validateId($extensionId);
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false || ! is_dir($realPath)) {
            throw new ExtensionOperationFailed('The staged extension package is incomplete.');
        }

        $expected = $realRoot.DIRECTORY_SEPARATOR.$extensionId;
        if ($realPath !== $expected || is_link($path)) {
            throw new ExtensionOperationFailed('The staged extension path is unsafe.');
        }

        if (! is_file($realPath.'/plugin.json')) {
            throw new ExtensionOperationFailed('The staged package is missing plugin.json.');
        }
    }

    public function deleteExtension(string $extensionId): void
    {
        $path = $this->destination($extensionId);
        if (! file_exists($path)) {
            return;
        }

        $this->deleteDirectory($path);
    }


    public function deletePublicAssets(string $extensionId): void
    {
        $extensionId = $this->validateId($extensionId);
        $configuredRoot = public_path('assets/plugins');
        if (! is_dir($configuredRoot)) {
            return;
        }

        $root = realpath($configuredRoot);
        if ($root === false) {
            throw new ExtensionOperationFailed('The public plugin assets directory is unavailable.');
        }

        $expected = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$extensionId;
        if (dirname($expected) !== rtrim($root, DIRECTORY_SEPARATOR)) {
            throw new ExtensionOperationFailed('Public extension assets path is unsafe.');
        }

        if (is_link($expected)) {
            if (! @unlink($expected)) {
                throw new ExtensionOperationFailed('Unable to remove the public extension asset link.');
            }

            return;
        }

        if (file_exists($expected)) {
            $real = realpath($expected);
            if ($real === false || $real !== $expected || ! is_dir($real)) {
                throw new ExtensionOperationFailed('Public extension assets escaped their expected directory.');
            }
            $this->deleteDirectory($expected);
        }
    }

    public function deleteDirectory(string $path): void
    {
        if (is_link($path)) {
            if (! @unlink($path)) {
                throw new ExtensionOperationFailed('Unable to remove an unsafe symbolic link.');
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            if ($file->isLink() || $file->isFile()) {
                if (! @unlink($pathname)) {
                    throw new ExtensionOperationFailed('Unable to remove an extension file.');
                }
            } elseif (! @rmdir($pathname)) {
                throw new ExtensionOperationFailed('Unable to remove an extension directory.');
            }
        }

        if (! @rmdir($path)) {
            throw new ExtensionOperationFailed('Unable to remove the extension directory.');
        }
    }

    public function copyDirectory(string $source, string $destination): void
    {
        if (! is_dir($source) || is_link($source)) {
            throw new ExtensionOperationFailed('Extension source directory is unsafe.');
        }

        if (file_exists($destination)) {
            throw new ExtensionOperationFailed('Extension staging destination already exists.');
        }

        if (! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new ExtensionOperationFailed('Unable to create an extension staging directory.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new ExtensionOperationFailed('Symbolic links inside extension directories are not allowed.');
            }

            $relative = substr($file->getPathname(), strlen($source) + 1);
            $target = $destination.DIRECTORY_SEPARATOR.$relative;

            if ($file->isDir()) {
                if (! is_dir($target) && ! mkdir($target, 0755, true) && ! is_dir($target)) {
                    throw new ExtensionOperationFailed('Unable to copy an extension directory.');
                }
            } elseif (! copy($file->getPathname(), $target)) {
                throw new ExtensionOperationFailed('Unable to copy an extension file.');
            }
        }
    }
}
