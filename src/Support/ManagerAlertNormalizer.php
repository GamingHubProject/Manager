<?php

namespace Azuriom\Plugin\GamingHubManager\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\ViewErrorBag;
use Stringable;

final class ManagerAlertNormalizer
{
    private const LEVELS = [
        'success' => 'success',
        'info' => 'info',
        'warning' => 'warning',
        'error' => 'danger',
        'danger' => 'danger',
    ];

    /**
     * @return list<string>
     */
    public function validation(mixed $value): array
    {
        if (! $value instanceof ViewErrorBag) {
            return [];
        }

        $messages = [];
        foreach ($value->all() as $message) {
            $safe = $this->safeText($message);
            if ($safe !== null) {
                $messages[] = $safe;
            }
        }

        return $messages;
    }

    /**
     * @return list<array{level: string, message: string, label: string|null}>
     */
    public function custom(mixed $value, string $defaultLevel = 'danger'): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value) || $value instanceof Stringable) {
            $alert = $this->record($value, $defaultLevel, null);

            return $alert === null ? [] : [$alert];
        }

        if (! is_array($value)) {
            return [];
        }

        if (array_key_exists('message', $value)) {
            $alert = $this->record(
                $value['message'],
                is_string($value['level'] ?? null) ? $value['level'] : $defaultLevel,
                $value['label'] ?? null,
            );

            return $alert === null ? [] : [$alert];
        }

        $alerts = [];
        foreach ($value as $key => $item) {
            if (is_array($item) && array_key_exists('message', $item)) {
                $alert = $this->record(
                    $item['message'],
                    is_string($item['level'] ?? null) ? $item['level'] : $defaultLevel,
                    $item['label'] ?? (is_string($key) ? $key : null),
                );
            } else {
                $alert = $this->record($item, $defaultLevel, is_string($key) ? $key : null);
            }

            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * @return array{level: string, message: string, label: string|null}|null
     */
    public function flash(mixed $value, string $level): ?array
    {
        return $this->record($value, $level, null);
    }

    /**
     * @return array{level: string, message: string, label: string|null}|null
     */
    private function record(mixed $message, string $level, mixed $label): ?array
    {
        $safeMessage = $this->safeText($message);
        if ($safeMessage === null) {
            return null;
        }

        return [
            'level' => self::LEVELS[strtolower($level)] ?? self::LEVELS['danger'],
            'message' => $safeMessage,
            'label' => $this->safeText($label, 120),
        ];
    }

    private function safeText(mixed $value, int $limit = 500): ?string
    {
        if (! is_scalar($value) && ! $value instanceof Stringable) {
            return null;
        }

        $text = trim(strip_tags((string) $value));
        if ($text === '') {
            return null;
        }

        $tracePosition = stripos($text, 'Stack trace:');
        if ($tracePosition !== false) {
            $text = substr($text, 0, $tracePosition);
        }

        $text = preg_replace('/([?&](?:access_?token|api_?key|key|secret|signature|token)=)[^&\s]+/i', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
