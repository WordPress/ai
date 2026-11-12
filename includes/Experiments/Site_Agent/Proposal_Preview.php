<?php
/**
 * Human-readable proposal previews for Site Agent actions.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use WP_Post;
use WordPress\AI\Abilities\Site_Agent\Create_Draft;
use WordPress\AI\Abilities\Site_Agent\Update_Post;
use WordPress\AI\Abilities\Site_Agent\Update_Setting;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the from/to rows shown on a proposal card.
 *
 * A proposal stores the exact arguments the model asked to run. This class
 * reads the current value for the same fields so the card can show what will
 * change, not only what the new value will be. It never writes anything.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Proposal_Preview {

	/**
	 * Setting names mapped to their human-readable labels.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private const SETTING_LABELS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'blogname'        => 'Site title',
		'blogdescription' => 'Tagline',
	);

	/**
	 * Field names mapped to their human-readable labels.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private const FIELD_LABELS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'post_type' => 'Type',
		'title'     => 'Title',
		'content'   => 'Content',
		'excerpt'   => 'Excerpt',
		'status'    => 'Status',
		'name'      => 'Setting',
		'value'     => 'Value',
	);

	/**
	 * Builds the before/after rows for one proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string               $ability_name The ability name.
	 * @param array<string, mixed> $args         The exact stored arguments.
	 * @return list<array<string, mixed>> The change rows.
	 */
	public static function changes( string $ability_name, array $args ): array {
		switch ( $ability_name ) {
			case Create_Draft::ABILITY:
				return self::create_draft_changes( $args );
			case Update_Post::ABILITY:
				return self::update_post_changes( $args );
			case Update_Setting::ABILITY:
				return self::update_setting_changes( $args );
		}

		return self::generic_changes( $args );
	}

	/**
	 * Builds a transcript-ready summary of an executed proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability name.
	 * @param mixed  $result       The ability result.
	 * @param string $error        Optional. The error message.
	 * @return string The summary.
	 */
	public static function result_summary( string $ability_name, $result, string $error = '' ): string {
		if ( '' !== $error ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'The action failed: %s', 'ai' ),
				$error
			);
		}

		if ( ! is_array( $result ) ) {
			return __( 'The proposal was approved and the action completed.', 'ai' );
		}

		$link  = isset( $result['link'] ) && is_scalar( $result['link'] ) ? (string) $result['link'] : '';
		$title = isset( $result['title'] ) && is_scalar( $result['title'] ) ? (string) $result['title'] : '';

		switch ( $ability_name ) {
			case Create_Draft::ABILITY:
				if ( '' !== $title ) {
					return '' !== $link
						? sprintf(
							/* translators: 1: draft title, 2: draft link. */
							__( 'Created the draft “%1$s”. [Open the draft](%2$s)', 'ai' ),
							$title,
							$link
						)
						: sprintf(
							/* translators: %s: draft title. */
							__( 'Created the draft “%s”.', 'ai' ),
							$title
						);
				}
				break;

			case Update_Post::ABILITY:
				if ( '' !== $title ) {
					return '' !== $link
						? sprintf(
							/* translators: 1: post title, 2: post link. */
							__( 'Updated “%1$s”. [Open the post](%2$s)', 'ai' ),
							$title,
							$link
						)
						: sprintf(
							/* translators: %s: post title. */
							__( 'Updated “%s”.', 'ai' ),
							$title
						);
				}
				break;

			case Update_Setting::ABILITY:
				$name  = isset( $result['name'] ) && is_scalar( $result['name'] ) ? (string) $result['name'] : '';
				$value = isset( $result['value'] ) && is_scalar( $result['value'] ) ? (string) $result['value'] : '';
				$label = self::setting_label( $name );

				if ( '' !== $value ) {
					return sprintf(
						/* translators: 1: setting label, 2: new value. */
						__( 'Updated the %1$s to “%2$s”.', 'ai' ),
						$label,
						$value
					);
				}
				break;
		}

		return __( 'The proposal was approved and the action completed.', 'ai' );
	}

	/**
	 * Builds changes for a new draft.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @return list<array<string, mixed>> The change rows.
	 */
	private static function create_draft_changes( array $args ): array {
		$post_type = isset( $args['post_type'] ) && is_scalar( $args['post_type'] ) ? sanitize_key( (string) $args['post_type'] ) : 'post';
		$rows      = array(
			self::row(
				'post_type',
				'page' === $post_type ? __( 'Page', 'ai' ) : __( 'Post', 'ai' )
			),
			self::row(
				'title',
				isset( $args['title'] ) && is_scalar( $args['title'] ) ? (string) $args['title'] : ''
			),
		);

		if ( isset( $args['content'] ) && is_scalar( $args['content'] ) && '' !== (string) $args['content'] ) {
			$rows[] = self::row( 'content', wp_kses_post( (string) $args['content'] ), 'html' );
		}

		if ( isset( $args['excerpt'] ) && is_scalar( $args['excerpt'] ) && '' !== (string) $args['excerpt'] ) {
			$rows[] = self::row( 'excerpt', (string) $args['excerpt'] );
		}

		$rows[] = self::row( 'status', __( 'Draft', 'ai' ) );

		return $rows;
	}

	/**
	 * Builds changes for an existing post or page.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @return list<array<string, mixed>> The change rows.
	 */
	private static function update_post_changes( array $args ): array {
		$id   = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return self::generic_changes( $args );
		}

		$rows = array();

		if ( array_key_exists( 'title', $args ) ) {
			$rows[] = self::row(
				'title',
				isset( $args['title'] ) && is_scalar( $args['title'] ) ? (string) $args['title'] : '',
				'text',
				(string) $post->post_title
			);
		}

		if ( array_key_exists( 'content', $args ) ) {
			$rows[] = self::row(
				'content',
				wp_kses_post( isset( $args['content'] ) && is_scalar( $args['content'] ) ? (string) $args['content'] : '' ),
				'html',
				wp_kses_post( (string) $post->post_content )
			);
		}

		if ( array_key_exists( 'excerpt', $args ) ) {
			$rows[] = self::row(
				'excerpt',
				isset( $args['excerpt'] ) && is_scalar( $args['excerpt'] ) ? (string) $args['excerpt'] : '',
				'text',
				(string) $post->post_excerpt
			);
		}

		if ( array_key_exists( 'status', $args ) ) {
			$status = isset( $args['status'] ) && is_scalar( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';

			$rows[] = self::row(
				'status',
				self::status_label( $status ),
				'text',
				self::status_label( (string) $post->post_status )
			);
		}

		return array() === $rows ? self::generic_changes( $args ) : $rows;
	}

	/**
	 * Builds changes for a site setting.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @return list<array<string, mixed>> The change rows.
	 */
	private static function update_setting_changes( array $args ): array {
		$name  = isset( $args['name'] ) && is_scalar( $args['name'] ) ? sanitize_key( (string) $args['name'] ) : '';
		$value = isset( $args['value'] ) && is_scalar( $args['value'] ) ? (string) $args['value'] : '';
		$label = self::setting_label( $name );

		return array(
			array(
				'field'  => 'value',
				'label'  => $label,
				'from'   => '' !== $name ? (string) get_option( $name ) : '',
				'to'     => $value,
				'format' => 'text',
			),
		);
	}

	/**
	 * Builds generic change rows for an ability without a dedicated preview.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @return list<array<string, mixed>> The change rows.
	 */
	private static function generic_changes( array $args ): array {
		$rows = array();

		foreach ( $args as $field => $value ) {
			$rows[] = array(
				'field'  => (string) $field,
				'label'  => self::field_label( (string) $field ),
				'from'   => null,
				'to'     => self::format_scalar( $value ),
				'format' => 'text',
			);
		}

		return $rows;
	}

	/**
	 * Builds one change row.
	 *
	 * @since x.x.x
	 *
	 * @param string      $field  The field name.
	 * @param string      $to     The proposed value.
	 * @param string      $format Optional. Either text or html.
	 * @param string|null $from   Optional. The current value, or null for a new item.
	 * @return array<string, mixed> The row.
	 */
	private static function row( string $field, string $to, string $format = 'text', ?string $from = null ): array {
		return array(
			'field'  => $field,
			'label'  => self::field_label( $field ),
			'from'   => $from,
			'to'     => $to,
			'format' => $format,
		);
	}

	/**
	 * Returns a human-readable label for a field.
	 *
	 * @since x.x.x
	 *
	 * @param string $field The field name.
	 * @return string The label.
	 */
	private static function field_label( string $field ): string {
		if ( isset( self::FIELD_LABELS[ $field ] ) ) {
			return __( self::FIELD_LABELS[ $field ], 'ai' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Fixed label map.
		}

		return ucwords( str_replace( '_', ' ', $field ) );
	}

	/**
	 * Returns a human-readable label for a setting.
	 *
	 * @since x.x.x
	 *
	 * @param string $name The setting name.
	 * @return string The label.
	 */
	private static function setting_label( string $name ): string {
		if ( isset( self::SETTING_LABELS[ $name ] ) ) {
			return __( self::SETTING_LABELS[ $name ], 'ai' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Fixed label map.
		}

		return $name;
	}

	/**
	 * Returns a human-readable status label.
	 *
	 * @since x.x.x
	 *
	 * @param string $status The status.
	 * @return string The label.
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case 'publish':
				return __( 'Published', 'ai' );
			case 'future':
				return __( 'Scheduled', 'ai' );
			case 'pending':
				return __( 'Pending review', 'ai' );
			case 'draft':
				return __( 'Draft', 'ai' );
			case 'private':
				return __( 'Private', 'ai' );
			case 'auto-draft':
				return __( 'Auto draft', 'ai' );
		}

		return $status;
	}

	/**
	 * Formats a scalar or array value for display.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The value.
	 * @return string The display value.
	 */
	private static function format_scalar( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? $encoded : '';
	}
}
