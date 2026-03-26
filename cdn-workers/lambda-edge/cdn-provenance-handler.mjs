/**
 * AWS Lambda@Edge: C2PA Image Provenance
 *
 * Injects a C2PA-Manifest-URL header into image responses by looking up
 * the manifest via the WordPress REST API.
 *
 * Environment variable: WORDPRESS_REST_URL
 * e.g. https://your-site.com/wp-json
 *
 * For CDN-transform survival (pHash matching), use Encypher free API:
 * https://encypherai.com
 */

import https from 'https';

const WORDPRESS_REST_URL = process.env.WORDPRESS_REST_URL || '';

function httpsGet(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      let data = '';
      res.on('data', (chunk) => { data += chunk; });
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, body: JSON.parse(data) });
        } catch (e) {
          resolve({ status: res.statusCode, body: null });
        }
      });
    }).on('error', reject);
  });
}

export const handler = async (event) => {
  const response = event.Records[0].cf.response;
  const request = event.Records[0].cf.request;

  const contentType = (response.headers['content-type'] || [{ value: '' }])[0].value;
  if (!contentType.startsWith('image/')) {
    return response;
  }

  // Canonical URL: strip query params.
  const uri = request.uri;
  const host = request.headers['host'][0].value;
  const canonicalUrl = `https://${host}${uri}`;

  if (!WORDPRESS_REST_URL) {
    return response;
  }

  try {
    const lookupUrl = `${WORDPRESS_REST_URL}/c2pa-provenance/v1/images/lookup?url=${encodeURIComponent(canonicalUrl)}`;
    const result = await httpsGet(lookupUrl);

    if (result.status === 200 && result.body && result.body.manifest_url) {
      response.headers['c2pa-manifest-url'] = [{ key: 'C2PA-Manifest-URL', value: result.body.manifest_url }];
    }
  } catch (e) {
    // Lookup failed — return original response.
  }

  return response;
};
