# C2PA Monitor

## Summary

Read-only experiment that detects [C2PA Content Credentials](https://c2pa.org/) in freshly uploaded images and captures the raw manifest store before WordPress's image processing pipeline destroys it. It writes a structured `_wpai_monitor_record` postmeta entry and persists the raw manifest bytes to a sidecar file for downstream consumers. The capture is fail-open and never blocks an upload.

## Status

Experimental. Cryptographic verification and full JUMBF/CBOR decoding are deferred. The feature was assembled in reviewable layers (register → record → detection → reader/sidecar → hook → UI).

## What it does

On every successful image upload (`add_attachment` priority 20):

1. Resolve the original on-disk file via `wp_get_original_image_path()`.
2. Sniff magic bytes for JPEG, PNG, or WebP. Other MIME types are skipped.
3. Walk the container looking for the C2PA storage segment:
   - JPEG: contiguous `APP11` (0xFFEB) markers. The first segment (packet sequence number `Z = 1`) must contain a valid JUMBF superbox (`jumb`) with a type UUID box (`jumd`) carrying the C2PA type UUID `6332706100110010800000AA00389B71`. Continuation segments (`Z > 1`) repeat the `LBox`/`TBox` header and are stripped before reassembly.
   - PNG: a `caBX` chunk.
   - WebP: a top-level RIFF chunk of type `C2PA`.
4. If found, stream the raw manifest bytes once, computing SHA-256 in flight, and persist the bytes to a sidecar file under `wp-content/uploads/ai-c2pa/`.
5. Write a structured `_wpai_monitor_record` postmeta entry pointing at the sidecar.

The handler is wrapped in a `try / catch ( Throwable )` boundary and writes a record on every supported MIME type even if every stage fails. The upload itself never blocks.

## Postmeta record

Stored at `_wpai_monitor_record` as a JSON-encoded string.

**Canonical contract:** the subject-only JSON Schema in the DIF [credential-schemas](https://github.com/decentralized-identity/credential-schemas) repository ([`wpai-monitor-record/schema.json`](https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/WordPress/schemas/wpai-monitor-record/schema.json)), extending [`media-provenance-capture`](https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/OpenVerifiable/schemas/media-provenance-capture/schema.json) (CMS-agnostic base). Cross-link the DIF pull request in PR descriptions.

```jsonc
{
	"@context": [
		"https://schema.org/",
		"https://w3id.org/openverifiable/v1"
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

The `@context` entry `https://w3id.org/openverifiable/v1` is a permanent [w3id.org](https://w3id.org/) identifier that 302-redirects to the OpenVerifiable JSON-LD context in the DIF credential-schemas repo. Using the w3id identifier keeps the value baked into every stored record stable even if the underlying document moves (registration: [perma-id/w3id.org#6376](https://github.com/perma-id/w3id.org/pull/6376)).

When no manifest is found, `c2pa` collapses to `{ "present": false, "format": <detected or null> }` and no sidecar is written.

`c2pa.decoded` is reserved for a follow-up (claim generator, `digitalSourceType`, action history). `traditional.*` are reserved for a future pass that promotes WordPress's existing EXIF / IPTC / XMP extraction into the same record.

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

The `.htaccess` and `index.php` files are written on first use and are not managed afterwards. Operators may replace them.

### Access considerations

The sidecar contains the raw C2PA manifest bytes extracted verbatim from the uploaded image. Those same bytes are already embedded in the original attachment, which WordPress serves publicly at its uploads URL — the pipeline only strips C2PA from generated subsizes, never from the original. The sidecar therefore does not expose anything that is not already publicly reachable through the original image, so it is stored alongside the uploads tree for lifecycle parity (backups, migrations, and deletion all follow the attachment).

The `.htaccess` / `index.php` hardening is defense-in-depth (directory-listing suppression and Apache deny), not a confidentiality boundary. Because the underlying data is not sensitive, the Apache-only nature of `.htaccess` is acceptable; the nginx snippet above is provided for operators who prefer to block direct access anyway. If a future pass captures data that is *not* already present in the public original (for example decoded PII), storage should move to a non-public location at that time.

## Why a sidecar instead of postmeta?

C2PA manifests in the wild can run into the hundreds of kilobytes. Persisting that as serialized postmeta would balloon `wp_postmeta`, slow list-table queries, and bloat REST responses for every consumer that fetches attachment meta. Sidecars are reversible, cheap, and mirror how core treats `wp_get_original_image_path()` (data lives next to the image, the database holds a reference).

## Constraints

- **Read-only** — never mutates images, manifests, or core attachment fields.
- **Fail-open** — every error path writes a record and returns; the upload always succeeds.
- **No external dependencies** — no Composer additions, no outbound HTTP, no shell-outs. Pure PHP byte parsing.
- **Bounded scan** — files larger than `C2pa_Monitor::MAX_SCAN_BYTES` (64 MiB) are skipped; individual manifest payloads are capped at `Manifest_Reader::MAX_MANIFEST_BYTES` (16 MiB).

## Media Library column

When the experiment is enabled a **Content Credentials** column appears in the Media Library list view for each attachment:

| Value | Tooltip / Meaning |
|---|---|
| ✓ Credentials | *Unverified* — C2PA Content Credentials were detected in this file but have not been validated. Links to the [CAI Verify tool](https://verify.contentauthenticity.org/). |
| No credentials | No C2PA Content Credentials were detected in this file. |
| — | No scan record exists (uploaded before the experiment was enabled, or a non-image MIME type). |

The column is sortable: clicking the header sorts credentials-first (descending). Attachments with no scan record appear at the bottom.

**Verification note:** WordPress attachment URLs are not reachable by the CAI verify tool's fetcher from outside the admin session (local, Playground, staging, and auth-gated sites are all unreachable). To verify a credential, download the original image from your media library and drag it into the verify tool manually. In-browser verification via the C2PA JS SDK (`@contentauth/sdk`) is planned as a follow-up and would remove this step.

## Attachment Details and Edit Media screens

The same three-state **Content Credentials** status is surfaced on two additional admin screens, using visible help text rather than a CSS tooltip (the tooltip's `position: absolute` positioning is clipped by the media modal's overflow container):

- **Attachment details** (`upload.php?item=<id>` and the media modal) — rendered via the `attachment_fields_to_edit` filter as a read-only HTML field. `show_in_edit => false` suppresses it on the Edit Media screen to avoid duplicating the meta box.
- **Edit Media** (`post.php?post=<id>&action=edit`) — rendered in a "Content Credentials" side meta box via `add_meta_boxes_attachment`, with a `<p class="description">` paragraph below the badge for each scannable state.
