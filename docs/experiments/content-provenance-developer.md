# Content Provenance — Developer Guide

## Abilities API

Two abilities are registered: `c2pa/sign` and `c2pa/verify`.

### c2pa/sign

Signs text content with C2PA provenance using the configured signing tier.

**Input:**
```json
{
  "text":     "string (required) — plain text to sign",
  "action":   "string (optional) — 'c2pa.created' | 'c2pa.edited', default: 'c2pa.created'",
  "metadata": "object (optional) — { title, url, author, post_id }"
}
```

**Output:**
```json
{
  "signed_text": "string — text with embedded Unicode provenance",
  "manifest":    "string — JSON manifest",
  "signer_tier": "string — 'local' | 'connected' | 'byok'"
}
```

### c2pa/verify

Verifies C2PA provenance embedded in text. No authentication required.

**Input:**
```json
{ "text": "string (required) — text to verify" }
```

**Output:**
```json
{
  "verified": "bool",
  "status":   "string — 'verified' | 'tampered' | 'unsigned' | 'invalid'",
  "manifest": "object|null — parsed manifest if present",
  "error":    "string|null — error description if any"
}
```

## Hooks

### `wpai_register_features`

Register a custom signing backend or extend behaviour:

```php
add_action( 'wpai_register_features', function( $registry ) {
    // Access the content provenance experiment.
    $experiment = $registry->get_feature( 'content-provenance' );
} );
```

### `wpai_content_provenance_experiment_instance`

Provides the Content_Provenance experiment instance to the C2PA_Sign ability:

```php
add_filter( 'wpai_content_provenance_experiment_instance', function( $experiment ) {
    // Return a custom Content_Provenance instance if needed.
    return $experiment;
} );
```

### `wpai_feature_content-provenance_enabled`

Filter the experiment's enabled state programmatically:

```php
add_filter( 'wpai_feature_content-provenance_enabled', '__return_true' );
```

## Signing Interface

Implement `WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface` to provide a custom signing backend:

```php
use WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface;

class My_Custom_Signer implements Signing_Interface {
    public function sign( string $content, array $claims ) {
        // Your signing logic here.
        // Return JSON manifest string or WP_Error.
    }

    public function get_tier(): string {
        return 'connected';
    }
}
```

## Unicode Embedding

The `Unicode_Embedder` class handles low-level embedding:

```php
use WordPress\AI\Experiments\Content_Provenance\Unicode_Embedder;

// Embed a payload.
$signed_text = Unicode_Embedder::embed( $plain_text, $manifest_json );

// Extract embedded payload.
$manifest_json = Unicode_Embedder::extract( $signed_text ); // null if no embedding

// Strip all embeddings.
$clean_text = Unicode_Embedder::strip( $signed_text );
```

## REST Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/wp-json/c2pa-provenance/v1/verify` | Public | Verify text provenance |
| `GET` | `/wp-json/c2pa-provenance/v1/status` | Editor | Post signing status |
| `GET` | `/.well-known/c2pa` | Public | Discovery document (C2PA §6.4) |

## Testing

Run integration tests:
```bash
composer test -- --filter Content_Provenance
```

Or run a specific test:
```bash
composer test -- --filter test_unicode_embed_extract_roundtrip
```
