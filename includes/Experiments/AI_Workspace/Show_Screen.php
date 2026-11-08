<?php
/**
 * The `ai/show-screen` WordPress Ability.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the `ai/show-screen` ability, which shows the person an A2UI screen.
 *
 * Experimental. The model describes a small screen as A2UI v0.9 messages
 * (https://a2ui.org/), and the transcript draws it with the WordPress component
 * library through `@swissspidy/a2ui-wp`. Like {@see Ask_User}, the ability does
 * nothing on the server and ends the turn: a button press on the screen arrives
 * as the person's next message, so the model is never told the person did
 * something they did not.
 *
 * A screen only shows and asks. Anything that would change the site still goes
 * through a proposal and its approval card, which the plugin draws itself. The
 * screen is never the place a write is approved.
 *
 * The checks here are the server's part of keeping a model-authored screen
 * harmless: a size limit, the basic catalog only, and no media components,
 * whose URLs would let the model make the browser fetch any address. The
 * client checks again, and limits `openUrl` to this site.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Show_Screen {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/show-screen';

	/**
	 * Most messages one screen may be built from.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_MESSAGES = 20;

	/**
	 * Most bytes the messages may take, encoded as JSON.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_BYTES = 32768;

	/**
	 * The protocol versions accepted.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	public const VERSIONS = array( 'v0.9', 'v0.9.1' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * The catalog IDs accepted: the A2UI basic catalog, under both of its IDs.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	public const CATALOG_IDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json',
		'https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json',
	);

	/**
	 * The component types a screen may use.
	 *
	 * The basic catalog without `Image`, `Video` and `AudioPlayer`.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	public const COMPONENTS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'Text',
		'Icon',
		'Row',
		'Column',
		'List',
		'Card',
		'Tabs',
		'Modal',
		'Divider',
		'Button',
		'TextField',
		'CheckBox',
		'ChoicePicker',
		'Slider',
		'DateTimeInput',
	);

	/**
	 * The message types a screen may send. `deleteSurface` has no use in a
	 * screen that is drawn once.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const MESSAGE_TYPES = array( 'createSurface', 'updateComponents', 'updateDataModel' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Show a Screen', 'ai' ),
				'description'         => __( 'Shows the person a small screen built from A2UI v0.9.1 messages: a card, a summary, tabs, or a short form with buttons. Use it when an answer reads better as a layout than as text. Showing a screen ends your turn: a button press arrives as the person\'s next message.', 'ai' ),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_show_screen' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					// Withheld from REST and MCP, like the question tool: a
					// screen is only meaningful where the person who sees it is.
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Permission callback for the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True when the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The messages, or an error.
	 */
	public function execute_show_screen( $input = array() ) {
		if ( ! Turn_Context::is_active() ) {
			return new WP_Error(
				'workspace_context_required',
				__( 'A screen can only be shown inside an AI Workspace conversation, where a person can see it.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$input    = rest_sanitize_object( $input );
		$messages = isset( $input['messages'] ) && is_array( $input['messages'] ) ? array_values( $input['messages'] ) : array();
		$valid    = self::validate_messages( $messages );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return array(
			'messages' => $messages,
			'note'     => __( 'Your turn has ended. The person sees the screen, and a button press arrives as their next message.', 'ai' ),
		);
	}

	/**
	 * Checks a screen's messages.
	 *
	 * The error messages go back to the model, so each one says what to change.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, mixed> $messages The A2UI messages.
	 * @return true|\WP_Error True when the screen may be shown, or why not.
	 */
	public static function validate_messages( array $messages ) {
		if ( array() === $messages || count( $messages ) > self::MAX_MESSAGES ) {
			return self::invalid(
				sprintf(
					/* translators: %d: the most messages a screen may use. */
					__( 'A screen needs between 1 and %d messages.', 'ai' ),
					self::MAX_MESSAGES
				)
			);
		}

		$encoded = wp_json_encode( $messages );

		if ( false === $encoded || strlen( $encoded ) > self::MAX_BYTES ) {
			return self::invalid( __( 'The screen is too large. Show less, or summarize it in text.', 'ai' ) );
		}

		$creates = 0;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['version'] ) || ! in_array( $message['version'], self::VERSIONS, true ) ) {
				return self::invalid( __( 'Every message needs "version": "v0.9.1".', 'ai' ) );
			}

			$types = array_values( array_intersect( array_keys( $message ), self::MESSAGE_TYPES ) );

			if ( 1 !== count( $types ) || 2 !== count( $message ) ) {
				return self::invalid( __( 'Every message needs exactly one of createSurface, updateComponents or updateDataModel, and nothing else besides its version.', 'ai' ) );
			}

			$body = $message[ $types[0] ];

			if ( ! is_array( $body ) ) {
				return self::invalid( __( 'A message body must be an object.', 'ai' ) );
			}

			if ( 'createSurface' === $types[0] ) {
				++$creates;

				if ( ! isset( $body['catalogId'] ) || ! in_array( $body['catalogId'], self::CATALOG_IDS, true ) ) {
					return self::invalid( __( 'createSurface must use the basic catalog, https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json.', 'ai' ) );
				}
			}

			if ( 'updateComponents' !== $types[0] ) {
				continue;
			}

			$components = isset( $body['components'] ) && is_array( $body['components'] ) ? $body['components'] : array();

			foreach ( $components as $component ) {
				$type = is_array( $component ) && isset( $component['component'] ) ? $component['component'] : null;

				if ( ! is_string( $type ) || ! in_array( $type, self::COMPONENTS, true ) ) {
					return self::invalid(
						sprintf(
							/* translators: %s: the component types a screen may use. */
							__( 'A screen may only use these components: %s.', 'ai' ),
							implode( ', ', self::COMPONENTS )
						)
					);
				}
			}
		}

		if ( 1 !== $creates ) {
			return self::invalid( __( 'A screen needs exactly one createSurface message, sent first.', 'ai' ) );
		}

		return true;
	}

	/**
	 * Returns the A2UI guide added to the system instruction when the tool is offered.
	 *
	 * Adapted from the agent instructions in swissspidy/a2ui-wp (GPL-2.0-or-later),
	 * by Pascal Birchler, without the media components and with real site data
	 * instead of sample data.
	 *
	 * @since x.x.x
	 *
	 * @return string The guide.
	 */
	public static function get_instructions(): string {
		return <<<'GUIDE'
To show something that reads better as a layout than as text, such as a summary card, a comparison in tabs, or a short form with buttons, call the screen tool with A2UI v0.9.1 messages. Keep it small and use only data you were given or looked up: never invent figures. A screen with buttons also counts as a next step. Showing a screen ends your turn, and a button press arrives as the person's next message, as "[screen]", the event name, the button text in double quotes, then the event context as JSON. A screen never changes the site: to change anything, propose it with a proposal tool afterwards.

Each message is an object with "version": "v0.9.1" and exactly one of createSurface, updateComponents or updateDataModel. Send createSurface first: {"version": "v0.9.1", "createSurface": {"surfaceId": "main", "catalogId": "https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json"}}. Then {"version": "v0.9.1", "updateComponents": {"surfaceId": "main", "components": [...]}}, and optionally {"version": "v0.9.1", "updateDataModel": {"surfaceId": "main", "value": {...}}}.

Components are a flat list. Each has a unique "id" and a "component" type and refers to others by id. One must have the id "root". Dynamic values are a literal, a binding {"path": "/form/title"}, or a call {"call": "formatString", "args": {"value": "Hello ${/user/name}"}, "returnType": "string"}. Functions: formatString, formatNumber, formatCurrency, formatDate, pluralize, required, email, regex, length, numeric, and, or, not.

The components: Text (text, variant h1-h5, body or caption); Icon (name); Row and Column (children, justify, align); List (children, direction); Card (child); Tabs (tabs: a list of {"title", "child"}); Modal (trigger, content); Divider; Button (child: the id of a Text, variant default, primary or borderless, action, optional checks); TextField (label, value as a binding, variant shortText, longText, number or obscured); CheckBox (label, value); ChoicePicker (label, options: a list of {"label", "value"}, value as a binding to a list, variant mutuallyExclusive or multipleSelection); Slider (label, min, max, value); DateTimeInput (label, value, enableDate, enableTime). children is a list of ids, or a template {"path": "/items", "componentId": "row"} rendered once per item, where paths without a leading slash are relative to the item. No images, video or audio.

A Button action is {"event": {"name": "choose_post", "context": {"id": {"path": "/selected"}}}}. Inputs bind their value to a path, and the button sends those paths in its event context.
GUIDE;
	}

	/**
	 * Builds the error returned for an invalid screen.
	 *
	 * @since x.x.x
	 *
	 * @param string $message What is wrong, for the model.
	 * @return \WP_Error The error.
	 */
	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'invalid_screen', $message, array( 'status' => 400 ) );
	}

	/**
	 * Returns the ability's input schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input schema.
	 */
	private function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'messages' => array(
					'type'        => 'array',
					'description' => __( 'The A2UI v0.9.1 messages that build the screen, createSurface first.', 'ai' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_MESSAGES,
					'items'       => array(
						'type' => 'object',
					),
				),
			),
			'required'   => array( 'messages' ),
		);
	}

	/**
	 * Returns the ability's output schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema.
	 */
	private function get_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'messages' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'note'     => array( 'type' => 'string' ),
			),
		);
	}
}
