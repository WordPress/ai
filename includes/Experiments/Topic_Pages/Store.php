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
	public const SECTIONS       = array( 'summary', 'overview', 'timeline' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- One array constant.
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
				if ( 'timeline' === $name && isset( $old['entries'] ) && is_array( $old['entries'] ) ) {
					$old['content'] = '';
					foreach ( $old['entries'] as $entry ) {
						if ( ! is_array( $entry ) || ! isset( $entry['post_id'], $entry['description'] ) || ! is_int( $entry['post_id'] ) || ! is_string( $entry['description'] ) ) {
							continue;
						}

						$old['content'] .= '<!-- wp:paragraph --><p>' . esc_html( $entry['description'] ) . ' <a data-wpai-source-id="' . $entry['post_id'] . '" href="' . esc_url( (string) get_permalink( $entry['post_id'] ) ) . '">' . esc_html( (string) get_the_date( '', $entry['post_id'] ) ) . '</a></p><!-- /wp:paragraph -->';
					}
				}
				// Earlier rich text sections used ordinary HTML rather than block delimiters.
				if ( isset( $old['content'] ) && is_string( $old['content'] ) && ! has_blocks( $old['content'] ) ) {
					$old['content'] = '<!-- wp:paragraph -->' . $old['content'] . '<!-- /wp:paragraph -->';
				}
				$approved = $this->version( $old );
			}
			$sections[ $name ] = array(
				'approved' => $approved,
				'draft'    => is_array( $item ) ? $this->version( $item['draft'] ?? null ) : null,
				'locked'   => is_array( $item ) && ! empty( $item['locked'] ),
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
		$state           = $this->get( $term_id );
		$sources         = ( new Sources() )->collect( $term_id );
		$fingerprint     = is_wp_error( $sources ) ? '' : hash( 'sha256', (string) wp_json_encode( $sources ) );
		$state['hidden'] = array();
		$state['stale']  = array();
		foreach ( $state['sections'] as $name => $item ) {
			if ( $item['approved'] ) {
				$preview = $this->render( $term_id, $name );
				$state['sections'][ $name ]['approved']['preview_html'] = $preview;
				if ( '' === $preview ) {
					$state['hidden'][] = $name; }
			}
			$version = $item['draft'] ?? $item['approved'];
			if ( ! $version || ( '' !== $fingerprint && ! ( ( $version['fingerprint'] ?? '' ) !== $fingerprint ) ) ) {
				continue;
			}

			$state['stale'][] = $name;
		}
		$state['generationError'] = is_wp_error( $sources ) ? $sources->get_error_message() : '';
		return $state;
	}

	/**
	 * Saves generated sections as drafts without publishing them.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @param array<string,mixed> $versions Generated versions.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	public function save_generated( int $term_id, array $versions, string $revision ) {
		return $this->write( $term_id, 'generate', 'all', array( 'versions' => $versions ), $revision );
	}

	/**
	 * Applies a save, publication, or lock transition in one write.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @param string $action Editorial action.
	 * @param string $section Section name or all.
	 * @param array<string,mixed> $input Action input.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	public function change( int $term_id, string $action, string $section, array $input, string $revision ) {
		if ( ! in_array( $action, array( 'save', 'publish', 'approve', 'lock' ), true ) ) {
			return new WP_Error( 'topic_invalid_action', __( 'Choose a valid topic action.', 'ai' ) );
		}
		return $this->write( $term_id, $action, $section, $input, $revision );
	}

	/**
	 * Serializes competing writes and validates the complete transition before persistence.
	 *
	 * @since x.x.x
	 * @param int $term_id Topic term ID.
	 * @param string $action Action name.
	 * @param string $section Target section or all.
	 * @param array<string,mixed> $input Action input.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	private function write( int $term_id, string $action, string $section, array $input, string $revision ) {
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
			if ( 'lock' === $action ) {
				if ( 'all' === $section || ! isset( $input['locked'] ) || ! is_bool( $input['locked'] ) ) {
					return new WP_Error( 'topic_invalid_section', __( 'Choose one section to lock.', 'ai' ) );
				}
				$state['sections'][ $section ]['locked'] = ! empty( $input['locked'] );
			} else {
				$versions = 'generate' === $action ? ( $input['versions'] ?? array() ) : ( $input['edits'] ?? array() );
				if ( isset( $input['content'] ) && 'all' !== $section ) {
					$versions = array( $section => $input['content'] );
				}
				if ( ! is_array( $versions ) || ( in_array( $action, array( 'save', 'generate' ), true ) && empty( $versions ) ) || ( 'all' === $section && isset( $input['content'] ) ) ) {
					return $this->invalid();
				}
				foreach ( $versions as $name => $value ) {
					if ( ! in_array( $name, $targets, true ) ) {
						return $this->invalid();
					}
					$version = 'generate' === $action ? $this->version( $value ) : ( $state['sections'][ $name ]['draft'] ?? $state['sections'][ $name ]['approved'] );
					if ( ! $version || ( 'generate' !== $action && ! is_string( $value ) ) ) {
						return $this->invalid();
					}
					if ( 'generate' !== $action ) {
						$version['content']                   = $value;
						$state['sections'][ $name ]['locked'] = true;
					}
					$validated = $this->review( $term_id, $version );
					if ( is_wp_error( $validated ) ) {
						return $validated;
					}
					$state['sections'][ $name ]['draft'] = $validated;
				}
				if ( in_array( $action, array( 'publish', 'approve' ), true ) ) {
					$published = false;
					foreach ( $targets as $name ) {
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
					if ( ! $published ) {
						return new WP_Error( 'topic_no_draft', __( 'There are no changes to publish.', 'ai' ) );
					}
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
		if ( is_wp_error( $term ) || ! $this->version( $version ) || $sources->query( $term, 1 )->found_posts < Sources::minimum() ) {
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
		return new WP_Error( 'topic_invalid_content', __( 'Review the content and link to public articles in this topic. Each section needs at least one source. Nothing has been published.', 'ai' ), array( 'status' => 400 ) );
	}

	/**
	 * Returns a conflict without overwriting another request's work.
	 *
	 * @since x.x.x
	 * @return \WP_Error Conflict error.
	 */
	private function conflict(): WP_Error {
		return new WP_Error( 'topic_conflict', __( 'This topic changed in another request. Reload before continuing. Your unsaved edits are still in this page.', 'ai' ), array( 'status' => 409 ) );
	}
}
