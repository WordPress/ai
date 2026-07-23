# Vendored: PHP AI Client (embeddings SDK subset)

This directory contains a **minimal, verbatim copy** of the embedding-related classes from
[WordPress/php-ai-client](https://github.com/WordPress/php-ai-client), so the AI plugin can use
embedding generation before WordPress core bundles it (core support arrives in WordPress 7.2 via
[wordpress-develop#12530](https://github.com/WordPress/wordpress-develop/pull/12530)).

- **Upstream:** [WordPress/php-ai-client](https://github.com/WordPress/php-ai-client)
- **Vendored commit:** `593a04cd18d22670f1186fb13f715987671d330a` (merge of [PR #244](https://github.com/WordPress/php-ai-client/pull/244))
- **License:** GPL-2.0-or-later.

## What was copied

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

`SDK_Overlay.php` (one directory up) registers a prepended PSR-4 autoloader for the
`WordPress\AiClient\` prefix pointing at `src/`, **only when the environment's own SDK lacks
embeddings**. See that file for the detection and fall-through logic.

## Updating

To pull a newer upstream slice: re-fetch the changed/new files into `src/`, bump the commit hash
above and the file table, and (if a newer feature is added) update the sentinel class in
`SDK_Overlay.php`. Pin to the SDK version WordPress core bundles to avoid drift with the
environment's unchanged classes. These files are exempt from PHPCS/PHPStan (see `phpcs.xml.dist` /
`phpstan.neon.dist`).
