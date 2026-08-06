<?php

declare(strict_types=1);

namespace Illuminate\Support {
    final class ViewErrorBag
    {
        /** @param list<string> $messages */
        public function __construct(private array $messages = [])
        {
        }

        /** @return list<string> */
        public function all(): array
        {
            return $this->messages;
        }
    }

    final class Collection
    {
        /** @param array<array-key, mixed> $items */
        public function __construct(private array $items = [])
        {
        }

        /** @return array<array-key, mixed> */
        public function all(): array
        {
            return $this->items;
        }
    }
}

namespace {
    if (! function_exists('mb_substr')) {
        function mb_substr(string $value, int $start, ?int $length = null): string
        {
            return $length === null ? substr($value, $start) : substr($value, $start, $length);
        }
    }

    require_once __DIR__.'/../src/Support/ManagerAlertNormalizer.php';

    use Azuriom\Plugin\GamingHubManager\Support\ManagerAlertNormalizer;
    use Illuminate\Support\Collection;
    use Illuminate\Support\ViewErrorBag;

    $failures = [];
    $expect = static function (bool $condition, string $message) use (&$failures): void {
        if (! $condition) {
            $failures[] = $message;
        }
    };

    $normalizer = new ManagerAlertNormalizer();

    $validation = $normalizer->validation(new ViewErrorBag(['Name is required.', 'URL is invalid.']));
    $expect($validation === ['Name is required.', 'URL is invalid.'], 'ViewErrorBag messages were not normalized.');
    $expect($normalizer->validation(new ViewErrorBag()) === [], 'Empty ViewErrorBag should render no validation alerts.');
    $expect($normalizer->validation(['not', 'a', 'bag']) === [], 'Domain arrays must never be treated as a ViewErrorBag.');

    $arrayAlerts = $normalizer->custom(['Registry failed.', 'Package metadata is stale.']);
    $expect(count($arrayAlerts) === 2, 'Array-of-string Manager alerts were not normalized.');
    $expect(($arrayAlerts[0]['level'] ?? null) === 'danger', 'Default Manager alert level should be danger.');

    $collectionAlerts = $normalizer->custom(new Collection(['First warning.', 'Second warning.']), 'warning');
    $expect(count($collectionAlerts) === 2, 'Collection Manager alerts were not normalized.');
    $expect(($collectionAlerts[0]['level'] ?? null) === 'warning', 'Collection default level was not preserved.');

    $structured = $normalizer->custom([
        ['level' => 'error', 'message' => 'Checksum failed.', 'label' => '<b>Core</b>'],
    ]);
    $expect(($structured[0]['level'] ?? null) === 'danger', 'Structured error level should map to Bootstrap danger.');
    $expect(($structured[0]['label'] ?? null) === 'Core', 'Structured labels must be context-safe plain text.');

    $sensitive = $normalizer->custom([
        'level' => 'warning',
        'message' => 'Download failed?token=secret-value Bearer abc.def.ghi Stack trace: /private/path',
    ]);
    $sensitiveMessage = $sensitive[0]['message'] ?? '';
    $expect(! str_contains($sensitiveMessage, 'secret-value'), 'Query-string secret was not redacted.');
    $expect(! str_contains($sensitiveMessage, 'abc.def.ghi'), 'Bearer token was not redacted.');
    $expect(! str_contains($sensitiveMessage, 'Stack trace:'), 'Stack trace was not removed.');

    $expect($normalizer->custom(null) === [], 'Null custom alerts should render nothing.');
    $expect($normalizer->custom(new \stdClass()) === [], 'Unsupported objects should render nothing.');
    $expect($normalizer->flash(null, 'success') === null, 'Empty flash value should render nothing.');

    if ($failures !== []) {
        fwrite(STDERR, "FAILED\n- ".implode("\n- ", $failures)."\n");
        exit(1);
    }

    echo "PASS: Manager alert normalization and reserved validation bag handling\n";
}
