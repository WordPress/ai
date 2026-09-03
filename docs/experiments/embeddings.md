# Storing Embeddings

The `WordPress\AI\Embeddings` namespace provides a portable storage layer for embedding vectors. It is persistence only: generating vectors is the job of the AI Client, and similarity search is built on top of it by higher-level code.

Vectors are stored in the `wpai_embeddings` table, one row per `(object_type, object_id, provider, model, chunk_index)`. Embedding vectors are only comparable to other vectors produced by the same model, so the provider and model are part of every record's identity — an index can never be queried with vectors from a different model by accident. The table is created on the first write, never by a read.

## What identifies a row

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

### One dimension count per model

`generate_embeddings()` accepts a `dimensions` argument, so the same `(provider, model)` pair can legitimately produce vectors of two different lengths. The storage layer allows that — `dimensions` is an attribute of the row, not part of its key, so a shorter vector replaces a longer one for the same object rather than sitting beside it.

What it does not do is make those vectors comparable. A similarity pass has to compare equal-length vectors, and their `embedding_coarse` codes differ in byte length too, so **a search must scope its scan to one dimension count** as well as to one provider and model. If you index a site at one dimensionality, keep it there for that model, or treat each dimensionality as a separate index.
