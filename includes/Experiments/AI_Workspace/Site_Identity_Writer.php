<?php
/**
 * The `site-identity` kind of AI Workspace proposal.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;
use WordPress\AI\Abilities\AI_Workspace\Propose_Site_Identity;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Proposes and writes a new site title or tagline.
 *
 * Deliberately not a write ability: MCP has no approval step. See {@see Proposal_Writer}.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Site_Identity_Writer implements Proposal_Kind {

	use Proposal_Writes;

	/**
	 * The kind name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const KIND = 'site-identity';

	/**
	 * Operation name recorded on every log row this class writes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOG_OPERATION = 'ai/update-site-identity';

	/**
	 * The longest site title that may be proposed, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const TITLE_MAX_LENGTH = 200;

	/**
	 * The longest tagline that may be proposed, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const TAGLINE_MAX_LENGTH = 300;

	/**
	 * The options an item may change.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	public const OPTIONS = array( 'blogname', 'blogdescription' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * The capability needed to propose and to write.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function name(): string {
		return self::KIND;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function max_items(): int {
		return count( self::OPTIONS );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function ability(): string {
		return Propose_Site_Identity::ABILITY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function instruction(): string {
		return __( 'To change the site title or tagline, call the site identity tool with one item per setting and its complete new value, as plain text; the person sees the current value next to yours and approves it, and proposing it also ends your turn. Either change is live for every visitor as soon as it is approved.', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function is_update(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function accepts_edits(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function log_operation(): string {
		return self::LOG_OPERATION;
	}

	/**
	 * Normalizes and validates one proposed option change.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $item The raw item.
	 * @return array<string, string>|\WP_Error The normalized item, or an error.
	 */
	public function normalize_item( array $item ) {
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability is the literal manage_options, held in a constant.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'workspace_site_identity_denied',
				__( 'You do not have permission to change the site title or tagline.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$option = isset( $item['option'] ) && is_string( $item['option'] ) ? $item['option'] : '';

		if ( ! in_array( $option, self::OPTIONS, true ) ) {
			return new WP_Error(
				'workspace_site_identity_invalid_option',
				__( 'Only the site title and tagline can be changed.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$value = $this->normalize_value( $option, isset( $item['value'] ) && is_string( $item['value'] ) ? $item['value'] : '' );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		// An edited item keeps the snapshot it was proposed with.
		$snapshot = isset( $item[ self::SNAPSHOT_FIELD ] ) && is_string( $item[ self::SNAPSHOT_FIELD ] )
			? $item[ self::SNAPSHOT_FIELD ]
			: hash( 'sha256', $this->stored_value( $option ) );

		return array(
			'option'             => $option,
			'value'              => $value,
			self::SNAPSHOT_FIELD => $snapshot,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function present_item( array $item ): array {
		$option = $this->field( $item, 'option' );
		$known  = in_array( $option, self::OPTIONS, true );

		return array(
			'post_id'   => 0,
			'post_type' => 'option',
			'title'     => $this->label( $option ),
			'status'    => '',
			'live'      => true,
			'option'    => $option,
			'field'     => $option,
			'current'   => $known ? $this->display_value( $this->stored_value( $option ) ) : '',
			'value'     => $this->field( $item, 'value' ),
		);
	}

	/**
	 * Writes one option change.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item            The proposal item.
	 * @param string               $proposal_id     The proposal ID.
	 * @param string               $conversation_id The conversation ID.
	 * @return array<string, mixed> The item outcome.
	 */
	protected function write_item( array $item, string $proposal_id, string $conversation_id ): array {
		$option = $this->field( $item, 'option' );
		$value  = $this->field( $item, 'value' );

		$started = microtime( true );

		if ( ! in_array( $option, self::OPTIONS, true ) ) {
			$this->log( $item, $proposal_id, $conversation_id, 'error', 0, 'invalid_option', 'The item names an option that cannot be changed.' );

			return $this->outcome( $item, 'failed', 'invalid_option', __( 'Only the site title and tagline can be changed.', 'ai' ) );
		}

		// Re-checked: the capability may have been revoked since the proposal.
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability is the literal manage_options, held in a constant.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			$this->log( $item, $proposal_id, $conversation_id, Turn_Runner::LOG_STATUS_DENIED, 0, 'cannot_manage_options', 'The user may no longer change site settings.' );

			return $this->outcome( $item, 'denied', 'cannot_manage_options', __( 'You no longer have permission to change the site title or tagline.', 'ai' ) );
		}

		$current = $this->stored_value( $option );

		// `sanitize_option()` escapes these options: "Tom & Jerry" is stored as "Tom &amp; Jerry".
		if ( $current === $this->as_stored( $option, $value ) ) {
			$this->log( $item, $proposal_id, $conversation_id, 'skipped', 0, 'unchanged', '' );

			return $this->outcome( $item, 'unchanged', '', '' );
		}

		if ( ! hash_equals( $this->field( $item, self::SNAPSHOT_FIELD ), hash( 'sha256', $current ) ) ) {
			$this->log( $item, $proposal_id, $conversation_id, 'skipped', 0, 'stale', 'The option changed after the proposal was made.' );

			return $this->outcome( $item, 'stale', 'stale', __( 'This setting changed after the change was proposed, so it was left as it is.', 'ai' ) );
		}

		$written  = update_option( $option, $value );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! $written ) {
			$this->log( $item, $proposal_id, $conversation_id, 'error', $duration, 'option_not_updated', 'update_option() did not write the value.' );

			return $this->outcome( $item, 'failed', 'option_not_updated', __( 'The setting could not be saved.', 'ai' ) );
		}

		$this->log( $item, $proposal_id, $conversation_id, 'success', $duration, '', '' );

		return $this->outcome( $item, 'updated', '', '' );
	}

	/**
	 * Sanitizes and validates a proposed value for an option.
	 *
	 * @since x.x.x
	 *
	 * @param string $option The option.
	 * @param string $value  The raw value.
	 * @return string|\WP_Error The value to store, or an error.
	 */
	private function normalize_value( string $option, string $value ) {
		$value = sanitize_text_field( $value );

		if ( 'blogname' === $option && '' === $value ) {
			return new WP_Error(
				'workspace_site_identity_empty',
				__( 'The site title cannot be empty.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$max = 'blogname' === $option ? self::TITLE_MAX_LENGTH : self::TAGLINE_MAX_LENGTH;

		if ( mb_strlen( $value ) > $max ) {
			return new WP_Error(
				'workspace_site_identity_too_long',
				sprintf(
					/* translators: 1: a setting label, such as Tagline. 2: the maximum length in characters. */
					__( 'The %1$s can be at most %2$d characters long.', 'ai' ),
					$this->label( $option ),
					$max
				),
				array( 'status' => 400 )
			);
		}

		return $value;
	}

	/**
	 * Builds one item outcome.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item          The proposal item.
	 * @param string               $outcome       The outcome name.
	 * @param string               $error_code    The error code, when there is one.
	 * @param string               $error_message The error message, when there is one.
	 * @return array<string, mixed> The outcome record.
	 */
	private function outcome( array $item, string $outcome, string $error_code, string $error_message ): array {
		$option = $this->field( $item, 'option' );

		return array(
			'key'           => $this->field( $item, 'key' ),
			'title'         => $this->label( $option ),
			'post_type'     => 'option',
			'status'        => '',
			'outcome'       => $outcome,
			'post_id'       => 0,
			'edit_link'     => admin_url( 'options-general.php' ),
			'error_code'    => $error_code,
			'error_message' => $error_message,
			'option'        => $option,
			'field'         => $option,
			'value'         => $this->field( $item, 'value' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function deselected_outcome( array $item ): array {
		return $this->outcome( $item, 'deselected', '', '' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function log_details( array $item ): array {
		return array(
			'option' => $this->field( $item, 'option' ),
		);
	}

	/**
	 * Returns an option's value exactly as stored.
	 *
	 * @since x.x.x
	 *
	 * @param string $option The option, one of {@see self::OPTIONS}.
	 * @return string The stored value.
	 */
	private function stored_value( string $option ): string {
		$value = get_option( $option, '' );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Returns a value in the form core would store it for an option.
	 *
	 * @since x.x.x
	 *
	 * @param string $option The option.
	 * @param string $value  The plain-text value.
	 * @return string The value as `update_option()` would store it.
	 */
	private function as_stored( string $option, string $value ): string {
		$stored = sanitize_option( $option, $value );

		return is_scalar( $stored ) ? (string) $stored : '';
	}

	/**
	 * Returns a stored value as plain text, as the Settings screen shows it.
	 *
	 * @since x.x.x
	 *
	 * @param string $value The stored value.
	 * @return string The plain text.
	 */
	private function display_value( string $value ): string {
		return wp_specialchars_decode( $value, ENT_QUOTES );
	}

	/**
	 * Returns the label the Settings screen gives an option.
	 *
	 * @since x.x.x
	 *
	 * @param string $option The option.
	 * @return string The translated label, or the option name for any other.
	 */
	private function label( string $option ): string {
		if ( 'blogname' === $option ) {
			return __( 'Site Title', 'ai' );
		}

		if ( 'blogdescription' === $option ) {
			return __( 'Tagline', 'ai' );
		}

		return $option;
	}
}
