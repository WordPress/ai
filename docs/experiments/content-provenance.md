# Content Provenance

**Status:** Experiment
**Category:** Editor
**Requires:** WordPress 6.0+, PHP 7.4+
**Version added:** 0.5.0

## Overview

The Content Provenance experiment embeds cryptographic proof of origin into published content using the [C2PA 2.3 text authentication specification](https://spec.c2pa.org/specifications/specifications/2.3/specs/C2PA_Specification.html#embedding_manifests_into_unstructured_text) (Section A.7). When you publish or update a post, an invisible C2PA manifest is woven into the text using Unicode variation selectors. Anyone with the text — even if it was copy-pasted, scraped, or syndicated — can verify it came from your site and hasn't been modified.

Zero editorial workflow change. Signing happens automatically at publish time.

## Setup

1. Go to **Settings → AI Experiments**
2. Enable **Content Provenance**
3. Choose your signing tier (see below)
4. Publish or update a post — it will be signed automatically

## Signing Tiers

### Local Signing (default, zero setup)

A keypair is generated locally when you enable the experiment. No accounts, no network dependencies, no costs.

**What works:** Anyone can verify your content hasn't been tampered with since publication.

**Limitation:** The signer's identity is not on the C2PA Trust List. External validators (Adobe Content Credentials, etc.) can confirm content integrity but may show the signer as "unverified." This is analogous to a self-signed HTTPS certificate: the cryptography works, the organizational identity isn't independently confirmed.

### Connected Signing (optional)

Configure a C2PA-compliant signing service for full trust list verification. External validators will confirm both content integrity and your organizational identity.

**Settings required:**
- **Signing service URL** — endpoint of a C2PA-compliant signing service
- **API key** — credential for the signing service

Any C2PA-compliant signing service works.

### BYOK — Bring Your Own Key (advanced)

Use your own code signing certificate. Full control over your trust chain.

**Settings required:**
- **Certificate path** — path to your PEM-format signing certificate

## Badge States

The Gutenberg sidebar shows a shield badge reflecting the current signing status:

| Badge | Meaning |
|-------|---------|
| 🟢 Green shield (filled, checkmark) | Signed with verified organizational identity (connected/BYOK) |
| 🔵 Blue shield (outline) | Signed with local key — content integrity verifiable, identity unverified |
| 🟡 Yellow shield (warning) | Content modified since last signing — will re-sign on next publish |
| 🔴 Red shield (X) | Tamper detected — content does not match signed manifest |
| ⚪ Grey shield (empty) | Not signed |

## Verification

### In the editor

Click **Verify** in the Content Provenance sidebar panel to check the current post content against its stored manifest.

### Public REST endpoint

```
POST /wp-json/c2pa-provenance/v1/verify
Content-Type: application/json

{ "text": "Content to verify (with embedded Unicode provenance)..." }
```

Response:
```json
{
  "verified": true,
  "status": "verified",
  "manifest": { ... },
  "signed_at": "2026-03-10T12:00:00Z",
  "signer_tier": "local"
}
```

### Standards-based discovery

The experiment registers a `/.well-known/c2pa` endpoint per C2PA 2.x §6.4. C2PA-aware tools and crawlers can discover provenance information for your site at:

```
GET /.well-known/c2pa
```

## WordPress Abilities API

This experiment registers two abilities that other plugins can use:

```php
// Sign content
$result = wp_do_ability( 'c2pa/sign', [
    'text'     => 'Content to sign',
    'action'   => 'c2pa.created',  // or 'c2pa.edited'
    'metadata' => [ 'title' => 'Post Title', 'url' => 'https://...' ],
] );

if ( ! is_wp_error( $result ) ) {
    $signed_text = $result['signed_text'];  // text with embedded provenance
    $manifest    = $result['manifest'];     // JSON manifest
}

// Verify content
$verification = wp_do_ability( 'c2pa/verify', [
    'text' => $text_to_verify,
] );

// $verification['verified'] === true|false
// $verification['status']   === 'verified'|'tampered'|'unsigned'|'invalid'
```

## Provenance Chain

When a post is updated, the new manifest includes a reference to the previous manifest as a C2PA ingredient (`c2pa.ingredient.v2`). This creates a verifiable edit history: each version of the post can be traced back to the original publication.

## Post Meta

The experiment stores the following post meta:

| Key | Type | Description |
|-----|------|-------------|
| `_c2pa_manifest` | string | JSON manifest for the current version |
| `_c2pa_status` | string | `signed`, `error` |
| `_c2pa_signed_at` | string | ISO 8601 timestamp of last signing |
| `_c2pa_signer_tier` | string | `local`, `connected`, or `byok` |
| `_c2pa_content_hash` | string | SHA-256 hash of signed content |

## Standards Reference

- [C2PA 2.3 Specification §A.7](https://spec.c2pa.org/specifications/specifications/2.3/specs/C2PA_Specification.html#embedding_manifests_into_unstructured_text) — Text manifest embedding
- [C2PA 2.x §6.4](https://spec.c2pa.org/) — External manifest URI discovery
