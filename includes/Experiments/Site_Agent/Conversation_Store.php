<?php
/**
 * Server-side Site Agent conversations.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stores one user's conversation transcripts until the user deletes them.
 *
 * Keeping the transcript on the server means the browser cannot supply the
 * history the model sees, an approval result can be appended to the same
 * conversation the person was reading, and the conversation can be listed and
 * reopened later from the same screen.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Conversation_Store {

	/**
	 * User meta prefix holding one conversation.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_PREFIX = 'wpai_site_agent_conversation_';

	/**
	 * User meta key holding the conversation index.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const INDEX_META = 'wpai_site_agent_conversations';

	/**
	 * Longest conversation title kept, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_TITLE_LENGTH = 80;

	/**
	 * Longest preview kept in the conversation index, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MAX_PREVIEW_LENGTH = 120;

	/**
	 * Creates and stores an empty conversation.
	 *
	 * @since x.x.x
	 *
	 * @param int    $user_id The owning user.
	 * @param string $title   Optional. Initial title, usually from the first prompt.
	 * @return array<string, mixed>|\WP_Error The conversation, or an error.
	 */
	public function create( int $user_id, string $title = '' ) {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'site_agent_conversation_no_owner',
				__( 'A conversation must belong to a signed-in user.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$now          = time();
		$conversation = array(
			'id'         => wp_generate_uuid4(),
			'user_id'    => $user_id,
			'title'      => $this->normalize_title( $title ),
			'messages'   => array(),
			'transcript' => array(),
			'created'    => $now,
			'updated'    => $now,
			'archived'   => false,
		);

		if ( ! $this->save( $conversation ) ) {
			return new WP_Error(
				'site_agent_conversation_not_stored',
				__( 'The conversation could not be stored.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $conversation;
	}

	/**
	 * Loads a conversation owned by a user.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The requesting user.
	 * @return array<string, mixed>|null The conversation, or null.
	 */
	public function get( string $conversation_id, int $user_id ): ?array {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return null;
		}

		$stored = get_user_meta( $user_id, $this->meta_name( $conversation_id ), true );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		if ( (int) ( $stored['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		if ( ! isset( $stored['transcript'] ) || ! is_array( $stored['transcript'] ) ) {
			$stored['transcript'] = array();
		}

		return $stored;
	}

	/**
	 * Saves a conversation and updates its index entry.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @return bool True when it was written.
	 */
	public function save( array $conversation ): bool {
		$conversation_id = isset( $conversation['id'] ) && is_string( $conversation['id'] ) ? $conversation['id'] : '';
		$user_id         = isset( $conversation['user_id'] ) ? (int) $conversation['user_id'] : 0;

		if ( '' === $conversation_id || $user_id <= 0 ) {
			return false;
		}

		$conversation['updated'] = time();

		$saved = update_user_meta( $user_id, $this->meta_name( $conversation_id ), $conversation );

		/*
		 * update_user_meta() returns false when the value is unchanged. The
		 * conversation was still persisted by the earlier write, so only treat
		 * a false return as an error when the stored value is missing.
		 */
		if ( false === $saved ) {
			$stored = get_user_meta( $user_id, $this->meta_name( $conversation_id ), true );

			if ( ! is_array( $stored ) ) {
				return false;
			}
		}

		$this->update_index( $conversation );

		return true;
	}

	/**
	 * Deletes a conversation and its index entry.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user.
	 */
	public function delete( string $conversation_id, int $user_id ): void {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, $this->meta_name( $conversation_id ) );

		$index = $this->get_index( $user_id );

		unset( $index[ $conversation_id ] );

		update_user_meta( $user_id, self::INDEX_META, $index );
	}

	/**
	 * Renames a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user.
	 * @param string $title           The new title.
	 * @return array<string, mixed>|\WP_Error The updated conversation, or an error.
	 */
	public function rename( string $conversation_id, int $user_id, string $title ) {
		$conversation = $this->get( $conversation_id, $user_id );

		if ( null === $conversation ) {
			return new WP_Error(
				'site_agent_conversation_not_found',
				__( 'That conversation was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		$conversation['title'] = $this->normalize_title( $title );

		if ( ! $this->save( $conversation ) ) {
			return new WP_Error(
				'site_agent_conversation_not_updated',
				__( 'The conversation could not be renamed.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $conversation;
	}

	/**
	 * Archives or unarchives a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user.
	 * @param bool   $archived        Whether to archive the conversation.
	 * @return array<string, mixed>|\WP_Error The updated conversation, or an error.
	 */
	public function archive( string $conversation_id, int $user_id, bool $archived = true ) {
		$conversation = $this->get( $conversation_id, $user_id );

		if ( null === $conversation ) {
			return new WP_Error(
				'site_agent_conversation_not_found',
				__( 'That conversation was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		$conversation['archived'] = $archived;

		if ( ! $this->save( $conversation ) ) {
			return new WP_Error(
				'site_agent_conversation_not_updated',
				__( 'The conversation could not be updated.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $conversation;
	}

	/**
	 * Lists a user's conversations, newest first.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id The owning user.
	 * @return list<array<string, mixed>> The conversation summaries.
	 */
	public function all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$index      = $this->get_index( $user_id );
		$summaries  = array();
		$stale_keys = array();

		foreach ( $index as $conversation_id => $summary ) {
			if ( ! is_array( $summary ) ) {
				$stale_keys[] = $conversation_id;
				continue;
			}

			$stored = get_user_meta( $user_id, $this->meta_name( (string) $conversation_id ), true );

			if ( ! is_array( $stored ) ) {
				$stale_keys[] = $conversation_id;
				continue;
			}

			$summaries[] = $this->summary( $stored );
		}

		if ( array() !== $stale_keys ) {
			foreach ( $stale_keys as $stale_key ) {
				unset( $index[ $stale_key ] );
			}

			update_user_meta( $user_id, self::INDEX_META, $index );
		}

		usort(
			$summaries,
			static function ( array $a, array $b ): int {
				return (int) ( $b['updated'] ?? 0 ) <=> (int) ( $a['updated'] ?? 0 );
			}
		);

		return $summaries;
	}

	/**
	 * Builds a short title from a prompt.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt.
	 * @return string The title.
	 */
	public function title_for_prompt( string $prompt ): string {
		$title = trim( wp_strip_all_tags( $prompt ) );

		if ( '' === $title ) {
			return '';
		}

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			$title = rtrim( mb_substr( $title, 0, self::MAX_TITLE_LENGTH - 1 ) ) . '…';
		}

		return $title;
	}

	/**
	 * Builds a summary for the conversation list.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @return array<string, mixed> The summary.
	 */
	private function summary( array $conversation ): array {
		$transcript = isset( $conversation['transcript'] ) && is_array( $conversation['transcript'] )
			? $conversation['transcript']
			: array();
		$preview    = '';

		for ( $i = count( $transcript ) - 1; $i >= 0; $i-- ) {
			$entry = $transcript[ $i ];

			if ( ! is_array( $entry ) || ! isset( $entry['text'] ) || ! is_scalar( $entry['text'] ) ) {
				continue;
			}

			$preview = trim( wp_strip_all_tags( (string) $entry['text'] ) );

			if ( '' !== $preview ) {
				break;
			}
		}

		$preview = $this->plain_preview( $preview );

		if ( mb_strlen( $preview ) > self::MAX_PREVIEW_LENGTH ) {
			$preview = rtrim( mb_substr( $preview, 0, self::MAX_PREVIEW_LENGTH - 1 ) ) . '…';
		}

		return array(
			'id'       => (string) ( $conversation['id'] ?? '' ),
			'title'    => (string) ( $conversation['title'] ?? '' ),
			'created'  => (int) ( $conversation['created'] ?? 0 ),
			'updated'  => (int) ( $conversation['updated'] ?? 0 ),
			'archived' => (bool) ( $conversation['archived'] ?? false ),
			'preview'  => $preview,
		);
	}

	/**
	 * Removes common Markdown syntax from a transcript preview.
	 *
	 * @since x.x.x
	 *
	 * @param string $preview The raw preview.
	 * @return string The plain preview.
	 */
	private function plain_preview( string $preview ): string {
		$replacements = array(
			'/!\[[^\]]*\]\([^)]*\)/u'  => '',
			'/\[([^\]]+)\]\([^)]*\)/u' => '$1',
			'/`{1,3}([^`]+)`{1,3}/u'   => '$1',
			'/^[\s>#*+-]+/mu'          => '',
			'/(\*\*|__)(.*?)\1/u'      => '$2',
			'/(\*|_)(.*?)\1/u'         => '$2',
			'/\s+/u'                   => ' ',
		);

		$preview = (string) preg_replace( array_keys( $replacements ), array_values( $replacements ), $preview );

		return trim( $preview );
	}

	/**
	 * Updates the conversation index entry.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 */
	private function update_index( array $conversation ): void {
		$conversation_id = (string) ( $conversation['id'] ?? '' );
		$user_id         = (int) ( $conversation['user_id'] ?? 0 );

		if ( '' === $conversation_id || $user_id <= 0 ) {
			return;
		}

		$index = $this->get_index( $user_id );

		$index[ $conversation_id ] = $this->summary( $conversation );

		update_user_meta( $user_id, self::INDEX_META, $index );
	}

	/**
	 * Reads the conversation index.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id The owning user.
	 * @return array<string, mixed> The index.
	 */
	private function get_index( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$index = get_user_meta( $user_id, self::INDEX_META, true );

		return is_array( $index ) ? $index : array();
	}

	/**
	 * Normalizes a conversation title.
	 *
	 * @since x.x.x
	 *
	 * @param string $title The title.
	 * @return string The normalized title.
	 */
	private function normalize_title( string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			$title = rtrim( mb_substr( $title, 0, self::MAX_TITLE_LENGTH - 1 ) ) . '…';
		}

		return $title;
	}

	/**
	 * Builds the user meta key holding a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @return string The user meta key.
	 */
	private function meta_name( string $conversation_id ): string {
		return self::META_PREFIX . $conversation_id;
	}
}
