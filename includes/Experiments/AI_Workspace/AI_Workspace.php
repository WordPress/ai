<?php
/**
 * AI Workspace experiment.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\AI_Workspace\REST\Turn_Controller;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides a full-screen, site-aware AI conversation surface in wp-admin.
 *
 * The experiment owns nothing but the admin screen registration; the screen
 * itself is rendered by a React application mounted into the page's root node.
 *
 * @since x.x.x
 */
class AI_Workspace extends Abstract_Feature {

	/**
	 * Admin page instance, created during register().
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Admin_Page|null
	 */
	private ?Admin_Page $admin_page = null;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'ai-workspace';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'AI Workspace', 'ai' ),
			'description' => __( 'Hold a multi-step, site-aware conversation with an AI assistant in a full-screen workspace. The assistant reads your content under your own capabilities and only creates or updates content after you approve it.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'text_generation',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->admin_page = new Admin_Page();
		$this->admin_page->register();

		/*
		 * The search ability queries the post types marked `show_in_abilities`, so the
		 * curated core post types have to be exposed before the ability registers and
		 * builds its input schema. This runs before `wp_abilities_api_init`, and is
		 * safe to run alongside the Custom Abilities experiment, which exposes the same
		 * objects: both paths only fill the flag in when it is not already set.
		 */
		( new Show_In_Abilities() )->register();

		/*
		 * Registered here rather than behind the Custom Abilities experiment so the
		 * workspace always has its search tool, and so every other ability consumer —
		 * the MCP surface and the Abilities Explorer — can reach it too.
		 */
		( new Search_Content() )->init();

		/*
		 * The turn endpoint is the only route through which the workspace reaches
		 * site content, and it is capability checked on every request.
		 */
		( new Turn_Controller() )->init();
	}
}
