# Vendored: PHP AI Client (forward-ported SDK subset)

This directory contains a **minimal, verbatim copy** of selected classes from
[WordPress/php-ai-client](https://github.com/WordPress/php-ai-client), so the AI plugin can use
newer SDK capabilities before every environment bundles them (e.g. embedding generation lands in
WordPress core in 7.2 via
[wordpress-develop#12530](https://github.com/WordPress/wordpress-develop/pull/12530)).

The overlay is organized into **independent features** (see `SDK_Overlay::FEATURES`). Each feature
is gated separately: an environment may already provide one feature (so the overlay defers to it)
while lacking another (so the overlay supplies it). Features are never gated on one another —
embeddings and streaming activate or defer on their own.

- **Upstream:** [WordPress/php-ai-client](https://github.com/WordPress/php-ai-client)
- **License:** GPL-2.0-or-later.

## Features

### `embeddings`

- **Vendored commit:** `20a1a6d33a11d3f2955e9c5b7389af7ff51209bc` (upstream `trunk` merge commit of [PR #274](https://github.com/WordPress/php-ai-client/pull/274), which builds on [PR #244](https://github.com/WordPress/php-ai-client/pull/244))
- **Sentinel:** `WordPress\AiClient\Builders\EmbeddingBuilder` (present only if the environment already ships embeddings)

The new classes introduced by PR #244 and PR #274, plus the existing classes those PRs modified
that lie on the embedding execution path:

| Vendored file | Kind |
| --- | --- |
| `src/Builders/EmbeddingBuilder.php` | new |
| `src/Builders/Traits/ModelConfigurationTrait.php` | new |
| `src/Providers/ModelResolver.php` | new |
| `src/Providers/Models/DTO/ModelRequirements.php` | modified (adds `fromEmbeddingData()` and `getUnmetRequirements()`) |
| `src/Providers/Models/DTO/ModelConfig.php` | modified (adds `dimensions` support) |
| `src/Providers/Models/EmbeddingGeneration/Contracts/EmbeddingGenerationModelInterface.php` | new |
| `src/Results/DTO/Embedding.php` | new |
| `src/Results/DTO/EmbeddingResult.php` | new |
| `src/Events/BeforeGenerateEmbeddingEvent.php` | new |
| `src/Events/AfterGenerateEmbeddingEvent.php` | new |

**Pinned to a merged commit.** PR #274 merged upstream on 2026-08-31; these files are vendored
from the resulting `trunk` merge commit. Re-check this table against
upstream whenever a later PR touches the embedding execution path.

### What the `embeddings` feature intentionally did NOT copy

- `src/AiClient.php` — its PR #244 and PR #274 changes are only static convenience wrappers; we
  build `EmbeddingBuilder` directly and use the environment's unmodified
  `AiClient::defaultRegistry()`. Vendoring it is also not an option: it is the overlay's
  base-SDK precondition class.
- `src/Builders/Traits/ModelResolutionTrait.php` — vendored for PR #244, dropped for PR #274; see
  above.
- `src/Builders/PromptBuilder.php` — refactored by PR #244, but the embedding path does not use it.
- `src/Providers/Models/Enums/OptionEnum.php` — the PR #244 diff is docblock-only; behavior is
  driven dynamically off `ModelConfig`'s `KEY_*` constants.

### `streaming`

- **Vendored commit:** `861cefa888278acd9abb7ff3da410b0b0b2d3a52` (`thelovekesh/php-ai-client`, branch `add/streaming` — the head of open upstream [PR #255](https://github.com/WordPress/php-ai-client/pull/255))
- **Sentinel:** `WordPress\AiClient\Results\StreamedGenerativeAiResult` (present only if the environment already ships streaming)
- **Guards:** `Providers\Http\DTO\Response` → `getStream`, `Providers\Http\DTO\RequestOptions` → `isStream`

The streaming value types plus the two HTTP DTOs that must become stream-aware for a transport to
deliver chunks incrementally:

| Vendored file | Kind |
| --- | --- |
| `src/Results/StreamedGenerativeAiResult.php` | new |
| `src/Results/ChunkAccumulator.php` | new |
| `src/Results/ValueObjects/GenerativeAiResultChunk.php` | new |
| `src/Results/ValueObjects/CandidateDelta.php` | new |
| `src/Results/ValueObjects/ToolCallDelta.php` | new |
| `src/Providers/Models/TextGeneration/Contracts/StreamingTextGenerationModelInterface.php` | new |
| `src/Providers/Http/Streaming/Contracts/EventStreamParserInterface.php` | new |
| `src/Providers/Http/Streaming/SseEventStreamParser.php` | new |
| `src/Providers/Http/Streaming/ValueObjects/ServerSentEvent.php` | new |
| `src/Providers/Http/DTO/Response.php` | modified (adds `getStream()`; the constructor accepts a `StreamInterface` body) |
| `src/Providers/Http/DTO/RequestOptions.php` | modified (adds `setStream()` / `isStream()` and the `stream` key) |

**Pinned to an unmerged PR head.** Unlike `embeddings`, this snapshot is not from upstream `trunk`.
Re-vendor from `trunk` once PR #255 merges, and re-check the guard methods: if a later SDK release
adds `Response::getStream()` or `RequestOptions::isStream()` for another reason, the guards stop
discriminating and must be re-picked.

**Compatibility with the bundled SDK.** The vendored files are trunk-era; the target environment
(WordPress core 7.x) bundles PHP AI Client **0.3.1**. Every symbol the vendored classes reference
was checked against `wp-includes/php-ai-client/src/` and exists in 0.3.1 with a compatible
signature — `MessagePart::__construct($content, ?MessagePartChannelEnum, ?string)`,
`Candidate::__construct(Message, FinishReasonEnum)`,
`GenerativeAiResult::__construct(string, array, TokenUsage, ProviderMetadata, ModelMetadata, array)`,
`TokenUsage::__construct(int, int, int)`, `FunctionCall::__construct(?string, ?string, mixed)`,
`MessagePartChannelEnum::from()`, `FinishReasonEnum::stop()`. No cascade beyond the table above was
needed. The modified `Response` is a strict superset of 0.3.1's: the constructor's `?string $body`
is widened to `string|StreamInterface|null`, and `getBody()`/`getData()`/`toArray()` behave
identically for a string body.

### What the `streaming` feature deliberately does NOT copy

- `src/Builders/PromptBuilder.php` — 45KB of trunk-era code and the highest drift risk against the
  bundled 0.3.1. Its `streamGenerateTextResult()` only validates messages, resolves the configured
  model, checks `instanceof StreamingTextGenerationModelInterface`, and delegates; the plugin does
  that directly instead. Same call the `embeddings` feature made.
- `src/AiClient.php` — static convenience wrappers only, and it is the overlay's base-SDK
  precondition class.
- `src/Providers/Http/HttpTransporter.php` — Guzzle-specific. WordPress core supplies its own
  transporter over `wp_safe_remote_request`, and ships no Guzzle.
- `src/Events/GenerateResultErrorEvent.php` — only `PromptBuilder` dispatches it upstream, and
  `PromptBuilder` is not vendored. Nothing in the set above references it.
- `src/Providers/Http/Exception/ResponseException.php` — the PR only adds a
  `fromStreamError()` factory, called from provider-side streaming implementations that live
  outside this SDK. Nothing in the set above references it, so overriding the bundled copy would be
  pure blast radius for no gain.

## Modifications applied

### `embeddings`

**One prefixed import.** `src/Builders/EmbeddingBuilder.php` imports
`Psr\EventDispatcher\EventDispatcherInterface`, which core scopes as
`WordPress\AiClientDependencies\Psr\EventDispatcher\EventDispatcherInterface`. The vendored copy
uses the prefixed name. This was missed when the feature was first vendored and stayed latent: the
symbol appears only as a nullable typed property and constructor parameter, and PHP does not resolve
such a type while the value is null. Passing a real dispatcher would have raised a TypeError, since
the prefixed interface does not satisfy the unprefixed name.

Otherwise unchanged. Unlike the `Secrets` vendor directory, these files keep their real
`WordPress\AiClient\…` namespace unchanged. That is required: the updated OpenAI/Google/Ollama
provider plugins must implement *this* `EmbeddingGenerationModelInterface` symbol, so it has to be
the canonical class, not a re-namespaced copy.

### `streaming`

**Prefixed PSR/Nyholm imports, and nothing else.** WordPress core scopes the PHP AI Client's PSR-7
dependencies, so `Nyholm\Psr7\Stream` is only reachable as
`WordPress\AiClientDependencies\Nyholm\Psr7\Stream` and `Psr\Http\Message\StreamInterface` only as
`WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface`. The unprefixed names do not exist
under a WordPress bootstrap, so the upstream imports would fatal. Exactly three files carried such
an import, and only the `use` line was touched in each:

| File | Upstream import | Vendored import |
| --- | --- | --- |
| `src/Providers/Http/DTO/Response.php` | `Nyholm\Psr7\Stream` | `WordPress\AiClientDependencies\Nyholm\Psr7\Stream` |
| `src/Providers/Http/DTO/Response.php` | `Psr\Http\Message\StreamInterface` | `WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface` |
| `src/Providers/Http/Streaming/SseEventStreamParser.php` | `Psr\Http\Message\StreamInterface` | `WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface` |
| `src/Providers/Http/Streaming/Contracts/EventStreamParserInterface.php` | `Psr\Http\Message\StreamInterface` | `WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface` |

Class bodies are otherwise byte-identical to upstream. `SDK_OverlayTest` asserts that no vendored
file imports an unprefixed `Nyholm\…` or `Psr\…` symbol, so a future re-vendor cannot silently
drop the rewrite. The assertion deliberately covers every `Psr\` namespace rather than `Psr\Http\`
alone: core prefixes `Psr\EventDispatcher\` and `Psr\SimpleCache\` the same way, and the narrower
pattern it replaced had let an unprefixed `Psr\EventDispatcher\` import sit in this tree unnoticed.

This does mean the `streaming` overlay targets an environment whose SDK dependencies are prefixed
the way core prefixes them. Against a Composer-installed, unprefixed `php-ai-client` the only
casualty is `Response::getStream()`; the buffered path (`getBody()`, `getData()`, `toArray()`) is
unaffected, because PHP resolves those type names lazily and `instanceof` against an unknown class
is simply false.

## How it loads

`includes/SDK_Overlay.php` decides per feature whether to `defer`, `skip`, or `activate`, and
serves **only the classes of the features that activated** from a single prepended autoloader.
Every other `WordPress\AiClient\…` class falls through to the environment's own autoloader. A
shared base-SDK precondition (`WordPress\AiClient\AiClient` must be present) gates the whole
overlay, since the vendored classes extend base-SDK classes we do not ship. See that file for the
detection, conflict-guard, and fall-through logic.

The overlay lives outside this directory on purpose: it is first-party code, and everything under
`includes/Vendor/` is exempt from PHPCS and PHPStan.

**Detection is lazy.** The autoloader registers at bootstrap, but which features activate is not
decided until the first `WordPress\AiClient\…` class is actually autoloaded, so a request that
never touches the SDK pays nothing. Probing at bootstrap would force-load the environment's own
copy of each sentinel class on every request. While probing, the overlay autoloader makes itself
inert so a probe is answered by the environment, never by our own copy.

## Adding a feature

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
`phpstan.neon.dist` excludes `includes/Vendor/AiClient/src/` from scanning). They are also excluded
from coverage reporting, for the same reason: upstream code carries paths this plugin never calls,
so scoring it misreports how well *this* plugin is tested. The loader itself,
`includes/SDK_Overlay.php`, is first-party code and is fully linted and analysed.

**One first-party exception, for the `streaming` bridge.** Three classes are excluded from PHPStan
in `phpstan.neon.dist`:

- `includes/Experiments/AI_Workspace/Streaming/Streaming_Http_Transporter.php`
- `includes/Experiments/AI_Workspace/Streaming/Anthropic_Streaming_Text_Generation_Model.php`
- `includes/Experiments/AI_Workspace/Streaming/Streaming_Turn_Driver.php`

They implement and extend SDK and provider-plugin symbols that are not resolvable in the analysis
environment, which PHPStan reports as non-ignorable errors — the kind a per-line ignore cannot
suppress. The exclusion is therefore file-scoped and deliberate, not a convenience. It is the only
first-party code in the plugin that is not analysed, so treat any addition to that list as a
decision rather than a formality, and drop a file from it once its symbols become resolvable.
