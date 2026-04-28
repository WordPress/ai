# C2PA Monitor

Read-only experiment that detects [C2PA Content Credentials](https://c2pa.org/) in
freshly uploaded images and captures the raw manifest store before WordPress's
image processing pipeline destroys it.

## Status

Experimental. No UI, no cryptographic verification, no JUMBF / CBOR decoding in
this pass; richer claim summaries and UI are deferred. The feature was
assembled in reviewable layers on the integration branch (register → record →
detection → reader/sidecar → hook).

## What it does

On every successful image upload (`add_attachment` priority 20):

1. Resolve the original on-disk file via `wp_get_original_image_path()`.
2. Sniff magic bytes for JPEG, PNG, or WebP. Other MIME types are skipped.
3. Walk the container looking for the C2PA storage segment:
	- JPEG: contiguous `APP11` (0xFFEB) markers carrying a JUMBF payload tagged
		with the literal `c2pa` (or `jumb`) byte sequence.
	- PNG: a `caBX` chunk.
	- WebP: a top-level RIFF chunk of type `C2PA`.
4. If found, stream the raw manifest bytes once, computing SHA-256 in flight,
	and persist the bytes to a sidecar file under `wp-content/uploads/ai-c2pa/`.
5. Write a structured `_wpai_monitor_record` postmeta entry pointing at the
	sidecar.

The handler is wrapped in a `try / catch ( Throwable )` boundary and writes a
record on every supported MIME type even if every stage fails. The upload
itself never blocks.

## Postmeta record

Stored at `_wpai_monitor_record` as a JSON-encoded string.

**Canonical contract:** the subject-only JSON Schema in the DIF
[credential-schemas](https://github.com/decentralized-identity/credential-schemas)
repository
([`wpai-monitor-record/schema.json`](https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/WordPress/schemas/wpai-monitor-record/schema.json)),
extending
[`media-provenance-capture`](https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/OpenVerifiable/schemas/media-provenance-capture/schema.json)
(CMS-agnostic base). Cross-link the DIF pull request in PR descriptions.

```jsonc
{
	"@context": [
		"https://schema.org/",
		"https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/WordPress/schemas/wpai-monitor-record/context.json"
	],
	"schema_version": 1,
	"captured_at": "2026-04-22T19:30:00Z",
	"duration_ms": 47,
	"source": {
		"attachment_id": 1234,
		"original_path_relative": "2026/04/photo.jpg",
		"size_bytes": 2841093,
		"mime": "image/jpeg"
	},
	"traditional": {
		"exif": {},
		"iptc": {},
		"xmp": {}
	},
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

When no manifest is found, `c2pa` collapses to
`{ "present": false, "format": <detected or null> }` and no sidecar is written.

`c2pa.decoded` is reserved for a follow-up (claim generator, `digitalSourceType`,
action history). `traditional.*` are reserved for a future pass that promotes
WordPress's existing EXIF / IPTC / XMP extraction into the same record.

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

## Constraints

- **Read-only** — never mutates images, manifests, or core attachment fields.
- **Fail-open** — every error path writes a record and returns; the upload
	always succeeds.
- **No external dependencies** — no Composer additions, no outbound HTTP, no
	shell-outs. Pure PHP byte parsing.
- **Bounded scan** — files larger than `C2pa_Monitor::MAX_SCAN_BYTES` (64 MiB) are
	skipped; individual manifest payloads are capped at
	`Manifest_Reader::MAX_MANIFEST_BYTES` (16 MiB).

## Test fixtures

Synthetic fixtures are generated at runtime by
`tests/Integration/Includes/Experiments/C2pa_Monitor/Fixtures.php`. They are
just well-formed enough at the container level to drive the detector and are
**not** valid signed C2PA assets. Generating them at runtime keeps binary
blobs out of the repo and avoids any third-party fixture licensing.

## Out of scope (this release)

- JUMBF box reader and CBOR decoder.
- Populating `c2pa.decoded` with claim generator / digital source type / action
	history.
- Admin UI, media library badge, icon overlay.
- Cryptographic verification of manifests.
- Preserving manifests through WordPress's GD / Imagick subsize pipeline.
