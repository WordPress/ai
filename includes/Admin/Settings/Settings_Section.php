<?php
/**
 * Value object describing a settings section on the admin screen.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin\Settings;

/**
 * Represents a single settings section contributed by the plugin or a feature.
 *
 * @since 0.1.0
 */
class Settings_Section {
	/**
	 * Unique section identifier.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Localised section title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Short description shown below the title.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Callback responsible for rendering the section content.
	 *
	 * @var callable
	 */
	private $render_callback;

	/**
	 * Display order priority.
	 *
	 * @var int
	 */
	private $priority;

	/**
	 * Optional feature identifier that owns this section.
	 *
	 * @var string|null
	 */
	private $feature_id;

	/**
	 * Optional metadata describing capabilities or supported renderers.
	 *
	 * @var array<string, mixed>
	 */
	private $supports;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $id              Section identifier.
	 * @param string               $title           Section title.
	 * @param string               $description     Section description.
	 * @param callable             $render_callback Render callback.
	 * @param int                  $priority        Priority for ordering.
	 * @param string|null          $feature_id      Owning feature identifier.
	 * @param array<string, mixed> $supports        Additional metadata.
	 */
	public function __construct(
		string $id,
		string $title,
		string $description,
		callable $render_callback,
		int $priority = 10,
		?string $feature_id = null,
		array $supports = array()
	) {
		$this->id              = $id;
		$this->title           = $title;
		$this->description     = $description;
		$this->render_callback = $render_callback;
		$this->priority        = $priority;
		$this->feature_id      = $feature_id;
		$this->supports        = $supports;
	}

	/**
	 * Returns the identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Returns the title.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * Returns the description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Returns the render callback.
	 *
	 * @since 0.1.0
	 *
	 * @return callable
	 */
	public function get_render_callback(): callable {
		return $this->render_callback;
	}

	/**
	 * Returns the priority.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return $this->priority;
	}

	/**
	 * Returns the owning feature identifier if provided.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function get_feature_id(): ?string {
		return $this->feature_id;
	}

	/**
	 * Returns the supports metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_supports(): array {
		return $this->supports;
	}

	/**
	 * Serializes the section to an array for JavaScript hydration.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $feature_enabled Whether the feature is currently enabled.
	 * @return array<string, mixed>
	 */
	public function to_array( bool $feature_enabled = true ): array {
		return array(
			'id'          => $this->id,
			'title'       => $this->title,
			'description' => $this->description,
			'featureId'   => $this->feature_id,
			'priority'    => $this->priority,
			'supports'    => $this->supports,
			'enabled'     => $feature_enabled,
		);
	}
}
