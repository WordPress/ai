<?php
/**
 * The `core/templates-query` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Templates;

use WP_Block_Template;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Templates
 *
 * Registers the read-only `core/templates-query` ability. It reports whether the active
 * theme is a block theme, and lists the block templates the site has for that theme:
 * where each one comes from, and whether the theme ships it as a file.
 *
 * An agent can call it before proposing a change to how a page looks, instead of finding
 * out from an error that the theme does not support block templates or that a template
 * has already been edited.
 *
 * Only templates (`wp_template`) are listed, not template parts.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Templates {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const ABILITY = 'core/templates-query';

	/**
	 * The ability category. Registered by WordPress core.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'site';

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Hooked at priority 11, after core, so the ability can stand down when core provides it.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the `core/templates-query` ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook. Registers nothing when the ability
	 * already exists, so a version that core provides stays in effect.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			return;
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Query Templates', 'ai' ),
				'description'         => __( 'Returns whether the active theme is a block theme, its name, and the block templates the site has for it. Each template has its slug, its title, where its current version comes from (theme, custom or plugin), and whether the theme ships it as a file. A template with the source "custom" has been created or edited by a user. Template parts are not included. Requires an authenticated user who can edit theme options.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => function (): array {
					return $this->execute_templates_query();
				},
				'permission_callback' => function (): bool {
					return $this->check_permission();
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'       => true,
					// Set explicitly so the ability is exposed over REST on all supported WordPress versions.
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the `core/templates-query` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array{block_theme: bool, theme: string, templates: list<array{slug: string, title: string, source: string, has_theme_file: bool}>} The theme and its templates.
	 */
	private function execute_templates_query(): array {
		return array(
			'block_theme' => wp_is_block_theme(),
			'theme'       => (string) wp_get_theme()->get( 'Name' ),
			'templates'   => $this->get_templates(),
		);
	}

	/**
	 * Returns the active theme's templates, sorted by slug.
	 *
	 * Sorted so the order does not depend on which templates a user has edited:
	 * get_block_templates() lists edited templates before the theme's files.
	 *
	 * @since x.x.x
	 *
	 * @return list<array{slug: string, title: string, source: string, has_theme_file: bool}> The templates.
	 */
	private function get_templates(): array {
		$templates = array_map( array( $this, 'format_template' ), get_block_templates( array(), 'wp_template' ) );

		usort(
			$templates,
			static function ( array $a, array $b ): int {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		return $templates;
	}

	/**
	 * Formats one template for the ability output.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Block_Template $template The template.
	 * @return array{slug: string, title: string, source: string, has_theme_file: bool} The template fields the ability returns.
	 */
	private function format_template( WP_Block_Template $template ): array {
		return array(
			'slug'           => (string) $template->slug,
			'title'          => (string) $template->title,
			'source'         => (string) $template->source,
			'has_theme_file' => (bool) $template->has_theme_file,
		);
	}

	/**
	 * Permission callback for the ability.
	 *
	 * Templates change how the whole site looks, so reading them is gated on the
	 * capability the Site Editor requires.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the current user can edit theme options.
	 */
	private function check_permission(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Builds the input schema: the ability takes no input.
	 *
	 * An empty object is accepted, so a client that always sends an object can call it.
	 *
	 * The schema has no `properties` key: an empty PHP array is encoded as the JSON array
	 * `[]`, which JSON Schema validators such as the one in `@wordpress/abilities` reject.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds the output schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'block_theme' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the active theme is a block theme. Block templates can only be used with a block theme.', 'ai' ),
				),
				'theme'       => array(
					'type'        => 'string',
					'description' => __( 'The name of the active theme.', 'ai' ),
				),
				'templates'   => array(
					'type'        => 'array',
					'description' => __( 'The block templates the site has for the active theme, sorted by slug.', 'ai' ),
					'items'       => $this->get_template_schema(),
				),
			),
			'required'             => array( 'block_theme', 'theme', 'templates' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds the schema of one template in the output.
	 *
	 * The source is not limited to an enum: a template's source comes from core and from
	 * filters, and an unexpected value should not fail the whole response.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The template JSON Schema.
	 */
	private function get_template_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'           => array(
					'type'        => 'string',
					'description' => __( 'The template slug, such as "404", "single" or "page-about".', 'ai' ),
				),
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'The template title.', 'ai' ),
				),
				'source'         => array(
					'type'        => 'string',
					'description' => __( 'Where the current version of the template comes from: "theme" for the theme\'s file, "custom" for a version a user created or edited, or "plugin" for a template a plugin registered.', 'ai' ),
				),
				'has_theme_file' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the active theme ships this template as a file. When a "custom" template has a theme file, resetting it in the Site Editor brings back the theme\'s version.', 'ai' ),
				),
			),
			'required'             => array( 'slug', 'title', 'source', 'has_theme_file' ),
			'additionalProperties' => false,
		);
	}
}
