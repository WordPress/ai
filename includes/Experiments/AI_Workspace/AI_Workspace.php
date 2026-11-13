<?php
/**
 * AI Workspace experiment.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WordPress\AI\Abilities\Command_Palette_AI_Search\Command_Palette_AI_Search;
use WordPress\AI\Abilities\Content\List_Terms;
use WordPress\AI\Abilities\Content\Read_Content_Bodies;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abilities\Site\Find_Admin_Screens;
use WordPress\AI\Abilities\Site\Get_Site_Info;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\AI_Workspace\REST\Conversation_Controller;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Experiments\AI_Workspace\REST\Stream_Responder;
use WordPress\AI\Experiments\AI_Workspace\REST\Turn_Controller;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides a site-aware AI conversation surface on its own `Tools` screen.
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
	 * Script and style handle for the command palette loader, without the Asset_Loader prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PALETTE_ASSET_HANDLE = 'command_palette_ai_search';

	/**
	 * Built command palette loader path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PALETTE_ASSET_PATH = 'experiments/command-palette-ai-search';

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
			'description' => __( 'Adds a dedicated AI Workspace screen under Tools, where you hold a multi-step conversation about your own site. The assistant searches and reads your content under your own capabilities, and writes nothing until you approve a proposal showing the exact values, including the post status, that will be written.', 'ai' ),
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
		 * here rather than leaning on `core/content-query`, which belongs to the Custom
		 * Abilities experiment: the workspace's reach must not change when a different
		 * experiment is switched off.
		 */
		( new Read_Content_Bodies() )->init();

		/*
		 * What the site already covers: its categories and tags, with how many
		 * posts each holds. Registered here for the same reason as search, and
		 * until `core/terms-query` lands in this plugin.
		 */
		( new List_Terms() )->init();

		/*
		 * Facts about the site itself: name, tagline, language, timezone, formats
		 * and theme, with the WordPress and PHP versions for administrators only.
		 * Registered here for the same reason as search.
		 */
		( new Get_Site_Info() )->init();

		/*
		 * "Where do I…" answered with a link: a curated map of admin screens,
		 * matched on the server and filtered to the screens the user can open.
		 * Registered here for the same reason as search.
		 */
		( new Find_Admin_Screens() )->init();

		/*
		 * The model's reach toward the write path. It stores a proposal and
		 * writes nothing; it is withheld from the REST and MCP surfaces because
		 * a proposal is only meaningful where the person who approves it is.
		 */
		( new Propose_Drafts() )->init();

		/*
		 * The same reach for block templates. It stores one proposed template and
		 * writes nothing, and is withheld from the REST and MCP surfaces for the
		 * same reason.
		 */
		( new Propose_Template() )->init();

		/*
		 * The same reach for changes to existing posts' titles, excerpts and
		 * content. It stores the proposed values and writes nothing, and is
		 * withheld from the REST and MCP surfaces for the same reason.
		 */
		( new Propose_Post_Changes() )->init();

		/*
		 * The same reach for the site title and tagline. It stores the proposed
		 * values and writes nothing, and is withheld from the REST and MCP
		 * surfaces for the same reason.
		 */
		( new Propose_Site_Identity() )->init();

		/*
		 * Asks the person a question with choices, which ends the turn. It
		 * writes nothing and, like the proposal tool, is withheld from the REST
		 * and MCP surfaces.
		 */
		( new Ask_User() )->init();

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
		 * Chat history. Chats are private posts readable only by their author;
		 * the ownership and user-removal hooks that protect them are registered
		 * by the plugin itself, so they hold while this experiment is off too.
		 */
		Conversation_Store::register_post_type();
		( new Conversation_Controller() )->init();

		/*
		 * The way in from the post editor. It is registered here rather than on
		 * the admin page, because the action lives on a screen the admin page
		 * never loads on.
		 */
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );

		/*
		 * The command palette's AI fallback. When the palette finds nothing for
		 * a search, the loader asks the model which of the palette's own
		 * commands answer it. It runs after the palette is enqueued.
		 */
		add_action( 'wp_abilities_api_init', array( $this, 'register_command_palette_ability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_command_palette_assets' ), 20 );
	}

	/**
	 * Registers the command palette AI search ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register_command_palette_ability(): void {
		wp_register_ability(
			Command_Palette_AI_Search::ABILITY,
			array(
				'label'         => __( 'Command Palette AI Search', 'ai' ),
				'description'   => __( 'Suggests admin screens when the Command Palette (Cmd/Ctrl+K) has no results for a search, by choosing from the commands the palette sent.', 'ai' ),
				'category'      => WPAI_DEFAULT_ABILITY_CATEGORY,
				'ability_class' => Command_Palette_AI_Search::class,
				/*
				 * Set here rather than in the class's meta, because the MCP
				 * Adapter's exposure filter reads only the registration args.
				 * The ability makes its own model call over a list only the
				 * palette can send, so it is not offered over MCP.
				 */
				'meta'          => array(
					'mcp' => array( 'public' => false ),
				),
			)
		);
	}

	/**
	 * Adds the AI loader to the command palette.
	 *
	 * @since x.x.x
	 */
	public function enqueue_command_palette_assets(): void {
		// Only on screens that have the command palette, for users who can run the ability.
		if ( ! wp_script_is( 'wp-commands', 'enqueued' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		Asset_Loader::enqueue_script( self::PALETTE_ASSET_HANDLE, self::PALETTE_ASSET_PATH, array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( self::PALETTE_ASSET_HANDLE, self::PALETTE_ASSET_PATH, array( 'wp-commands' ) );

		/*
		 * The Ask AI row leads to the workspace, so it is offered only to users
		 * who pass the workspace screen's own check. Nobody else is told where
		 * the screen is. The settings are nested because wp_localize_script()
		 * turns top-level values into strings.
		 */
		$ask_ai = null;

		if ( current_user_can( 'manage_options' ) ) {
			$ask_ai = array(
				'workspaceUrl'    => Admin_Page::url(),
				'promptArg'       => Admin_Page::PROMPT_QUERY_ARG,
				'promptMaxLength' => Admin_Page::MAX_SEED_PROMPT_LENGTH,
			);
		}

		Asset_Loader::localize_script( self::PALETTE_ASSET_HANDLE, 'CommandPaletteAISearch', array( 'askAi' => $ask_ai ) );
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
