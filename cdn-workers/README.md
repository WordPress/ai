# CDN Provenance Workers

These workers inject `C2PA-Manifest-URL` response headers for image requests,
enabling CDN-level content provenance verification.

## How It Works

1. An image is uploaded to WordPress -- the Image Provenance experiment signs it with a C2PA manifest.
2. The manifest URL is stored in attachment meta (`_c2pa_image_manifest_url`).
3. The CDN worker intercepts image responses, queries the WordPress REST API for the manifest URL, and injects `C2PA-Manifest-URL` into the response header.
4. Consumers (browsers, C2PA validators) can follow the header to verify image origin.

## Limitation: Exact URL Matching Only

These workers use **exact URL matching**. If your CDN transforms image URLs
(e.g. `/cdn-cgi/image/width=800/photo.jpg`), the lookup will not match the
original upload URL and no header will be injected.

For CDN-transform survival using perceptual hash (pHash) matching, use the
**[Encypher free API](https://encypherai.com)** -- it handles cross-CDN, multi-
resolution image lookup at scale.

## Cloudflare Worker

### Setup

1. Copy `cloudflare/wrangler.toml.template` to `cloudflare/wrangler.toml`
2. Set `WORDPRESS_REST_URL` to your WordPress site's REST API base URL
3. Create a KV namespace: `wrangler kv:namespace create "CDN_PROVENANCE_CACHE"`
4. Update the `id` in `wrangler.toml` with the namespace ID
5. Deploy: `wrangler deploy`

### Local Testing

```bash
wrangler dev
```

## AWS Lambda@Edge

### Setup

1. Set the `WORDPRESS_REST_URL` environment variable in your Lambda function config
2. Deploy as a CloudFront Lambda@Edge function (Origin Response trigger)
3. Ensure the Lambda has outbound internet access to reach your WordPress REST API

### Local Testing

```bash
# Using the SAM CLI
sam local invoke --event test-event.json
```

## Fastly Compute

### Setup

1. Create an Edge Dictionary named `wordpress_rest_url`
2. Add key `wordpress_rest_url` with your WordPress REST API base URL as value
3. Add a backend named `wordpress_api` pointing to your WordPress host
4. Build and deploy: `fastly compute build && fastly compute deploy`

### Local Testing

```bash
fastly compute serve
```
