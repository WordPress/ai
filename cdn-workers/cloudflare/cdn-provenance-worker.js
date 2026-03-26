/**
 * Cloudflare Worker: C2PA Image Provenance
 *
 * Looks up the C2PA manifest URL for any image request via the WordPress
 * REST API and injects a C2PA-Manifest-URL response header.
 *
 * Configuration (wrangler.toml):
 *   WORDPRESS_REST_URL = "https://your-site.com/wp-json"
 *   CDN_PROVENANCE_CACHE = KV namespace binding
 *
 * For CDN-transform survival (pHash matching across resized images),
 * use the Encypher free API: https://encypherai.com
 */

export default {
  async fetch(request, env) {
    const response = await fetch(request);

    // Only process image responses.
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.startsWith('image/')) {
      return response;
    }

    const url = new URL(request.url);
    // Canonical URL: scheme + host + path (strip CDN transform params).
    const canonicalUrl = `${url.protocol}//${url.hostname}${url.pathname}`;
    const cacheKey = `manifest:${canonicalUrl}`;

    // Try KV cache first.
    let manifestUrl = null;
    if (env.CDN_PROVENANCE_CACHE) {
      manifestUrl = await env.CDN_PROVENANCE_CACHE.get(cacheKey);
    }

    if (!manifestUrl) {
      // Look up via WordPress REST API.
      const lookupUrl = `${env.WORDPRESS_REST_URL}/c2pa-provenance/v1/images/lookup?url=${encodeURIComponent(canonicalUrl)}`;

      try {
        const lookupResponse = await fetch(lookupUrl, {
          headers: { 'Accept': 'application/json' },
        });

        if (lookupResponse.ok) {
          const data = await lookupResponse.json();
          manifestUrl = data.manifest_url || null;

          // Cache the result.
          if (manifestUrl && env.CDN_PROVENANCE_CACHE) {
            await env.CDN_PROVENANCE_CACHE.put(cacheKey, manifestUrl, { expirationTtl: 3600 });
          }
        }
      } catch (e) {
        // Lookup failed — serve original response without header.
        return response;
      }
    }

    if (!manifestUrl) {
      return response;
    }

    // Inject the header into a new response.
    const newHeaders = new Headers(response.headers);
    newHeaders.set('C2PA-Manifest-URL', manifestUrl);

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: newHeaders,
    });
  },
};
