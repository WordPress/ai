<?php
/**
 * A proposal kind that changes an existing value, for tests only.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures;

use RuntimeException;
use WP_Error;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Kind;

/**
 * Stands in for the update kinds later units add (post fields, site identity).
 *
 * It carries one `value` per item, takes a snapshot when the item has none, and
 * records what it was asked to write instead of writing anything. It can be told
 * to throw on a given item, to stand in for a request that dies mid-write.
 *
 * @since x.x.x
 */
final class Test_Update_Kind implements Proposal_Kind {

	/**
	 * The kind name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const KIND = 'test-update';

	/**
	 * The propose ability this kind declares.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/propose-test-update';

	/**
	 * The instruction sentence this kind contributes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const INSTRUCTION = 'To change a test value, call the test update tool.';

	/**
	 * The snapshot a new item is given.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SNAPSHOT = 'value at propose time';

	/**
	 * Whether this kind changes existing things.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private bool $update;

	/**
	 * The one-based position of the selected item to throw on, or 0 for none.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public int $throw_on = 0;

	/**
	 * The values each write was asked to make, keyed by item key.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	public array $written = array();

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param bool $update Optional. Whether the kind is an update kind. Default true.
	 */
	public function __construct( bool $update = true ) {
		$this->update = $update;
	}

	/**
	 * Adds this kind to every proposal store through the kinds filter.
	 *
	 * @since x.x.x
	 *
	 * @return callable The filter callback, for removal.
	 */
	public function add_to_stores(): callable {
		$callback = function ( $kinds ) {
			$kinds[] = $this;

			return $kinds;
		};

		add_filter( 'wpai_workspace_proposal_kinds', $callback );

		return $callback;
	}

	/**
	 * Registers the kind's propose ability, as a later unit's propose class would.
	 *
	 * @since x.x.x
	 */
	public static function register_ability(): void {
		global $wp_current_filter;

		if ( ! wp_has_ability_category( 'content' ) ) {
			$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
			try {
				wp_register_ability_category(
					'content',
					array(
						'label'       => 'Content',
						'description' => 'Content.',
					)
				);
			} finally {
				array_pop( $wp_current_filter );
			}
		}

		if ( wp_has_ability( self::ABILITY ) ) {
			return;
		}

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				self::ABILITY,
				array(
					'label'               => 'Propose a test update',
					'description'         => 'Stores a proposed test value for the person to approve.',
					'category'            => 'content',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array( 'value' => array( 'type' => 'string' ) ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array( 'proposal_id' => array( 'type' => 'string' ) ),
					),
					'execute_callback'    => static function () {
						return array( 'proposal_id' => 'test-proposal' );
					},
					'permission_callback' => static function () {
						return true;
					},
					'meta'                => array(
						'annotations' => array(
							'readonly'    => false,
							'destructive' => false,
							'open_world'  => false,
						),
					),
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Unregisters the kind's propose ability.
	 *
	 * @since x.x.x
	 */
	public static function unregister_ability(): void {
		if ( ! wp_has_ability( self::ABILITY ) ) {
			return;
		}

		wp_unregister_ability( self::ABILITY );
	}

	/** {@inheritDoc} */
	public function name(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function max_items(): int {
		return 5;
	}

	/** {@inheritDoc} */
	public function ability(): string {
		return self::ABILITY;
	}

	/** {@inheritDoc} */
	public function capability(): string {
		return '';
	}

	/** {@inheritDoc} */
	public function instruction(): string {
		return self::INSTRUCTION;
	}

	/** {@inheritDoc} */
	public function is_update(): bool {
		return $this->update;
	}

	/** {@inheritDoc} */
	public function accepts_edits(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function normalize_item( array $item ) {
		$value = isset( $item['value'] ) && is_string( $item['value'] ) ? trim( $item['value'] ) : '';

		if ( '' === $value ) {
			return new WP_Error( 'test_update_empty', 'A value is required.', array( 'status' => 400 ) );
		}

		$snapshot = isset( $item[ self::SNAPSHOT_FIELD ] ) && is_string( $item[ self::SNAPSHOT_FIELD ] )
			? $item[ self::SNAPSHOT_FIELD ]
			: self::SNAPSHOT;

		return array(
			'value'              => $value,
			self::SNAPSHOT_FIELD => $snapshot,
		);
	}

	/** {@inheritDoc} */
	public function present_item( array $item ): array {
		return array(
			'value' => isset( $item['value'] ) && is_string( $item['value'] ) ? $item['value'] : '',
		);
	}

	/** {@inheritDoc} */
	public function write( array $proposal, array $selected, ?callable $on_item = null ): array {
		$counts = array_fill_keys( self::OUTCOMES, 0 );
		$items  = array();
		$seen   = 0;

		foreach ( $proposal['items'] as $item ) {
			$key = (string) $item['key'];

			if ( ! in_array( $key, $selected, true ) ) {
				$outcome = $this->outcome( $key, 'deselected' );
			} else {
				++$seen;

				if ( $seen === $this->throw_on ) {
					throw new RuntimeException( 'The request died mid-write.' );
				}

				$this->written[ $key ] = (string) $item['value'];

				$outcome = $this->outcome( $key, 'updated' );
			}

			++$counts[ $outcome['outcome'] ];
			$items[] = $outcome;

			if ( null === $on_item ) {
				continue;
			}

			$on_item( $outcome );
		}

		return array_merge( array( 'items' => $items ), $counts );
	}

	/** {@inheritDoc} */
	public function log_operation(): string {
		return 'ai/test-update';
	}

	/**
	 * Builds one item outcome.
	 *
	 * @since x.x.x
	 *
	 * @param string $key     The item key.
	 * @param string $outcome The outcome name.
	 * @return array<string, mixed> The outcome.
	 */
	private function outcome( string $key, string $outcome ): array {
		return array(
			'key'           => $key,
			'title'         => 'Test value',
			'post_type'     => '',
			'status'        => '',
			'outcome'       => $outcome,
			'post_id'       => 0,
			'edit_link'     => '',
			'error_code'    => '',
			'error_message' => '',
		);
	}
}
