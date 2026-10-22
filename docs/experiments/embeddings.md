# Embeddings: Architecture and Developer API

Embeddings are the shared foundation other features build on — semantic search, "related content," frontend chat, and any other feature that needs to compare pieces of content by meaning rather than keywords. This plugin does not ship such a feature itself; it provides the building blocks: generating vectors through the PHP AI Client, storing and retrieving them, and comparing them.

The pieces, in the order a request usually touches them:

1. **Generate** — `generate_embeddings()` turns text into a vector, via a specific provider and model.
2. **Store / retrieve** — `WordPress\AI\Embeddings\Embedding_Repository` persists vectors in a dedicated table and reads them back, scoped to the object and model that produced them.
3. **Compare** — `Vector_Math` and `Vector_Ranker` score and rank vectors against each other, entirely in PHP, with no further provider calls.

None of the three steps requires the others. Code that already has two vectors can skip straight to comparing them; code that only needs to check whether an object is stale can read a content hash without ever touching a vector.

## Generating embeddings

`generate_embeddings()` (`includes/helpers.php`) is the entry point:

```php
use function WordPress\AI\generate_embeddings;

$result = generate_embeddings(
	'The quick brown fox jumps over the lazy dog',
	array(
		'provider' => 'openai',
		'model'    => 'text-embedding-3-small',
	)
);

if ( is_wp_error( $result ) ) {
	return $result;
}

$vector   = $result->getEmbeddings()[0]->getValues(); // list<float>
$provider = $result->getProviderMetadata()->getId();  // 'openai'
$model    = $result->getModelMetadata()->getId();     // 'text-embedding-3-small'
```

`$input` accepts a single string or a list of strings; a list produces one `Embedding` per input, in the same order, inside a single `EmbeddingResult`. Batching several inputs into one call is one request to the provider instead of several.

**A model is always required, and nothing is chosen on your behalf.** Unlike the text-generation builders elsewhere in the plugin, there is no provider/model fallback list for embeddings. This is deliberate: vectors are only comparable to other vectors from the same model, so a stored corpus is permanently bound to whatever model indexed it. Silently falling back to a different model would produce vectors nothing else in the index can be compared against, with no error to say so. `generate_embeddings()` returns a `WP_Error` (`ai_embeddings_missing_model` / `ai_embeddings_missing_provider`) rather than guessing.

`$args` accepts:

| Key | Type | Required | Notes |
| --- | --- | --- | --- |
| `model` | `string` \| `ModelInterface` | Yes | A model ID (with `provider`) or an already-resolved model instance. |
| `provider` | `string` | Only when `model` is a string | The connector/provider ID that offers the model. Ignored when `model` is an instance. |
| `dimensions` | `int` | No | Requests a shorter vector from models that support it. See [One dimension count per model](#one-dimension-count-per-model) before mixing dimensions for the same model. |

Under the hood, `generate_embeddings()` is a thin wrapper around `WordPress\AiClient\Builders\EmbeddingBuilder` from the PHP AI Client SDK: it builds the request, resolves the model through the provider registry, and calls `generateEmbeddingResult()`. Code that needs more control — multiple inputs with per-call configuration, or a lifecycle event listener (see [Lifecycle events](#lifecycle-events) below) — can use `EmbeddingBuilder` directly instead of the helper; `generate_embeddings()` exists to cover the common case of "one or more strings, one model, one result."

Any exception from the SDK or the provider is caught and returned as a `WP_Error` (`ai_embeddings_failed`) rather than propagating, so callers only need to check `is_wp_error()`.

### How providers and models connect

`generate_embeddings()` resolves the model through `AiClient::defaultRegistry()`, the same provider registry used by the plugin's other AI features. A provider becomes available to it by registering as a connector of type `ai_provider` (visible via `wp_get_connectors()` / the plugin's own `get_ai_connectors()` helper) with valid credentials — this is what connector plugins such as `ai-provider-for-google` do. The registry is what `usingProviderModel( $provider, $model )` looks the pair up in; `usingModel( $model_instance )` skips that lookup for a model object the caller already has (for example from `AiClient::defaultRegistry()->getProviderModel( $provider, $model )` directly).

Whether a given model actually supports embeddings is a property of the model class, not of the request: a model implements `WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface` only if its provider connector declares embedding support for it. Asking for a model that does not implement it, a provider that is not registered, or a provider that is registered but not configured with valid credentials all surface the same way: an `InvalidArgumentException` from the SDK, which `generate_embeddings()` turns into a `WP_Error`. There is no separate "does this provider support embeddings" check to call first — attempting generation **is** the check.

### Lifecycle events

The SDK dispatches `WordPress\AiClient\Events\BeforeGenerateEmbeddingEvent` and `AfterGenerateEmbeddingEvent` from inside `EmbeddingBuilder::generateEmbeddingResult()`, carrying the resolved model, the inputs, and (after generation) the result. These are plain PSR event objects dispatched through an `EventDispatcherInterface` passed into the builder's constructor — **not** WordPress `do_action()` hooks.

This matters in practice: `generate_embeddings()` constructs its `EmbeddingBuilder` without an event dispatcher, so calling it never fires these events. They only run for code that builds its own `EmbeddingBuilder` and supplies a dispatcher:

```php
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\EmbeddingBuilder;

$builder = new EmbeddingBuilder( AiClient::defaultRegistry(), $text, $my_event_dispatcher );
$builder->usingProviderModel( 'openai', 'text-embedding-3-small' );
$result = $builder->generateEmbeddingResult();
```

If a feature needs to observe or short-circuit embedding generation, go through `EmbeddingBuilder` directly with a dispatcher rather than `generate_embeddings()`.

### Implementation note: the SDK overlay

The `EmbeddingBuilder` class and its supporting types (`ModelRequirements`, `EmbeddingGenerationModelInterface`, `Embedding`, `EmbeddingResult`, the two lifecycle events, and related classes) are not always loaded from this plugin. `includes/SDK_Overlay.php` registers a conditional autoloader that serves the plugin's vendored copies of these classes **only when the environment's own PHP AI Client SDK does not already provide them** (detected via a sentinel class, `WordPress\AiClient\Builders\EmbeddingBuilder` itself). When WordPress core or another plugin ships an SDK version that already includes embeddings, the overlay defers entirely and those classes come from there instead.

In practice this is transparent — `use WordPress\AiClient\...` resolves to a working class either way — but it explains two things a developer debugging this area might otherwise find surprising: `includes/Vendor/AiClient/src/...` is not always what actually runs, and the vendored and environment-provided copies are forward-compatible, not two different APIs.

## Storing and retrieving embeddings

The `WordPress\AI\Embeddings` namespace provides a portable storage layer for embedding vectors. It is persistence only: generating vectors is the job described above, and similarity search is built on top of it by higher-level code.

Vectors are stored in the `wpai_embeddings` table, one row per `(object_type, object_id, provider, model, chunk_index)`. Embedding vectors are only comparable to other vectors produced by the same model, so the provider and model are part of every record's identity — an index can never be queried with vectors from a different model by accident. The table is created on the first write, never by a read.

### What identifies a row

`object_type` follows core's own vocabulary for the kinds of thing WordPress can attach data to — `post`, `term`, `user`, `comment` — and `object_subtype` narrows it to the specific post type or taxonomy: `page`, `product`, `post_tag`, `category`. Post types and taxonomies share a name space, so `product` on its own is ambiguous; the pair is not.

**Always pass the subtype when you save.** It is not part of the row's identity, so nothing breaks if you leave it empty — but it is the only thing that lets a later query count coverage per post type or per taxonomy ("48,102 of 50,000 tags indexed") without joining `wp_posts` or `wp_term_taxonomy`. Reconstructing it afterwards means reading every object back, and an object deleted in the meantime cannot be reconstructed at all. Passing it costs one argument at write time and saves an unrecoverable backfill later.

`object_subtype` is an attribute rather than part of the key, so re-embedding an object whose subtype changed — a post converted to a page, a term moved between taxonomies — replaces the row and updates the subtype rather than stranding the old one.

```php
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use function WordPress\AI\generate_embeddings;

$repository = new Embedding_Repository();

$result = generate_embeddings( $text, array(
	'provider' => 'ollama',
	'model'    => 'nomic-embed-text:latest',
) );

if ( is_wp_error( $result ) ) {
	return $result;
}

// Take the provider and model from the result rather than from the request. They are part of the
// row's identity, so they have to name what actually produced the vector — and when a model
// instance is passed instead of an ID, the caller never named a provider in the first place.
$repository->save(
	new Embedding_Record(
		'post',                                        // object_type
		42,                                            // object_id
		$result->getProviderMetadata()->getId(),       // resolved provider
		$result->getModelMetadata()->getId(),          // resolved model
		$result->getEmbeddings()[0]->getValues(),      // the vector
		0,                                             // chunk_index
		hash( 'sha256', $text ),                       // content_hash, covering the whole object
		0,                                             // row ID: 0 until stored
		(string) get_post_type( 42 )                   // object_subtype
	)
);

// Read it back — always scoped to the model that produced it.
$records = $repository->get( 'post', 42, 'ollama', 'nomic-embed-text:latest' );

// Cheap staleness check before re-embedding.
$stale = $repository->get_content_hash( 'post', 42, 'ollama', 'nomic-embed-text:latest' ) !== hash( 'sha256', $text );

// Bounded, newest-first lookup of indexed objects, and a batched scan over every vector for a model.
$post_ids = $repository->get_object_ids( 'post', 'ollama', 'nomic-embed-text:latest', 500 );
foreach ( $repository->iterate( 'ollama', 'nomic-embed-text:latest', 'post' ) as $record ) {
	// $record->get_vector(), $record->get_object_id() …
}

// Switching models means re-indexing. Provider and model are part of every row's identity, so
// index the new model alongside the old one and only drop the old vectors once coverage is
// complete — the existing index keeps serving results for the whole backfill.
foreach ( $post_ids as $post_id ) {
	$repository->save(
		new Embedding_Record( 'post', $post_id, 'ollama', 'mxbai-embed-large:latest', $new_vector )
	);
}

// Cut over only after the new model covers everything.
$repository->delete_for_model( 'ollama', 'nomic-embed-text:latest' );
```

Longer content can be stored as several chunks of the same object by using `chunk_index`; `get()` returns them in chunk order. The content hash is object-level rather than chunk-level: every chunk of one object stores the same hash, covering the whole source content, so `get_content_hash()` answers "is this object stale" for the object as a whole.

Vectors are packed as little-endian float32 bytes (see `Vector_Codec`), the same layout MariaDB's native `VECTOR` type uses, so a backend with a native vector index can implement `Embedding_Repository_Interface` against the same data. Alongside each vector the row also stores `embedding_coarse`, a binary quantization code of one bit per component — 192 bytes for a 1536-dimension vector against the 6,144 of its float32 form. Nothing in this layer reads it; it exists so that a similarity search can rank every candidate cheaply on the small code and then rescore only a shortlist against the exact vectors. It is written for you on save and can always be recomputed from the vector, so it needs no handling by callers.

Deleting is scoped the same way reads are: `delete_for_object()` removes an object's vectors (optionally narrowed to one provider and/or model), and `delete_for_model()` removes every vector a model produced — the operation a model switch's cutover step above ends with.

### One dimension count per model

`generate_embeddings()` accepts a `dimensions` argument, so the same `(provider, model)` pair can legitimately produce vectors of two different lengths. The storage layer allows that — `dimensions` is an attribute of the row, not part of its key, so a shorter vector replaces a longer one for the same object rather than sitting beside it.

What it does not do is make those vectors comparable. A similarity pass has to compare equal-length vectors, and their `embedding_coarse` codes differ in byte length too, so **a search must scope its scan to one dimension count** as well as to one provider and model. If you index a site at one dimensionality, keep it there for that model, or treat each dimensionality as a separate index.

## Comparing vectors

Two classes beside the storage layer do the arithmetic. `Vector_Math` has the pure functions — `cosine_similarity()`, `dot_product()`, `euclidean_distance()`, `norm()`, `normalize()` and `centroid()` — and `Vector_Ranker::rank()` scores a query against a keyed set of candidates and returns them best first. Both work on plain lists of floats, so `Embedding_Record::get_vector()` and the SDK's `Embedding::getValues()` can be passed straight in. Neither reads the database.

Stored vectors are **not** normalized on write, so compare them with `cosine_similarity()`, which divides by both norms. Use `dot_product()` only when you know both sides are unit length — for example after `normalize()`.

```php
use WordPress\AI\Embeddings\Vector_Math;
use WordPress\AI\Embeddings\Vector_Ranker;

// How alike are two stored objects?
$a = $repository->get( 'post', 42, 'openai', 'text-embedding-3-small' )[0]->get_vector();
$b = $repository->get( 'post', 99, 'openai', 'text-embedding-3-small' )[0]->get_vector();

$similarity = Vector_Math::cosine_similarity( $a, $b ); // 1.0 identical direction, 0.0 unrelated.

// Related content: rank candidate vectors keyed by post ID, keep the top five.
$candidates = array();
foreach ( $records as $record ) {
	$candidates[ $record->get_object_id() ] = $record->get_vector();
}

$related = Vector_Ranker::rank( $a, $candidates, Vector_Math::METRIC_COSINE, 5 );
// => array( 99 => 0.91, 17 => 0.87, … ), best first, keys preserved.
```

All vectors passed together must have the same dimension count — and therefore come from the same provider, model and `dimensions` setting. A mismatch throws an `InvalidArgumentException` rather than returning a low score, and so does a zero vector under cosine similarity, which has no direction to compare.

### Classifying with centroids

`centroid()` turns a handful of labelled examples into one prototype vector per label; ranking the prototypes against a new vector is a nearest-centroid classifier with no training step.

```php
$prototypes = array(
	'sports'  => Vector_Math::centroid( $sports_example_vectors ),
	'cooking' => Vector_Math::centroid( $cooking_example_vectors ),
	'travel'  => Vector_Math::centroid( $travel_example_vectors ),
);

$best = Vector_Ranker::rank( $new_post_vector, $prototypes, Vector_Math::METRIC_COSINE, 1 );
$label = array_key_first( $best ); // 'sports'
```

`Vector_Ranker` sorts cosine and dot product descending and Euclidean distance ascending, so the first key is always the best match whichever metric you choose.

## Trying it from WP-CLI

`wp ai embeddings generate` and `wp ai embeddings compare` (`includes/CLI/Embeddings_Command.php`) exercise the whole path above without writing any PHP — useful for checking a provider/model works, or for seeding a couple of stored vectors to compare while developing a feature on top of this foundation.

```bash
# Generate and store vectors for a post's content, chunked. --dry-run defaults to true, so it
# must be turned off explicitly to actually call the provider and store anything.
wp ai embeddings generate --post-id=42 --chunk --provider=ollama --model=nomic-embed-text:latest --dry-run=false

# Compare two posts already embedded with the same model.
wp ai embeddings compare 42 99 --metric=all
```

`generate` requires `--provider` and `--model` for the same reason `generate_embeddings()` does — see [Generating embeddings](#generating-embeddings) above. `compare` reads stored vectors via `Embedding_Repository` and scores them via `Vector_Math`/`Vector_Ranker`, so it is a working example of steps 2 and 3 of the pipeline, end to end, against real stored data; it also rejects two posts stored at different dimension counts rather than comparing them, which is `Vector_Math`'s own `Vectors must have the same number of dimensions` requirement surfaced as a CLI error. Run either command with `--help` for the full set of options (`--dry-run`, `--chunk`, `--metric`, `--pairs`, etc.).
