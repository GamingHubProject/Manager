# Optional Package Manifest

Current Azuriom packages need only `plugin.json`. Future packages can include `gaming-hub-extension.json`:

```json
{
  "schema": 1,
  "id": "gaming-hub-example",
  "name": "Gaming Hub Example",
  "version": "1.0.0",
  "type": "feature",
  "description": "Example package.",
  "author": "Example",
  "repository": "https://github.com/example/gaming-hub-example",
  "requires": {
    "azuriom": ">=1.2.0",
    "php": ">=8.2",
    "gaming-hub-core": ">=0.6.6",
    "extensions": {}
  },
  "provides": {},
  "consumes": {},
  "package": {
    "plugin_directory": "gaming-hub-example",
    "checksum_algorithm": "sha256"
  }
}
```

The manifest ID, version, and plugin directory must match `plugin.json` and the ZIP root directory. Before installation or update, the package version must also match the selected semantic GitHub Release tag and any version suffix encoded in the release asset filename. Manager itself is not a valid managed package target.
