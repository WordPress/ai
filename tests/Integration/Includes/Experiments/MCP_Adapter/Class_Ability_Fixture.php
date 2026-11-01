<?php
/**
 * Class-based ability fixture for MCP Access tests.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\MCP_Adapter
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\MCP_Adapter;

use WordPress\AI\Abstracts\Abstract_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers like the plugin's own abilities: through `ability_class`, with
 * meta declared by the class instead of the registration args.
 */
class Class_Ability_Fixture extends Abstract_Ability {
	/**
	 * Meta the next instance declares.
	 *
	 * @var array<string, mixed>
	 */
	public static array $declared_meta = array();

	/**
	 * Registers the fixture inside the abilities API init context.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $meta Meta the class declares.
	 */
	public static function register( string $name, array $meta = array() ): void {
		global $wp_current_filter;

		self::$declared_meta = $meta;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$name,
				array(
					'label'         => 'Class-based test ability',
					'description'   => 'Test ability registered through ability_class.',
					'ability_class' => self::class,
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute_callback( $input ) {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return self::$declared_meta;
	}
}
