<?php
/**
 * Intercepts reads of connector credential options to enforce caller approval.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers `option_{$setting_name}` filters for each connector credential option.
 *
 * When a call reads a connector's credential and the originating plugin/theme
 * has not been approved, the filter returns an empty string and records a
 * pending approval request for the site administrator to review.
 *
 * @since x.x.x
 */
final class Option_Guard {
	/**
	 * Caller identifier.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Caller_Identifier
	 */
	private Caller_Identifier $identifier;

	/**
	 * Approvals store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Map of option name to connector id for the connectors we guard.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private array $option_to_connector = array();

	/**
	 * Map of connector id to the owning plugin basename, used for the owner bypass.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private array $connector_owner = array();

	/**
	 * Guard against reentrant filter calls when recording pending attempts.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private bool $in_filter = false;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Connector_Approval\Caller_Identifier $identifier Caller identifier.
	 * @param \WordPress\AI\Connector_Approval\Approvals_Store $store Approvals store.
	 */
	public function __construct( Caller_Identifier $identifier, Approvals_Store $store ) {
		$this->identifier = $identifier;
		$this->store      = $store;
	}

	/**
	 * Registers the option filters for every known connector's credential option.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$connectors = wp_get_connectors();
		if ( ! is_array( $connectors ) ) {
			return;
		}

		foreach ( $connectors as $connector_id => $data ) {
			if ( ! is_string( $connector_id ) || ! is_array( $data ) ) {
				continue;
			}

			$auth         = isset( $data['authentication'] ) && is_array( $data['authentication'] ) ? $data['authentication'] : array();
			$setting_name = isset( $auth['setting_name'] ) && is_string( $auth['setting_name'] ) ? $auth['setting_name'] : '';
			if ( '' === $setting_name ) {
				continue;
			}

			$this->option_to_connector[ $setting_name ] = $connector_id;

			$plugin = isset( $data['plugin'] ) && is_array( $data['plugin'] ) ? $data['plugin'] : array();
			$owner  = isset( $plugin['file'] ) && is_string( $plugin['file'] ) ? $plugin['file'] : '';
			if ( '' !== $owner ) {
				$this->connector_owner[ $connector_id ] = $owner;
			}

			add_filter( "option_{$setting_name}", array( $this, 'filter_option' ), 10, 2 );
		}
	}

	/**
	 * Filters a credential option value based on caller approval.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $value  The option value read from the database.
	 * @param string $option The option name.
	 * @return mixed Either the original value when approved, or an empty string when denied.
	 */
	public function filter_option( $value, string $option ) {
		if ( $this->in_filter ) {
			return $value;
		}

		if ( ! isset( $this->option_to_connector[ $option ] ) ) {
			return $value;
		}

		$connector_id = $this->option_to_connector[ $option ];

		$this->in_filter = true;
		try {
			$caller = $this->identifier->identify();
		} finally {
			$this->in_filter = false;
		}

		// No identifiable plugin/theme/mu-plugin on the stack — pass through (wp-cli, core, REST, etc.).
		if ( null === $caller ) {
			return $value;
		}

		// The connector's own plugin can always read its own credential.
		if ( Caller_Identifier::TYPE_PLUGIN === $caller['type']
			&& isset( $this->connector_owner[ $connector_id ] )
			&& $this->matches_owner( $caller['basename'], $this->connector_owner[ $connector_id ] )
		) {
			return $value;
		}

		if ( $this->store->is_approved( $caller['basename'], $connector_id ) ) {
			return $value;
		}

		$this->in_filter = true;
		try {
			$this->store->record_pending( $caller, $connector_id );
		} finally {
			$this->in_filter = false;
		}

		return '';
	}

	/**
	 * Determines whether a caller basename matches a connector owner plugin file.
	 *
	 * The owner is registered as a plugin basename (e.g. `ai-provider-for-openai/plugin.php`);
	 * caller basenames for plugins are also in that shape, so we compare the slug segment.
	 *
	 * @since x.x.x
	 *
	 * @param string $caller_basename Caller basename.
	 * @param string $owner_basename  Owner plugin basename from the connector registry.
	 * @return bool
	 */
	private function matches_owner( string $caller_basename, string $owner_basename ): bool {
		if ( $caller_basename === $owner_basename ) {
			return true;
		}

		$caller_slug = strtok( $caller_basename, '/' );
		$owner_slug  = strtok( $owner_basename, '/' );

		return false !== $caller_slug && false !== $owner_slug && $caller_slug === $owner_slug;
	}
}
