<?php
/**
 * AI Workspace chats in personal-data export and erase.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds a person's saved chats to Tools → Export Personal Data and Tools → Erase Personal Data.
 *
 * Registered by the plugin's `Main` class, next to the chat lifecycle hooks, so
 * chats saved while the experiment was on are exported and erased while it is
 * off (R16, KTD13). Chats are read through {@see Conversation_Store::chat_ids()},
 * which finds archived chats even when their status is not registered.
 *
 * Erasing needs no separate pass over proposals: a proposal whose chat is gone
 * can no longer be approved.
 *
 * @since x.x.x
 */
final class Conversation_Privacy {

	/**
	 * The exporter, eraser and export group ID.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ID = 'wpai-chats';

	/**
	 * How many chats each export page holds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const PER_PAGE = 20;

	/**
	 * Hooks the exporter and eraser.
	 *
	 * @since x.x.x
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Registers the AI chats exporter.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, mixed>> $exporters The registered exporters.
	 * @return array<string, array<string, mixed>> The exporters.
	 */
	public static function register_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'AI chats', 'ai' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the AI chats eraser.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, mixed>> $erasers The registered erasers.
	 * @return array<string, array<string, mixed>> The erasers.
	 */
	public static function register_eraser( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'AI chats', 'ai' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page of a person's chats.
	 *
	 * Each chat is one item: its name, when it was created and last updated,
	 * whether it is archived, and each turn's message and reply. Tool results
	 * are left out; they are site content the person can export on its own.
	 *
	 * @since x.x.x
	 *
	 * @param string $email_address The person's email address.
	 * @param int    $page          The one-based page.
	 * @return array{data: list<array<string, mixed>>, done: bool} The export page.
	 */
	public static function export( $email_address, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email_address );

		if ( false === $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page = max( 1, (int) $page );
		$ids  = Conversation_Store::chat_ids( $user->ID, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$data = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$data[] = array(
				'group_id'          => self::ID,
				'group_label'       => __( 'AI chats', 'ai' ),
				'group_description' => __( 'Your saved chats with the AI Workspace assistant.', 'ai' ),
				'item_id'           => 'wpai-chat-' . $post->post_name,
				'data'              => self::fields( $post ),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases every chat a person owns.
	 *
	 * @since x.x.x
	 *
	 * @param string $email_address The person's email address.
	 * @param int    $page          The one-based page. Every chat is erased on the first.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} The erase result.
	 */
	public static function erase( $email_address, $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', (string) $email_address );
		$had  = false !== $user && array() !== Conversation_Store::chat_ids( $user->ID, 1 );

		if ( $had ) {
			Conversation_Store::delete_user_chats( $user->ID );
		}

		return array(
			'items_removed'  => $had,
			// A chat that could not be deleted is still there.
			'items_retained' => $had && array() !== Conversation_Store::chat_ids( $user->ID, 1 ),
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Builds the exported fields of one chat.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The chat post.
	 * @return list<array{name: string, value: string}> The fields.
	 */
	private static function fields( WP_Post $post ): array {
		$fields = array(
			array(
				'name'  => __( 'Name', 'ai' ),
				'value' => $post->post_title,
			),
			array(
				'name'  => __( 'Created', 'ai' ),
				'value' => $post->post_date_gmt,
			),
			array(
				'name'  => __( 'Updated', 'ai' ),
				'value' => $post->post_modified_gmt,
			),
			array(
				'name'  => __( 'Archived', 'ai' ),
				'value' => Conversation_Store::STATUS_ARCHIVED === $post->post_status ? __( 'Yes', 'ai' ) : __( 'No', 'ai' ),
			),
		);

		foreach ( Conversation_Store::entries_of( $post->ID ) as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$turn = $index + 1;

			// A follow-up after an approval has no message of the person's.
			if ( isset( $entry['prompt'] ) && is_string( $entry['prompt'] ) && '' !== $entry['prompt'] ) {
				$fields[] = array(
					/* translators: %d: the turn's number in the chat. */
					'name'  => sprintf( __( 'Turn %d: your message', 'ai' ), $turn ),
					'value' => $entry['prompt'],
				);
			}

			if ( ! isset( $entry['text'] ) || ! is_string( $entry['text'] ) || '' === $entry['text'] ) {
				continue;
			}

			$fields[] = array(
				/* translators: %d: the turn's number in the chat. */
				'name'  => sprintf( __( 'Turn %d: reply', 'ai' ), $turn ),
				'value' => $entry['text'],
			);
		}

		return $fields;
	}
}
