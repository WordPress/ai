//! Fastly Compute: C2PA Image Provenance
//!
//! Injects a C2PA-Manifest-URL header into image responses by looking up
//! the manifest via the WordPress REST API.
//!
//! Edge Dictionary key: `wordpress_rest_url`
//! Value: https://your-site.com/wp-json
//!
//! For CDN-transform survival (pHash matching), use Encypher free API:
//! https://encypherai.com

use fastly::http::{Method, StatusCode};
use fastly::{Error, Request, Response};

#[fastly::main]
fn main(req: Request) -> Result<Response, Error> {
    let backend = "origin";
    let mut beresp = req.send(backend)?;

    // Only process image responses.
    let content_type = beresp
        .get_header_str("content-type")
        .unwrap_or("")
        .to_string();

    if !content_type.starts_with("image/") {
        return Ok(beresp);
    }

    // Get WordPress REST URL from Edge Dictionary.
    let dict = fastly::Dictionary::open("wordpress_rest_url");
    let wp_rest_url = match dict.get("wordpress_rest_url") {
        Some(url) => url,
        None => return Ok(beresp),
    };

    // Canonical URL: scheme + host + path (no query string).
    let req_url = req.get_url();
    let canonical_url = format!(
        "{}://{}{}",
        req_url.scheme(),
        req_url.host_str().unwrap_or(""),
        req_url.path()
    );

    let encoded_url = urlencoding::encode(&canonical_url);
    let lookup_url = format!(
        "{}/c2pa-provenance/v1/images/lookup?url={}",
        wp_rest_url.trim_end_matches('/'),
        encoded_url
    );

    // Look up the manifest URL.
    let lookup_req = Request::get(lookup_url);
    let lookup_resp = lookup_req.send("wordpress_api");

    if let Ok(mut resp) = lookup_resp {
        if resp.get_status() == StatusCode::OK {
            if let Ok(body) = resp.take_body_str() {
                if let Ok(json) = serde_json::from_str::<serde_json::Value>(&body) {
                    if let Some(manifest_url) = json["manifest_url"].as_str() {
                        beresp.set_header("C2PA-Manifest-URL", manifest_url);
                    }
                }
            }
        }
    }

    Ok(beresp)
}
