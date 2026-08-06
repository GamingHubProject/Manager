# Registry Schema 1

A registry contains descriptive package metadata and the GitHub repository used for authoritative release discovery.

```json
{
  "schema": 1,
  "id": "example-registry",
  "name": "Example registry",
  "homepage": "https://github.com/example",
  "extensions": [
    {
      "id": "gaming-hub-example",
      "name": "Gaming Hub Example",
      "description": "Example package.",
      "author": "Gaming Hub",
      "category": "Features",
      "repository": "https://github.com/example/gaming-hub-example",
      "release_asset": "gaming-hub-example-v*.zip",
      "checksum_asset": "SHA256SUMS",
      "verified": true,
      "official": false
    }
  ]
}
```

## Authoritative version

For GitHub-backed packages, published GitHub Releases are authoritative. Manager:

1. fetches releases from the package repository;
2. ignores drafts;
3. applies the stable/prerelease source policy;
4. requires a matching uploaded ZIP asset;
5. parses semantic versions from release tags;
6. selects the highest matching version.

`latest_version` is optional and deprecated. It is never allowed to hide a newer discovered GitHub release. Manager uses it only as a temporary display hint when GitHub discovery is unavailable.

GitHub `zipball_url` and `tarball_url` source archives are not package assets and are never selected.

## Checksum sources

The selected ZIP must have a valid SHA-256 checksum. Sources are evaluated in this order:

1. an explicit checksum asset, including the configured `checksum_asset`, `<selected ZIP>.sha256`, `SHA256SUMS`, `SHA256SUMS.txt`, `checksums.txt`, `checksum.txt`, `sha256sums.txt`, or `sha256.txt`;
2. the `digest` field of the exact selected GitHub release asset in `sha256:<64 hexadecimal characters>` format;
3. a registry-pinned checksum whose version and asset name exactly match the selected release;
4. rejection when no valid source exists.

Generic checksum files must name the exact selected ZIP. A bare 64-character checksum is accepted only from the exact `<selected ZIP>.sha256` sidecar.

A registry may pin checksums using records:

```json
{
  "checksums": [
    {
      "version": "1.0.0",
      "asset": "gaming-hub-example-v1.0.0.zip",
      "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  ]
}
```

or a version/asset map:

```json
{
  "checksums": {
    "1.0.0": {
      "gaming-hub-example-v1.0.0.zip": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  }
}
```

Legacy scalar `sha256` is accepted only when the registry also pins the exact selected version and exact asset name. Wildcard asset patterns are not an exact checksum binding.
