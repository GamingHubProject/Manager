<?php

namespace Azuriom\Plugin\GamingHubManager\Services;

use Throwable;

final class ExtensionSafeMessage
{
    public function fromThrowable(Throwable $exception): string
    {
        return $this->sanitize($exception->getMessage());
    }

    public function sanitize(string $message): string
    {
        $message = trim(strip_tags($message));
        $message = str_replace(
            array_filter([base_path(), storage_path(), public_path()]),
            ['[application]', '[storage]', '[public]'],
            $message,
        );
        $message = preg_replace('/([?&](?:access_?token|api_?key|key|secret|signature|token)=)[^&\s]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('#https://[^\s?]+\?[^\s]+#i', 'remote HTTPS URL', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return $message !== ''
            ? mb_substr($message, 0, 500)
            : 'The extension operation could not be completed.';
    }
}
