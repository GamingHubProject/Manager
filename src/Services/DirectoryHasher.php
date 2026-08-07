<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DirectoryHasher
{
    public function hash(string $path): string
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real) || is_link($path)) {
            throw new ExtensionOperationFailed('Package directory is unavailable for integrity verification.');
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new ExtensionOperationFailed('Package integrity verification rejected a symbolic link.');
            }
            if ($file->isFile()) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($real) + 1));
                $files[$relative] = $file->getPathname();
            }
        }
        ksort($files, SORT_STRING);

        $context = hash_init('sha256');
        foreach ($files as $relative => $file) {
            $size = filesize($file);
            $contentHash = hash_file('sha256', $file);
            if ($size === false || $contentHash === false) {
                throw new ExtensionOperationFailed('Unable to read a package file during integrity verification.');
            }
            hash_update($context, $relative."\0".$size."\0".$contentHash."\n");
        }

        return hash_final($context);
    }
}
