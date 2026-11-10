<?php
/**
 * Reviewed Topic Page storage and publication.
 *
 * @package WordPress\AI
 * @since x.x.x
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

	public const META_KEY     = '_wpai_topic_page';
	public const SUMMARY      = 'summary';
	public const OVERVIEW     = 'overview';
	public const TIMELINE     = 'timeline';
	public const SECTIONS     = array( self::SUMMARY, self::OVERVIEW ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- One array constant.
	public const ALL_SECTIONS = array( self::SUMMARY, self::OVERVIEW, self::TIMELINE ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- One array constant.

	/**
	 * Returns normalized editorial state without rewriting storage.
	 *
	 * @since x.x.x
	 *
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
	 *
	 * @param int $term_id Topic term ID.
	 * @return array<string,mixed> Editor state.
	 */
	public function state( int $term_id ): array {
		$state            = $this->get( $term_id );
		$sources          = new Sources();
		$term             = $sources->eligible_term( $term_id );
		$generation_error = is_wp_error( $term ) ? $term->get_error_message() : '';
		$state['hidden']  = array();
		$state['stale']   = array();

		foreach ( $state['sections'] as $name => $item ) {
			$version = $item['draft'] ?? $item['approved'];

			if ( ! $version ) {
				continue;
			}

			$records = is_wp_error( $term ) ? $term : $sources->collect_for_term( $term, $name );

			if ( is_wp_error( $records ) ) {
				$generation_error = $records->get_error_message();
			}

			$fingerprint = is_wp_error( $records ) ? '' : Sources::fingerprint( $records );

			if ( $item['draft'] ) {
				$reviewed = $this->review( $term_id, $item['draft'], $name );
				$state['sections'][ $name ]['draft']['preview_html'] = is_wp_error( $reviewed ) ? '' : $this->render_content( $reviewed['content'] );
			}

			if ( $item['approved'] ) {
				$preview = $this->render( $term_id, $name );
				$state['sections'][ $name ]['approved']['preview_html'] = $preview;

				if ( '' === $preview ) {
					$state['hidden'][] = $name;
				}
			}

			if ( '' !== $fingerprint && ( $version['fingerprint'] ?? '' ) === $fingerprint ) {
				continue;
			}

			$state['stale'][] = $name;
		}

		$state['generationError'] = $generation_error;

		return $state;
	}

	/**
	 * Saves a generated section as a private preview.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Category ID.
	 * @param string $section Section name.
	 * @param array<string,mixed> $version Generated version.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	public function save_generated( int $term_id, string $section, array $version, string $revision ) {
		return $this->write( $term_id, 'generate', $section, $version, $revision );
	}

	/**
	 * Publishes or discards an existing preview; raw edits are never accepted.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Category ID.
	 * @param string $action Save or discard.
	 * @param string $section Section name.
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
	 * Validates a transition and serializes competing writes.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Category ID.
	 * @param string $action Generate, save, or discard.
	 * @param string $section Target section.
	 * @param array<string,mixed> $version Generated version, empty for save/discard.
	 * @param string $revision Expected revision.
	 * @return array<string,mixed>|\WP_Error Saved state or error.
	 */
	private function write( int $term_id, string $action, string $section, array $version, string $revision ) {
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			return new WP_Error( 'topic_invalid_section', __( 'Choose a valid topic section.', 'ai' ) );
		}

		wp_cache_delete( $term_id, 'term_meta' );
		$raw   = get_term_meta( $term_id, self::META_KEY, true );
		$state = $this->get( $term_id );

		if ( ! hash_equals( $state['revision'], $revision ) ) {
			return $this->conflict();
		}

		if ( 'discard' === $action ) {
			$state['sections'][ $section ]['draft'] = null;
		} else {
			$version = $this->version( 'generate' === $action ? $version : $state['sections'][ $section ]['draft'] );

			if ( ! $version ) {
				return 'save' === $action ? new WP_Error( 'topic_no_draft', __( 'There are no changes to save.', 'ai' ) ) : $this->invalid();
			}

			if ( 'generate' === $action ) {
				// A provider call can outlive edits in another request.
				foreach ( $version['source_ids'] as $source_id ) {
					clean_post_cache( $source_id );
				}

				$sources = new Sources();
				$term    = $sources->eligible_term( $term_id );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				Sources::invalidate( array( $term_id ), $term->taxonomy );
				$records = $sources->collect_for_term( $term, $section );

				if ( is_wp_error( $records ) ) {
					return $records;
				}

				if ( isset( $version['fingerprint'] ) && ! hash_equals( Sources::fingerprint( $records ), $version['fingerprint'] ) ) {
					return new WP_Error(
						'topic_sources_changed',
						__( 'Source articles changed during generation. Regenerate to use the latest articles. Your published text has not changed.', 'ai' ),
						array( 'status' => 409 )
					);
				}
			}

			$validated = $this->review( $term_id, $version, $section );

			if ( is_wp_error( $validated ) || ! $this->can_display( $term_id, $validated ) ) {
				return $this->invalid();
			}

			if ( 'generate' === $action ) {
				$state['sections'][ $section ]['draft'] = $validated;
			} else {
				$state['sections'][ $section ]['approved'] = $validated;
				$state['sections'][ $section ]['draft']    = null;
			}
		}

		$state['revision'] = wp_generate_uuid4();
		$saved             = metadata_exists( 'term', $term_id, self::META_KEY )
			? update_term_meta( $term_id, self::META_KEY, wp_slash( $state ), $raw )
			: add_term_meta( $term_id, self::META_KEY, wp_slash( $state ), true );

		return $saved && ! is_wp_error( $saved ) ? $this->get( $term_id ) : $this->conflict();
	}

	/**
	 * Renders approved, source-validated static blocks only.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Topic term ID.
	 * @param string $section Section name.
	 * @return string Public content or an empty string.
	 */
	public function render( int $term_id, string $section ): string {
		$version = $this->get( $term_id )['sections'][ $section ]['approved'] ?? null;

		if ( ! $version || ! $this->can_display( $term_id, $version ) ) {
			return '';
		}

		$version = $this->review( $term_id, $version, $section );

		return is_wp_error( $version ) ? '' : $this->render_content( $version['content'] );
	}

	/**
	 * Renders validated block content as safe HTML.
	 *
	 * @since x.x.x
	 *
	 * @param string $content Validated block content.
	 * @return string Display HTML.
	 */
	private function render_content( string $content ): string {
		return wp_kses_post( do_blocks( $content ) );
	}

	/**
	 * Checks the current public availability of a version's source context.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Topic term ID.
	 * @param array<string,mixed> $version Saved version.
	 * @return bool Whether the version may be published.
	 */
	public function can_display( int $term_id, array $version ): bool {
		$sources = new Sources();
		$term    = $sources->eligible_term( $term_id );

		if ( is_wp_error( $term ) || ! $this->version( $version ) ) {
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
	 *
	 * @param int $term_id Topic term ID.
	 * @param array<string,mixed> $version Draft or approved content.
	 * @param string $section Section name.
	 * @return array<string,mixed>|\WP_Error Validated version or error.
	 */
	private function review( int $term_id, array $version, string $section ) {
		if ( strlen( $version['content'] ) > 100000 ) {
			return $this->invalid();
		}

		$blocks = array_values( array_filter( parse_blocks( $version['content'] ), static fn( $block ) => null !== $block['blockName'] || '' !== trim( $block['innerHTML'] ) ) );

		if ( count( $blocks ) !== ( self::SUMMARY === $section ? 1 : 2 ) ) {
			return $this->invalid();
		}

		$sources = new Sources();
		$term    = $sources->term( $term_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$version['content'] = '';

		foreach ( $blocks as $block ) {
			if (
				'core/paragraph' !== $block['blockName'] ||
				! empty( $block['attrs']['metadata']['bindings'] ) ||
				! empty( $block['innerBlocks'] ) ||
				'' === trim( wp_strip_all_tags( $block['innerHTML'] ) )
			) {
				return $this->invalid();
			}

			$tags         = new \WP_HTML_Tag_Processor( wp_kses_post( serialize_block( $block ) ) );
			$has_citation = false;

			while ( $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
				$reference = $tags->get_attribute( 'data-wpai-source-id' );
				$id        = is_string( $reference ) && ctype_digit( $reference ) ? (int) $reference : 0;

				if ( self::SUMMARY === $section || ! in_array( $id, $version['source_ids'], true ) || ! $sources->is_public_source( $id, $term ) ) {
					return $this->invalid();
				}

				$tags->set_attribute( 'href', (string) get_permalink( $id ) );
				$has_citation = true;
			}

			if ( self::OVERVIEW === $section && ! $has_citation ) {
				return $this->invalid();
			}

			$version['content'] .= $tags->get_updated_html();
		}

		return $version;
	}

	/**
	 * Checks the shape of stored versions before they reach typed consumers.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Stored version.
	 * @return array<string,mixed>|null A structurally valid version.
	 */
	private function version( $value ): ?array {
		return is_array( $value ) &&
			isset( $value['content'], $value['source_ids'] ) &&
			is_string( $value['content'] ) &&
			is_array( $value['source_ids'] ) &&
			! empty( $value['source_ids'] ) &&
			count( array_filter( $value['source_ids'], 'is_int' ) ) === count( $value['source_ids'] )
				? $value
				: null;
	}

	/**
	 * Returns a recoverable review error without changing storage.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error Validation error.
	 */
	private function invalid(): WP_Error {
		return new WP_Error(
			'topic_invalid_content',
			__( 'This preview has invalid content or sources. Regenerate the section before saving. Your published text has not changed.', 'ai' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Returns a conflict without overwriting another request's work.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error Conflict error.
	 */
	private function conflict(): WP_Error {
		return new WP_Error(
			'topic_conflict',
			__( 'This topic changed in another request. Load the latest version before continuing. Your published text has not changed.', 'ai' ),
			array( 'status' => 409 )
		);
	}
}
