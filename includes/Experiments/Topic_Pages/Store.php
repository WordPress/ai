<?php
/**
 * Reviewed Topic Page storage and publication.
 *
 * @since x.x.x
 * @package WordPress\AI
 */
declare( strict_types=1 );
namespace WordPress\AI\Experiments\Topic_Pages;

use WP_Error;

/**
 * Keeps pending work separate from public content.
 *
 * @since x.x.x
 */
final class Store {
	public const META_KEY       = '_wpai_topic_page';
	public const SECTIONS       = array( 'summary', 'overview' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- One array constant.
	public const ALLOWED_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- One array constant.

	/**
	 * Returns normalized editorial state without rewriting storage.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @return array<string,mixed> Editorial state.
	 */
	public function get( int $term_id ): array {
		$raw      = get_term_meta( $term_id, self::META_KEY, true );
		$sections = array();
		$nested   = is_array( $raw ) && isset( $raw['sections'] ) && is_array( $raw['sections'] );
		foreach ( self::SECTIONS as $name ) {
			$item     = $nested ? ( $raw['sections'][ $name ] ?? null ) : null;
			$approved = is_array( $item ) ? $this->version( $item['approved'] ?? null ) : null;
			if ( ! $nested && is_array( $raw ) && isset( $raw[ $name ] ) && is_array( $raw[ $name ] ) ) {
				$old               = $raw[ $name ];
				$old['source_ids'] = $old['sources'] ?? array();
				// Earlier rich text sections used ordinary HTML rather than block delimiters.
				if ( isset( $old['content'] ) && is_string( $old['content'] ) && ! has_blocks( $old['content'] ) ) {
					$old['content'] = '<!-- wp:paragraph -->' . $old['content'] . '<!-- /wp:paragraph -->';
				}
				$approved = $this->version( $old );
			}
			$sections[ $name ] = array(
				'approved' => $approved,
				'draft'    => is_array( $item ) ? $this->version( $item['draft'] ?? null ) : null,
			);
		}
		return array(
			'revision' => '' === $raw ? '' : hash( 'sha256', (string) wp_json_encode( $raw ) ),
			'sections' => $sections,
		);
	}

	/**
	 * Returns state with current public visibility and source freshness.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @return array<string,mixed> Editor state.
	 */
	public function state( int $term_id ): array {
		$state            = $this->get( $term_id );
		$sources          = new Sources();
		$generation_error = '';
		$state['hidden']  = array();
		$state['stale']   = array();
		foreach ( $state['sections'] as $name => $item ) {
			$records = $sources->collect( $term_id, $name );
			if ( is_wp_error( $records ) ) {
				$generation_error = $records->get_error_message();
			}
			$fingerprint = is_wp_error( $records ) ? '' : hash( 'sha256', (string) wp_json_encode( $records ) );
			if ( $item['draft'] ) {
				$reviewed = $this->review( $term_id, $item['draft'] );
				$state['sections'][ $name ]['draft']['preview_html'] = is_wp_error( $reviewed ) ? '' : wp_kses_post( do_blocks( $reviewed['content'] ) );
			}
			if ( $item['approved'] ) {
				$preview = $this->render( $term_id, $name );
				$state['sections'][ $name ]['approved']['preview_html'] = $preview;
				if ( '' === $preview ) {
					$state['hidden'][] = $name;
				}
			}
			$version = $item['draft'] ?? $item['approved'];
			if ( ! $version || ( '' !== $fingerprint && ( $version['fingerprint'] ?? '' ) === $fingerprint ) ) {
				continue;
			}

			$state['stale'][] = $name;
		}
		$state['generationError'] = $generation_error;
		return $state;
	}

	/**
	 * Saves generated sections as private previews.
	 *
	 * @param int $term_id Category ID.
	 * @param array<string,mixed> $versions Generated versions.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	public function save_generated( int $term_id, array $versions, string $revision ) {
		return $this->write( $term_id, 'generate', 'all', $versions, $revision );
	}

	/**
	 * Publishes or discards an existing preview; raw edits are never accepted.
	 *
	 * @param int $term_id Category ID.
	 * @param string $action Save or discard.
	 * @param string $section Section name or all.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	public function change( int $term_id, string $action, string $section, string $revision ) {
		if ( ! in_array( $action, array( 'save', 'discard' ), true ) ) {
			return new WP_Error( 'topic_invalid_action', __( 'Choose a valid topic action.', 'ai' ) );
		}
		return $this->write( $term_id, $action, $section, array(), $revision );
	}

	/**
	 * Validates the complete transition and prevents competing requests from overwriting it.
	 *
	 * @param int $term_id Category ID.
	 * @param string $action Generate, save, or discard.
	 * @param string $section Target section or all.
	 * @param array<string,mixed> $versions Generated versions, empty for save/discard.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	private function write( int $term_id, string $action, string $section, array $versions, string $revision ) {
		$lock  = new Lock();
		$owner = $lock->acquire( $term_id, 'save' );
		if ( false === $owner ) {
			return $this->conflict();
		}
		try {
			wp_cache_delete( $term_id, 'term_meta' );
			$raw   = get_term_meta( $term_id, self::META_KEY, true );
			$state = $this->get( $term_id );
			if ( ! hash_equals( $state['revision'], $revision ) ) {
				return $this->conflict();
			}
			if ( 'all' !== $section && ! in_array( $section, self::SECTIONS, true ) ) {
				return new WP_Error( 'topic_invalid_section', __( 'Choose a valid topic section.', 'ai' ) );
			}
			$targets = 'all' === $section ? self::SECTIONS : array( $section );
			if ( 'generate' === $action ) {
				if ( ! $versions || array_diff( array_keys( $versions ), $targets ) ) {
					return $this->invalid();
				}
				foreach ( $versions as $name => $value ) {
					$version = $this->version( $value );
					if ( ! $version ) {
						return $this->invalid();
					}
					// A provider call can outlive another request's edits. Recheck fresh source data.
					foreach ( $version['source_ids'] as $source_id ) {
						clean_post_cache( $source_id );
					}
					$term = ( new Sources() )->term( $term_id );
					if ( ! is_wp_error( $term ) ) {
						Sources::invalidate( array( $term_id ), $term->taxonomy );
					}
					$records = ( new Sources() )->collect( $term_id, $name );
					if ( is_wp_error( $records ) ) {
						return $records;
					}
					if ( isset( $version['fingerprint'] ) && ! hash_equals( hash( 'sha256', (string) wp_json_encode( $records ) ), $version['fingerprint'] ) ) {
						return new WP_Error( 'topic_sources_changed', __( 'Source articles changed during generation. Regenerate to use the latest articles. Your published text has not changed.', 'ai' ), array( 'status' => 409 ) );
					}
					$validated = $this->review( $term_id, $version );
					if ( is_wp_error( $validated ) ) {
						return $validated;
					}
					$state['sections'][ $name ]['draft'] = $validated;
				}
			} else {
				$published = false;
				foreach ( $targets as $name ) {
					if ( 'discard' === $action ) {
						$state['sections'][ $name ]['draft'] = null;
						continue;
					}
					$draft = $state['sections'][ $name ]['draft'];
					if ( ! $draft ) {
						continue;
					}
					$validated = $this->review( $term_id, $draft );
					if ( is_wp_error( $validated ) || ! $this->can_display( $term_id, $validated ) ) {
						return $this->invalid();
					}
					$state['sections'][ $name ]['approved'] = $validated;
					$state['sections'][ $name ]['draft']    = null;
					$published                              = true;
				}
				if ( 'save' === $action && ! $published ) {
					return new WP_Error( 'topic_no_draft', __( 'There are no changes to save.', 'ai' ) );
				}
			}
			// Preserve retired timeline text in storage without exposing it in the review screen.
			if ( is_array( $raw ) ) {
				$old_sections = is_array( $raw['sections'] ?? null ) ? $raw['sections'] : $raw;
				if ( array_key_exists( 'timeline', $old_sections ) ) {
					$state['sections']['timeline'] = $old_sections['timeline'];
				}
			}
			$state['revision'] = wp_generate_uuid4();
			$saved             = metadata_exists( 'term', $term_id, self::META_KEY )
				? update_term_meta( $term_id, self::META_KEY, wp_slash( $state ), $raw )
				: add_term_meta( $term_id, self::META_KEY, wp_slash( $state ), true );
			return $saved && ! is_wp_error( $saved ) ? $this->get( $term_id ) : $this->conflict();
		} finally {
			$lock->release( $term_id, 'save', $owner );
		}
	}

	/**
	 * Renders approved, source-validated static blocks only.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @param string $section Section name.
	 * @return string Public content or an empty string.
	 */
	public function render( int $term_id, string $section ): string {
		$version = $this->get( $term_id )['sections'][ $section ]['approved'] ?? null;
		if ( ! $version || ! $this->can_display( $term_id, $version ) ) {
			return '';
		}
		$version = $this->review( $term_id, $version );
		return is_wp_error( $version ) ? '' : wp_kses_post( do_blocks( $version['content'] ) );
	}

	/**
	 * Checks the current public availability of a version's source context.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @param array<string,mixed> $version Saved version.
	 * @return bool Whether the version may be published.
	 */
	public function can_display( int $term_id, array $version ): bool {
		$sources = new Sources();
		$term    = $sources->term( $term_id );
		if ( is_wp_error( $term ) || ! $this->version( $version ) || ! $sources->eligible( $term_id ) ) {
			return false;
		}
		foreach ( $version['source_ids'] as $id ) {
			if ( ! $sources->is_public_source( $id, $term ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validates and sanitizes actual citations while retaining generation context.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @param array<string,mixed> $version Draft or approved content.
	 * @return array<string,mixed>|\WP_Error Validated version or error.
	 */
	private function review( int $term_id, array $version ) {
		if ( strlen( $version['content'] ) > 100000 || '' === trim( wp_strip_all_tags( $version['content'] ) ) || ! $this->valid_blocks( parse_blocks( $version['content'] ) ) ) {
			return $this->invalid();
		}
		$sources = new Sources();
		$term    = $sources->term( $term_id );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$tags = new \WP_HTML_Tag_Processor( wp_kses_post( $version['content'] ) );
		$ids  = array();
		// Older versions had no explicit IDs on their links. Remember known URLs before normalizing.
		$urls = is_array( $version['source_urls'] ?? null ) ? $version['source_urls'] : array();
		foreach ( $version['source_ids'] as $id ) {
			$urls[ $id ] = $urls[ $id ] ?? get_permalink( $id );
		}
		while ( $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$reference = $tags->get_attribute( 'data-wpai-source-id' );
			$href      = $tags->get_attribute( 'href' );
			$id        = is_string( $reference ) && ctype_digit( $reference ) ? (int) $reference : (int) array_search( $href, $urls, true );
			if ( ! $id && is_string( $href ) ) {
				$id = url_to_postid( $href ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- The core resolver is required outside VIP and only handles unresolved edited citations.
			}
			if ( ! $id || ! $sources->is_public_source( $id, $term ) ) {
				return $this->invalid();
			}
			$tags->set_attribute( 'href', (string) get_permalink( $id ) );
			$tags->set_attribute( 'data-wpai-source-id', (string) $id );
			$urls[ $id ] = get_permalink( $id );
			$ids[]       = $id;
		}
		if ( ! $ids ) {
			return $this->invalid();
		}
		$version['content']     = $tags->get_updated_html();
		$version['source_ids']  = array_values( array_unique( array_merge( $version['source_ids'], $ids ) ) );
		$version['source_urls'] = $urls;
		return $version;
	}

	/**
	 * Checks the shape of stored versions before they reach typed consumers.
	 *
	 * @since x.x.x
	 * @param mixed $value Stored version.
	 * @return array<string,mixed>|null A structurally valid version.
	 */
	private function version( $value ): ?array {
		return is_array( $value ) && isset( $value['content'], $value['source_ids'] ) && is_string( $value['content'] ) && is_array( $value['source_ids'] ) && ! empty( $value['source_ids'] ) && count( array_filter( $value['source_ids'], 'is_int' ) ) === count( $value['source_ids'] ) ? $value : null;
	}

	/**
	 * Rejects unsupported blocks and dynamic bindings, including nested content.
	 *
	 * @since x.x.x
	 * @param array<mixed> $blocks Parsed blocks.
	 * @return bool Whether every block is supported.
	 */
	private function valid_blocks( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) {
				continue;
			}
			if ( ! in_array( $block['blockName'], self::ALLOWED_BLOCKS, true ) || ! empty( $block['attrs']['metadata']['bindings'] ) || ! $this->valid_blocks( $block['innerBlocks'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns a recoverable review error without changing storage.
	 *
	 * @since x.x.x
	 * @return \WP_Error Validation error.
	 */
	private function invalid(): WP_Error {
		return new WP_Error( 'topic_invalid_content', __( 'This preview has invalid content or sources. Regenerate the section before saving. Your published text has not changed.', 'ai' ), array( 'status' => 400 ) );
	}

	/**
	 * Returns a conflict without overwriting another request's work.
	 *
	 * @since x.x.x
	 * @return \WP_Error Conflict error.
	 */
	private function conflict(): WP_Error {
		return new WP_Error( 'topic_conflict', __( 'This topic changed in another request. Load the latest version before continuing. Your published text has not changed.', 'ai' ), array( 'status' => 409 ) );
	}
}
