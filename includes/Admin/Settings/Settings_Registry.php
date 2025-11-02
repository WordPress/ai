<?php
/**
 * Registry for AI Experiments settings sections.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin\Settings;

/**
 * Stores and exposes settings sections registered by the plugin or features.
 *
 * @since 0.1.0
 */
class Settings_Registry {
	/**
	 * Registered sections keyed by identifier.
	 *
	 * @var array<string, \WordPress\AI\Admin\Settings\Settings_Section>
	 */
	private $sections = array();

	/**
	 * Registers a settings section.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Section $section Section instance.
	 * @return bool True if registration succeeded, false if the ID is already in use.
	 */
	public function register_section( Settings_Section $section ): bool {
		$id = $section->get_id();

		if ( isset( $this->sections[ $id ] ) ) {
			return false;
		}

		$this->sections[ $id ] = $section;
		return true;
	}

	/**
	 * Determines whether a section with the provided identifier exists.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Section identifier.
	 * @return bool True when the section exists.
	 */
	public function has_section( string $id ): bool {
		return isset( $this->sections[ $id ] );
	}

	/**
	 * Returns a registered section by identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Section identifier.
	 * @return \WordPress\AI\Admin\Settings\Settings_Section|null Section instance or null.
	 */
	public function get_section( string $id ): ?Settings_Section {
		return $this->sections[ $id ] ?? null;
	}

	/**
	 * Returns all registered sections sorted by priority and identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, \WordPress\AI\Admin\Settings\Settings_Section> Sorted sections keyed by identifier.
	 */
	public function get_sections(): array {
		if ( empty( $this->sections ) ) {
			return array();
		}

		$sections = $this->sections;

		uasort(
			$sections,
			static function ( Settings_Section $a, Settings_Section $b ): int {
				$priority = $a->get_priority() <=> $b->get_priority();
				if ( 0 !== $priority ) {
					return $priority;
				}

				return strcmp( $a->get_id(), $b->get_id() );
			}
		);

		return $sections;
	}
}
