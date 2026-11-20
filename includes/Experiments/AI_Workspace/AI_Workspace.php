<?php
/**
 * AI Workspace experiment.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WordPress\AI\Abilities\AI_Workspace\Ask_User;
use WordPress\AI\Abilities\AI_Workspace\Propose_Drafts;
use WordPress\AI\Abilities\AI_Workspace\Propose_Post_Changes;
use WordPress\AI\Abilities\AI_Workspace\Propose_Site_Identity;
use WordPress\AI\Abilities\AI_Workspace\Propose_Template;
use WordPress\AI\Abilities\Command_Palette_Search\Command_Palette_Search;
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
 * Provides a site-aware AI conversation surface on its own admin screen.
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
	private const PALETTE_ASSET_HANDLE = 'command_palette_search';

	/**
	 * Built command palette loader path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PALETTE_ASSET_PATH = 'experiments/command-palette-search';

	/**
	 * Admin page instance, created during register().
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Admin_Page|null
	 */
	private ?Admin_Page $admin_page = null;

	/**
	 * The workspace's tools: the reads, the proposal kinds and the question tool.
	 *
	 * @since x.x.x
	 *
	 * @var list<\WordPress\AI\Abilities\Content\Search_Content|\WordPress\AI\Abilities\Content\Read_Content_Bodies|\WordPress\AI\Abilities\Site\Get_Site_Info|\WordPress\AI\Abilities\Site\Find_Admin_Screens|\WordPress\AI\Abilities\AI_Workspace\Propose_Drafts|\WordPress\AI\Abilities\AI_Workspace\Propose_Template|\WordPress\AI\Abilities\AI_Workspace\Propose_Post_Changes|\WordPress\AI\Abilities\AI_Workspace\Propose_Site_Identity|\WordPress\AI\Abilities\AI_Workspace\Ask_User>
	 */
	private array $tools = array();

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
			'label'          => __( 'AI Workspace', 'ai' ),
			'description'    => __( 'Adds a dedicated Workspace screen where you can hold a multi-step conversation about your own site. The assistant searches and reads your content using your capabilities, answers questions about the site, and will change nothing until you approve a proposal showing the exact values that will be written: new drafts and templates, or changes to a post or the site title and tagline. It also adds AI suggestions and an "Ask in AI Workspace" row to the Command Palette.', 'ai' ),
			'category'       => Experiment_Category::ADMIN,
			'access_control' => true,
			'capability'     => 'text_generation',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// Must run before the search ability builds its schema from `show_in_abilities` post types.
		( new Show_In_Abilities() )->register();

		// Registered here, not behind Custom Abilities, so the workspace's tools don't depend on another experiment.
		$search = new Search_Content();
		$search->init();

		$read = new Read_Content_Bodies();
		$read->init();

		$drafts = new Propose_Drafts();
		$drafts->init();

		$template = new Propose_Template();
		$template->init();

		$post_changes = new Propose_Post_Changes();
		$post_changes->init();

		$site_identity = new Propose_Site_Identity();
		$ask_user      = new Ask_User();

		$this->tools = array( $search, $read, new Get_Site_Info(), new Find_Admin_Screens(), $drafts, $template, $post_changes, $site_identity, $ask_user );
		add_action( 'wp_abilities_api_init', array( $this, 'register_tool_abilities' ), 11 );

		( new Turn_Controller() )->init();
		( new Stream_Responder() )->init();
		( new Proposal_Controller() )->init();

		// Ownership and user-removal hooks are registered by `Main`, so they hold while this is off.
		Conversation_Store::register_post_type();
		( new Conversation_Controller() )->init();

		add_action( 'wp_abilities_api_init', array( $this, 'register_command_palette_ability' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register_ui(): void {
		$this->admin_page = new Admin_Page();
		$this->admin_page->register();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );

		// Priority 20: after the palette is enqueued.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_command_palette_assets' ), 20 );
	}

	/**
	 * Registers the workspace's tool abilities.
	 *
	 * @since x.x.x
	 */
	public function register_tool_abilities(): void {
		foreach ( $this->tools as $tool ) {
			$args = $tool->get_ability_args();

			if ( null === $args ) {
				continue;
			}

			$this->register_feature_ability( $tool::ABILITY, $args );
		}
	}

	/**
	 * Registers the command palette search ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register_command_palette_ability(): void {
		$this->register_feature_ability(
			Command_Palette_Search::ABILITY,
			array(
				'label'         => __( 'Command Palette Search', 'ai' ),
				'description'   => __( 'Suggests admin screens when the Command Palette (Cmd/Ctrl+K) has no results for a search, by choosing from the commands the palette sent.', 'ai' ),
				'category'      => WPAI_DEFAULT_ABILITY_CATEGORY,
				'ability_class' => Command_Palette_Search::class,
				// Set here, not in the class's meta: the MCP Adapter reads only registration args.
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
		if ( ! wp_script_is( 'wp-commands', 'enqueued' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		Asset_Loader::enqueue_script( self::PALETTE_ASSET_HANDLE, self::PALETTE_ASSET_PATH, array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( self::PALETTE_ASSET_HANDLE, self::PALETTE_ASSET_PATH, array( 'wp-commands' ) );

		// Nested because wp_localize_script() stringifies top-level values.
		$ask_ai = null;

		if ( current_user_can( 'manage_options' ) ) {
			$ask_ai = array(
				'workspaceUrl'    => Admin_Page::url(),
				'promptArg'       => Admin_Page::PROMPT_QUERY_ARG,
				'promptMaxLength' => Admin_Page::MAX_SEED_PROMPT_LENGTH,
			);
		}

		Asset_Loader::localize_script( self::PALETTE_ASSET_HANDLE, 'CommandPaletteSearch', array( 'askAi' => $ask_ai ) );
	}

	/**
	 * Enqueues the block editor handoff action on the post editing screens.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_editor_assets( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		// Matches the workspace screen's own capability.
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
