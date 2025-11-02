<?php
/**
 * Builds settings data payload for React hydration.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin;

use WordPress\AI\Admin\Settings\Feature_Toggles;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;
use WordPress\AI\Admin\Settings\Settings_Toggle;

/**
 * Constructs the data structure passed to the React application.
 *
 * @since 0.1.0
 */
class Settings_Payload_Builder {
	/**
	 * Settings toggle service.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Toggle
	 */
	private $toggle;

	/**
	 * Feature toggles service.
	 *
	 * @var \WordPress\AI\Admin\Settings\Feature_Toggles
	 */
	private $feature_toggles;

	/**
	 * Settings registry.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle   $toggle          Settings toggle.
	 * @param \WordPress\AI\Admin\Settings\Feature_Toggles   $feature_toggles Feature toggles.
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry        Settings registry.
	 */
	public function __construct(
		Settings_Toggle $toggle,
		Feature_Toggles $feature_toggles,
		Settings_Registry $registry
	) {
		$this->toggle          = $toggle;
		$this->feature_toggles = $feature_toggles;
		$this->registry        = $registry;
	}

	/**
	 * Builds the data payload shared with the React application.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Settings payload.
	 */
	public function build(): array {
		$feature_toggles = $this->feature_toggles;
		$sections        = array_map(
			static function ( Settings_Section $section ) use ( $feature_toggles ): array {
				$feature_id = $section->get_feature_id();
				$enabled    = $feature_id ? $feature_toggles->is_feature_enabled( $feature_id ) : true;

				return $section->to_array( $enabled );
			},
			$this->registry->get_sections()
		);

		return array(
			'toggle'         => $this->toggle->to_array(),
			'featureToggles' => $this->feature_toggles->to_array(),
			'sections'       => array_values( $sections ),
		);
	}
}
