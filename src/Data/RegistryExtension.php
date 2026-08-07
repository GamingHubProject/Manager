<?php

namespace Azuriom\Plugin\GamingHubManager\Data;

final readonly class RegistryExtension
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $author,
        public string $category,
        public string $repository,
        public string $releaseAsset,
        public ?string $checksumAsset,
        public ?string $latestVersion,
        public bool $verified,
        public bool $official,
        public ?string $icon = null,
        public ?string $releaseNotesUrl = null,
        public array $raw = [],
    ) {
    }
}
