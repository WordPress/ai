# TransformersPHP Local Provider (Separate Plugin)

## Overview

Spin up a dedicated WordPress plugin that bundles the [TransformersPHP](https://github.com/CodeWithKyrian/transformers-php) stack and registers a self-hosted provider with the WP AI Client registry. The plugin should: (1) remain GPLv3+ without impacting the GPLv2+ licensing of the canonical AI Experiments plugin, and (2) expose a first-class discovery hook so AI Experiments and other clients see a "local Transformers" provider only when servers meet the sharper runtime requirements (PHP ≥ 8.1, `ext-ffi`, ONNX runtime libs, model cache).

## Why this needs a separate plugin

1. **Licensing** – TransformersPHP is Apache 2.0. Bundling it directly into AI Experiments (GPLv2+) would force the entire core plugin to move to GPLv3, which conflicts with WP.org guidelines.
2. **Runtime requirements** – TransformersPHP demands PHP 8.1+, `ffi.enable=1`, higher memory limits, and platform-specific shared libraries. Those constraints are unacceptable for most shared hosts and should only impact opt-in sites.
3. **Distribution model** – The package ships native binaries per architecture and must be installed on the same target platform. Keeping it in a standalone project allows per-environment Composer installs and avoids inflating the canonical plugin zip.
4. **Operational blast radius** – ONNX inference can stall PHP-FPM workers. A dedicated plugin can add tailored health checks, WP-CLI tooling, and long-running queue support without complicating the main experiments plugin.

## Proposed scope

| Pillar | Description |
|--------|-------------|
| **Provider implementation** | Implement `TransformersPhpProvider` (extends `AbstractApiProvider`) plus text-generation model classes that wrap TransformersPHP pipelines and emit `GenerativeAiResult` objects. |
| **Model/catalog management** | Scan a configurable cache directory (or JSON manifest) to build `ModelMetadata` entries with capability + option metadata so the WP AI Client can route abilities appropriately. |
| **Admin + tooling** | New settings page for model downloads, cache path, FFI diagnostics, and WP-CLI commands (`wp transformers download <model>`). Include health-check endpoints so AI Experiments can detect readiness. |
| **Registry integration** | On activation (and when health checks pass), register the provider with `AiClient::defaultRegistry()` and expose a WordPress action/filter that the AI Experiments Extended Providers experiment can rely on. |
| **Documentation** | Installation guide that walks through Composer install on the production host, enabling `ext-ffi`, setting memory/JIT, and prefetching models. |

## Key requirements / acceptance criteria

- Plugin declares GPLv3 (or GPLv3+) and clearly communicates licensing differences from AI Experiments.
- Activation blocker that refuses to load unless PHP ≥ 8.1, `ext-ffi` enabled, and required shared libs are present.
- Provider registration only occurs when a health check confirms at least one usable ONNX model.
- REST + WP-CLI interfaces to download/update models, inspect cache usage, and test local inference latency.
- Hooks/filters so AI Experiments (and other consumers) can detect the provider and optionally display guidance in their UI.
- Deployment docs describing how to install via Composer on the server (per architecture), manage cache directories, and roll updates without impacting wp-admin requests.

## Open questions

1. Should the plugin ship its own background queue (e.g., Action Scheduler) to offload long-running inference, or rely on synchronous calls initially?
2. Do we allow multiple TransformersPHP instances (different cache roots) per site, or assume a single global registry?
3. Can we expose a lightweight HTTP proxy (outside PHP) for better concurrency, and if so, how do we package it?  
4. What telemetry/logging is needed so site owners can understand CPU/memory impact when enabling the provider?

## Labels

`providers`, `architecture`, `ai-core`, `new-plugin`, `needs-research`
