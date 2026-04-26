# C2PA Monitor

Read-only experiment for [C2PA Content Credentials](https://c2pa.org/) in uploaded
images. Layers land incrementally on the integration branch; this README grows with
each layer.

## Status

Experimental. Scaffolding, record format, and parsing are delivered in separate
iterations. See the integration branch commit messages for the layer map.

## Postmeta record

Stored at `_wpai_monitor_record` as a JSON-encoded string.

**Canonical contract:** the subject-only JSON Schema in the DIF
[credential-schemas](https://github.com/decentralized-identity/credential-schemas)
repository
([`wpai-monitor-record/schema.json`](https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/WordPress/schemas/wpai-monitor-record/schema.json)),
extending
[`media-provenance-capture`](https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/OpenVerifiable/schemas/media-provenance-capture/schema.json)
(CMS-agnostic base). Open a DIF pull request in parallel; Layer 2+ PRs in this
repo reference that schema PR.

For human-readable shape while iterating:

```jsonc
// $schema (optional) — for validators once the DIF files are on main
{
  "schema_version": 1,
  "captured_at": "2026-04-22T19:30:00Z",
  "duration_ms": 47,
  "source": {
    "attachment_id": 1234,
    "original_path_relative": "2026/04/photo.jpg",
    "size_bytes": 2841093,
    "mime": "image/jpeg"
  },
  "traditional": { "exif": {}, "iptc": {}, "xmp": {} },
  "c2pa": {
    "present": true,
    "format": "jpeg",
    "container": "APP11/JUMBF",
    "manifest_sha256": "ab12...",
    "manifest_length": 184213,
    "sidecar_path_relative": "ai-c2pa/1234.jpeg.c2pa",
    "decoded": null
  },
  "errors": []
}
```

## Sidecar layout

```
wp-content/uploads/ai-c2pa/
├── .htaccess        ← Apache deny-all (auto-written)
├── index.php        ← silence-is-golden placeholder (auto-written)
└── <attachment_id>.<format>.c2pa
```

**Operators on nginx must add a deny rule manually**, e.g.:

```nginx
location ^~ /wp-content/uploads/ai-c2pa/ {
    deny all;
}
```

The `.htaccess` and `index.php` files are written on first use and are not
managed afterwards. Operators may replace them.

## Why a sidecar instead of postmeta?

C2PA manifests in the wild can run into the hundreds of kilobytes. Persisting
that as serialized postmeta would balloon `wp_postmeta`, slow list-table
queries, and bloat REST responses for every consumer that fetches attachment
meta. Sidecars are reversible, cheap, and mirror how core treats
`wp_get_original_image_path()` (data lives next to the image, the database
holds a reference).

## Test fixtures

Synthetic fixtures are generated at runtime by
`tests/Integration/Includes/Experiments/C2pa_Monitor/Fixtures.php`. They are
just well-formed enough at the container level to drive the detector and are
**not** valid signed C2PA assets. Generating them at runtime keeps binary
blobs out of the repo and avoids any third-party fixture licensing.

## Constraints

- **Read-only** — never mutates images, manifests, or core attachment fields.
- **Fail-open** — every error path should write a record and return; the upload
  must not block.
- **No external dependencies** — no Composer additions, no outbound HTTP, no
  shell-outs for the capture path. Pure PHP byte parsing.
- **Bounded scan** — files larger than `C2pa_Monitor::MAX_SCAN_BYTES` (64 MiB)
  are skipped; individual manifest payloads are capped in `Manifest_Reader`.
