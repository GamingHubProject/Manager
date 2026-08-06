<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Azuriom\Plugin\GamingHubManager\Exceptions\ChecksumMismatch;
use Azuriom\Plugin\GamingHubManager\Exceptions\ExtensionOperationFailed;

final class ExtensionChecksumVerifier
{
    public function verify(string $file, ?string $expected): string
    {
        $actual = hash_file('sha256', $file);
        if (! is_string($actual)) {
            throw new ExtensionOperationFailed('Unable to calculate the downloaded package SHA-256 checksum.');
        }

        if ($expected !== null) {
            $expected = $this->normalize($expected);
            if (! hash_equals($expected, strtolower($actual))) {
                throw new ChecksumMismatch('SHA-256 checksum mismatch.');
            }
        }

        return strtolower($actual);
    }

    public function parse(string $text, string $asset, bool $allowBareHash = false): ?string
    {
        $expectedAsset = basename($asset);
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', $line, $matches)
                && basename(trim($matches[2])) === $expectedAsset) {
                return strtolower($matches[1]);
            }

            if (preg_match('/^SHA256\s*\((.+)\)\s*=\s*([a-f0-9]{64})$/i', $line, $matches)
                && basename(trim($matches[1])) === $expectedAsset) {
                return strtolower($matches[2]);
            }

            if ($allowBareHash && preg_match('/^([a-f0-9]{64})$/i', $line, $matches)) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    public function normalize(string $checksum): string
    {
        $checksum = strtolower(trim($checksum));
        if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new ExtensionOperationFailed('Published SHA-256 checksum is malformed.');
        }

        return $checksum;
    }
}
