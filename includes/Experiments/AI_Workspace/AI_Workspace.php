<?php
/**
 * AI Workspace experiment.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WordPress\AI\Abilities\Content\Read_Content_Bodies;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Experiments\AI_Workspace\REST\Stream_Responder;
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
	 * Script handle for the block editor handoff, without the Asset_Loader prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const EDITOR_ASSET_HANDLE = 'workspace_editor';

	/**
	 * Built handoff asset path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const EDITOR_ASSET_PATH = 'experiments/ai-workspace-editor';

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
		 * The reading half of retrieval. Search finds posts; this returns the bodies
		 * of a handful of them, filtered row by row at execute time. It is registered
		 * here rather than leaning on `core/read-content`, which belongs to the Custom
		 * Abilities experiment: the workspace's reach must not change when a different
		 * experiment is switched off.
		 */
		( new Read_Content_Bodies() )->init();

		/*
		 * The model's reach toward the write path. It stores a proposal and
		 * writes nothing; it is withheld from the REST and MCP surfaces because
		 * a proposal is only meaningful where the person who approves it is.
		 */
		( new Propose_Drafts() )->init();

		/*
		 * The turn endpoint is the only route through which the workspace reaches
		 * site content, and it is capability checked on every request.
		 */
		( new Turn_Controller() )->init();

		/*
		 * The transcript's transport. The turn route writes no output itself; this
		 * consumer of its emitter filter turns a turn into server-sent events when
		 * the client asks for them, and stays silent otherwise so the route answers
		 * with its ordinary JSON body.
		 */
		( new Stream_Responder() )->init();

		/*
		 * The confirm gate. This controller holds the only code path in the
		 * feature that creates content, and it runs only after a person has
		 * approved the stored resolved values of a proposal they own.
		 */
		( new Proposal_Controller() )->init();

		/*
		 * The way in from the post editor. It is registered here rather than on
		 * the admin page, because the action lives on a screen the admin page
		 * never loads on.
		 */
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Enqueues the block editor handoff action on the post editing screens.
	 *
	 * The action only navigates: it carries the post's identity to the
	 * workspace and nothing else, so the workspace reads that post's body
	 * through the same permission-checked tool path as any other content.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_editor_assets( string $hook_suffix ): void {
		// Load the action on the post editing screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		/*
		 * The workspace screen is gated on `manage_options`, so a user who
		 * cannot open it is never offered the action that leads there.
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Asset_Loader::enqueue_script( self::EDITOR_ASSET_HANDLE, self::EDITOR_ASSET_PATH );
		Asset_Loader::localize_script(
			self::EDITOR_ASSET_HANDLE,
			'WorkspaceHandoff',
			array(
				'workspaceUrl' => Admin_Page::url(),
				'postArg'      => Admin_Page::POST_QUERY_ARG,
			)
		);
	}
}
