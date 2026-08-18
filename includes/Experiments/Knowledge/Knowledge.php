<?php
/**
 * Knowledge and Guidelines experiment.
 *
 * @package WordPress\AI\Experiments\Knowledge
 *
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Knowledge;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Knowledge and Guidelines experiment.
 *
 * Provides the `wp_knowledge` storage layer and the Settings → Guidelines page
 * that plugins and AI features read site guidelines from.
 *
 * The Gutenberg plugin ships the same feature behind its `gutenberg-guidelines`
 * experiment flag. Both copies share one contract — the `wp_knowledge` post
 * type, the `wp_knowledge_type` taxonomy, the `/wp/v2/knowledge` routes, and the
 * unprefixed `wp_*` functions in knowledge-functions.php — so only one of them
 * may provide it. The rule is "first one to declare it wins": this experiment
 * checks what is already there and stands down when another plugin got there
 * first. Because Gutenberg registers during `init` priority 10 and this
 * experiment runs at priority 15, Gutenberg wins whenever its flag is on.
 *
 * Extending the registries is a separate concern. Anything that only wants to
 * add a scope or a knowledge type should use the `wp_guideline_scopes` and
 * `wp_knowledge_types` filters, which work no matter who owns the base
 * implementation.
 *
 * @since 1.3.0
 */
class Knowledge extends Abstract_Feature {

	/**
	 * Whether this plugin owns the knowledge implementation on this request.
	 *
	 * @since 1.3.0
	 *
	 * @var bool
	 */
	private bool $owns_knowledge = false;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'knowledge';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Knowledge and Guidelines', 'ai' ),
			'description' => __( 'Store site guidelines that AI features use as context, and manage them under Settings → Guidelines.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Runs during `init` priority 15, which is a valid moment to register a post
	 * type and late enough for Gutenberg's priority 10 registration to have
	 * happened already.
	 */
	public function register(): void {
		// The shared `wp_*` functions. Each one is guarded by `function_exists()`.
		require_once __DIR__ . '/knowledge-functions.php';

		$this->owns_knowledge = Knowledge_Post_Type::register();

		/*
		 * Everything below belongs to whoever registered the post type. When
		 * Gutenberg owns it, it also serves the scopes route and the Settings
		 * page, so registering ours as well would duplicate both.
		 */
		if ( ! $this->owns_knowledge ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( ! is_admin() ) {
			return;
		}

		( new Admin_Page() )->init();
	}

	/**
	 * Whether this plugin registered the knowledge implementation.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when this plugin owns it, false when another plugin does.
	 */
	public function owns_knowledge(): bool {
		return $this->owns_knowledge;
	}

	/**
	 * Registers the read-only guideline scopes registry route.
	 *
	 * It sits beside the standard CPT routes. Guideline data itself is read and
	 * written through the standard `/wp/v2/knowledge` collection. Scope rows are
	 * identified by the `guideline-` slug prefix and the `guideline` knowledge
	 * type (see the reservation guard in knowledge-functions.php).
	 *
	 * @since 1.3.0
	 *
	 * @internal Used in the rest_api_init action.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new Guideline_Scopes_REST_Controller() )->register_routes();
	}
}
