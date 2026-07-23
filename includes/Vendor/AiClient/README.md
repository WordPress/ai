# Vendored: PHP AI Client (forward-ported SDK subset)

This directory contains a **minimal, verbatim copy** of selected classes from
[WordPress/php-ai-client](https://github.com/WordPress/php-ai-client), so the AI plugin can use
newer SDK capabilities before every environment bundles them (e.g. embedding generation lands in
WordPress core in 7.2 via
[wordpress-develop#12530](https://github.com/WordPress/wordpress-develop/pull/12530)).

The overlay is organized into **independent features** (see `SDK_Overlay::FEATURES`). Each feature
is gated separately: an environment may already provide one feature (so the overlay defers to it)
while lacking another (so the overlay supplies it). Features are never gated on one another —
embeddings and, later, streaming activate or defer on their own.

- **Upstream:** [WordPress/php-ai-client](https://github.com/WordPress/php-ai-client)
- **License:** GPL-2.0-or-later.

## Features

### `embeddings`

- **Vendored commit:** `593a04cd18d22670f1186fb13f715987671d330a` (merge of [PR #244](https://github.com/WordPress/php-ai-client/pull/244))
- **Sentinel:** `WordPress\AiClient\Builders\EmbeddingBuilder` (present only if the environment already ships embeddings)

The new classes introduced by PR #244, plus the two existing classes it modified that lie on the
embedding execution path:

| Vendored file | Kind |
| --- | --- |
| `src/Builders/EmbeddingBuilder.php` | new |
| `src/Builders/Traits/ModelResolutionTrait.php` | new |
| `src/Providers/ModelResolver.php` | new |
| `src/Providers/Models/DTO/ModelRequirements.php` | modified (adds `fromEmbeddingData()`) |
| `src/Providers/Models/DTO/ModelConfig.php` | modified (adds `dimensions` support) |
| `src/Providers/Models/EmbeddingGeneration/Contracts/EmbeddingGenerationModelInterface.php` | new |
| `src/Results/DTO/Embedding.php` | new |
| `src/Results/DTO/EmbeddingResult.php` | new |
| `src/Events/BeforeGenerateEmbeddingEvent.php` | new |
| `src/Events/AfterGenerateEmbeddingEvent.php` | new |

## What was intentionally NOT copied

- `src/AiClient.php` — its PR #244 changes are only static convenience wrappers; we build
  `EmbeddingBuilder` directly and use the environment's unmodified `AiClient::defaultRegistry()`.
- `src/Builders/PromptBuilder.php` — refactored by PR #244, but the embedding path does not use it.
- `src/Providers/Models/Enums/OptionEnum.php` — the PR #244 diff is docblock-only; behavior is
  driven dynamically off `ModelConfig`'s `KEY_*` constants.

## Modifications applied

**None.** Unlike the `Secrets` vendor directory, these files keep their real
`WordPress\AiClient\…` namespace unchanged. That is required: the updated OpenAI/Google/Ollama
provider plugins must implement *this* `EmbeddingGenerationModelInterface` symbol, so it has to be
the canonical class, not a re-namespaced copy.

## How it loads

`SDK_Overlay.php` (one directory up) decides per feature whether to `defer`, `skip`, or
`activate`, then registers a single prepended PSR-4 autoloader that serves **only the classes of
the features that activated**. Every other `WordPress\AiClient\…` class falls through to the
environment's own autoloader. A shared base-SDK precondition (`WordPress\AiClient\AiClient` must be
present) gates the whole overlay, since the vendored classes extend base-SDK classes we do not
ship. See that file for the detection, conflict-guard, and fall-through logic.

## Adding a feature (e.g. streaming)

1. Vendor the feature's new/modified files into `src/` at their real namespace paths.
2. Add an entry to `SDK_Overlay::FEATURES` with:
   - a `sentinel` — a **net-new** class the feature introduces (never a shared/base class),
   - `guards` — any existing class the feature modifies mapped to a method only our copy defines,
   - `classes` — the exact list of fully-qualified class names the feature overlays.
3. Add the feature's vendored commit + file table to this README.

**Disjoint sets, single snapshot.** Features should own disjoint class sets. If two features must
modify the *same* class, vendor one file containing the union of both features' additions and list
it under both features' `classes`; because the whole overlay is pinned to a single, internally
consistent snapshot, a class is never needed in two incompatible versions at once. Pin to the SDK
version the target environments bundle to avoid drift with the environment's unchanged classes.

These files are exempt from PHPCS/PHPStan (`phpcs.xml.dist` excludes `includes/Vendor/`;
`phpstan.neon.dist` excludes `includes/Vendor/AiClient/src/` from scanning).
