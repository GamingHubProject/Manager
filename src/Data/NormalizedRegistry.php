<?php
namespace Azuriom\Plugin\GamingHubManager\Data;
final readonly class NormalizedRegistry { public function __construct(public int $schema,public string $id,public string $name,public ?string $homepage,public array $extensions,public string $fetchedAt){} }
