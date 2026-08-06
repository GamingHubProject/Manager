<?php
namespace Azuriom\Plugin\GamingHubManager\Data;
final readonly class ExtensionManifest { public function __construct(public int $schema, public string $id, public string $name, public string $version, public string $type, public string $description, public string $author, public ?string $homepage, public ?string $repository, public array $requires, public array $provides, public array $consumes, public string $pluginDirectory, public string $checksumAlgorithm, public ?string $publicAttributionLabel, public array $raw) {} public function toArray():array{return $this->raw;} }
