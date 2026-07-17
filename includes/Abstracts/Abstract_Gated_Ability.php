<?php
/**
 * Abstract Gated Ability base class.
 *
 * @package WordPress\AI\Abstracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Abstracts;

use InvalidArgumentException;
use WordPress\AI\Contracts\Gated_Ability;

/**
 * Base implementation for gated abilities.
 *
 * Handles label/description metadata and provides a default (no) dependency on
 * core-object exposure. Subclasses declare their key, metadata, and how to
 * register the underlying WordPress Ability.
 *
 * @since x.x.x
 */
abstract class Abstract_Gated_Ability implements Gated_Ability {
	/**
	 * The ability toggle key.
	 *
	 * @since x.x.x
	 * @var non-empty-string
	 */
	protected string $key;

	/**
	 * The ability label.
	 *
	 * @since x.x.x
	 * @var non-empty-string
	 */
	protected string $label;

	/**
	 * The ability description.
	 *
	 * @since x.x.x
	 * @var non-empty-string
	 */
	protected string $description;

	/**
	 * Constructor.
	 *
	 * Loads and validates the ability metadata.
	 *
	 * @since x.x.x
	 *
	 * @throws \InvalidArgumentException If the key or metadata is invalid.
	 */
	final public function __construct() {
		$this->key = static::get_key();
		if ( empty( $this->key ) ) {
			throw new InvalidArgumentException(
				esc_html__( 'Invalid gated ability key returned by ::get_key().', 'ai' )
			);
		}

		$metadata = $this->load_metadata();
		if ( empty( $metadata['label'] ) ) {
			throw new InvalidArgumentException(
				esc_html__( 'Gated ability label is required in load_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['description'] ) ) {
			throw new InvalidArgumentException(
				esc_html__( 'Gated ability description is required in load_metadata().', 'ai' )
			);
		}

		$this->label       = $metadata['label'];
		$this->description = $metadata['description'];
	}

	/**
	 * Loads the ability metadata.
	 *
	 * Must return an array with keys: label, description.
	 *
	 * @since x.x.x
	 *
	 * @return array{label: string, description: string} Ability metadata.
	 */
	abstract protected function load_metadata(): array;

	/**
	 * {@inheritDoc}
	 */
	abstract public function register(): void;

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Defaults to false; override in abilities that depend on core-object exposure.
	 */
	public function requires_core_object_exposure(): bool {
		return false;
	}
}
