<?php
/**
 * Use cases offered by the Get started guide.
 *
 * @package WordPress\AI\Onboarding
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Onboarding;

use WordPress\AI\Features\Registry;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Defines the use cases a site owner can choose in the Get started guide.
 *
 * A use case is a named group of Features and Experiments that are turned on
 * together. A use case can carry one add-on: a tool that runs on its own once
 * enabled, which is offered separately so that it is never turned on by a
 * broader choice.
 *
 * @since x.x.x
 */
final class Use_Cases {

	/**
	 * The feature registry.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Returns the use cases, limited to the features that are registered.
	 *
	 * @since x.x.x
	 *
	 * @return list<array{
	 *   id: string,
	 *   label: string,
	 *   description: string,
	 *   sprite: string,
	 *   features: list<string>,
	 *   addon: array{id: string, label: string, description: string, features: list<string>, try: array{label: string, description: string, url: string, linkText: string}|null}|null,
	 *   try: array{label: string, description: string, url: string, linkText: string}|null
	 * }>
	 */
	public function get_all(): array {
		$use_cases = array(
			array(
				'id'          => 'write-publish',
				'label'       => __( 'Write and publish', 'ai' ),
				'description' => __( 'Titles, excerpts, descriptions, summaries', 'ai' ),
				'sprite'      => 'writing',
				'features'    => array(
					'title-generation',
					'excerpt-generation',
					'meta-description',
					'slug-generation',
					'summarization',
				),
				'addon'       => array(
					'id'          => 'type-ahead',
					'label'       => __( 'Also suggest text as I type', 'ai' ),
					'description' => __( 'Runs each time you pause in a paragraph', 'ai' ),
					'features'    => array( 'type-ahead' ),
					'try'         => array(
						'label'       => __( 'Suggestions as you type are on', 'ai' ),
						'description' => __( 'Pause while writing a paragraph and a suggestion appears in grey. Press Tab to keep it.', 'ai' ),
						'url'         => admin_url( 'post-new.php' ),
						'linkText'    => __( 'New post', 'ai' ),
					),
				),
				'try'         => array(
					'label'       => __( 'Get title ideas', 'ai' ),
					'description' => __( 'Open a post that has a few paragraphs, click the title, then choose Generate in its toolbar.', 'ai' ),
					'url'         => admin_url( 'edit.php' ),
					'linkText'    => __( 'Go to Posts', 'ai' ),
				),
			),
			array(
				'id'          => 'edit-translate',
				'label'       => __( 'Edit and translate', 'ai' ),
				'description' => __( 'Resize text, editing notes and one-click fixes, tags, translation', 'ai' ),
				'sprite'      => 'reading',
				'features'    => array(
					'content-resizing',
					'content-classification',
					'editorial-notes',
					'editorial-updates',
					'revision-change-summary',
					'content-translation',
				),
				'addon'       => null,
				'try'         => array(
					'label'       => __( 'Shorten or expand a paragraph', 'ai' ),
					'description' => __( 'Open a post, select a paragraph, then choose Resize Content in the block toolbar.', 'ai' ),
					'url'         => admin_url( 'edit.php' ),
					'linkText'    => __( 'Go to Posts', 'ai' ),
				),
			),
			array(
				'id'          => 'images-audio',
				'label'       => __( 'Images and audio', 'ai' ),
				'description' => __( 'Generate images, write alt text, turn posts into audio', 'ai' ),
				'sprite'      => 'painting',
				'features'    => array(
					'image-generation',
					'alt-text-generation',
					'text-to-speech',
				),
				'addon'       => null,
				'try'         => array(
					'label'       => __( 'Make an image', 'ai' ),
					'description' => __( 'Describe the image you want and add the result to your Media Library.', 'ai' ),
					'url'         => admin_url( 'upload.php?page=generate-image' ),
					'linkText'    => __( 'Generate an image', 'ai' ),
				),
			),
			array(
				'id'          => 'comments',
				'label'       => __( 'Comments', 'ai' ),
				'description' => __( 'Draft replies for you to edit and send', 'ai' ),
				'sprite'      => 'thinking',
				'features'    => array( 'suggest-reply' ),
				'addon'       => array(
					'id'          => 'comment-moderation',
					'label'       => __( 'Also check new comments automatically', 'ai' ),
					'description' => __( 'Every new comment, including those from visitors. Can hold comments for review.', 'ai' ),
					'features'    => array( 'comment-moderation' ),
					'try'         => array(
						'label'       => __( 'Comment moderation is running', 'ai' ),
						'description' => __( 'New comments are checked as they arrive. Results show in Comments.', 'ai' ),
						'url'         => admin_url( 'edit-comments.php' ),
						'linkText'    => __( 'View comments', 'ai' ),
					),
				),
				'try'         => array(
					'label'       => __( 'Draft a reply', 'ai' ),
					'description' => __( 'Hover a comment and choose Suggest Reply. You edit the draft before it is sent.', 'ai' ),
					'url'         => admin_url( 'edit-comments.php' ),
					'linkText'    => __( 'Go to Comments', 'ai' ),
				),
			),
		);

		/**
		 * Filters the use cases offered by the Get started guide on Settings > AI.
		 *
		 * Each use case turns on its `features` together. An optional `addon`
		 * holds tools that run on their own, and is offered as a separate,
		 * unticked choice under its use case. Features that are not registered
		 * are removed, and a use case left with no features is not shown.
		 *
		 * @since x.x.x
		 *
		 * @param list<array<string, mixed>> $use_cases The use case definitions.
		 */
		$use_cases = apply_filters( 'wpai_onboarding_use_cases', $use_cases );

		if ( ! is_array( $use_cases ) ) {
			return array();
		}

		$normalized = array();
		$seen_ids   = array();

		foreach ( $use_cases as $use_case ) {
			if ( ! is_array( $use_case ) ) {
				continue;
			}

			$id = $this->to_string( $use_case['id'] ?? '' );
			if ( '' === $id || isset( $seen_ids[ $id ] ) ) {
				continue;
			}

			$features = $this->filter_registered_features( $use_case['features'] ?? array() );
			if ( empty( $features ) ) {
				continue;
			}

			$addon = $this->normalize_addon( $use_case['addon'] ?? null, $seen_ids + array( $id => true ) );

			$seen_ids[ $id ] = true;
			if ( null !== $addon ) {
				$seen_ids[ $addon['id'] ] = true;
			}

			$normalized[] = array(
				'id'          => $id,
				'label'       => $this->to_string( $use_case['label'] ?? '' ),
				'description' => $this->to_string( $use_case['description'] ?? '' ),
				'sprite'      => sanitize_key( $this->to_string( $use_case['sprite'] ?? '' ) ),
				'features'    => $features,
				'addon'       => $addon,
				'try'         => $this->normalize_try( $use_case['try'] ?? null ),
			);
		}

		return $normalized;
	}

	/**
	 * Returns every ID that can be stored as a chosen use case or add-on.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Use case and add-on IDs.
	 */
	public function get_selectable_ids(): array {
		$ids = array();

		foreach ( $this->get_all() as $use_case ) {
			$ids[] = $use_case['id'];

			if ( null === $use_case['addon'] ) {
				continue;
			}

			$ids[] = $use_case['addon']['id'];
		}

		return $ids;
	}

	/**
	 * Normalizes an add-on definition.
	 *
	 * @since x.x.x
	 *
	 * @param mixed               $addon    The raw add-on definition.
	 * @param array<string, bool> $used_ids IDs that are already taken.
	 * @return array{id: string, label: string, description: string, features: list<string>, try: array{label: string, description: string, url: string, linkText: string}|null}|null
	 */
	private function normalize_addon( $addon, array $used_ids ): ?array {
		if ( ! is_array( $addon ) ) {
			return null;
		}

		$id = $this->to_string( $addon['id'] ?? '' );
		if ( '' === $id || isset( $used_ids[ $id ] ) ) {
			return null;
		}

		$features = $this->filter_registered_features( $addon['features'] ?? array() );
		if ( empty( $features ) ) {
			return null;
		}

		return array(
			'id'          => $id,
			'label'       => $this->to_string( $addon['label'] ?? '' ),
			'description' => $this->to_string( $addon['description'] ?? '' ),
			'features'    => $features,
			'try'         => $this->normalize_try( $addon['try'] ?? null ),
		);
	}

	/**
	 * Normalizes a "try it" pointer.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $pointer The raw pointer definition.
	 * @return array{label: string, description: string, url: string, linkText: string}|null
	 */
	private function normalize_try( $pointer ): ?array {
		if ( ! is_array( $pointer ) ) {
			return null;
		}

		$label = $this->to_string( $pointer['label'] ?? '' );
		$url   = esc_url_raw( $this->to_string( $pointer['url'] ?? '' ) );

		if ( '' === $label || '' === $url ) {
			return null;
		}

		return array(
			'label'       => $label,
			'description' => $this->to_string( $pointer['description'] ?? '' ),
			'url'         => $url,
			'linkText'    => $this->to_string( $pointer['linkText'] ?? '' ),
		);
	}

	/**
	 * Keeps the feature IDs that are registered, in the order given.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $feature_ids The raw feature IDs.
	 * @return list<string> Registered feature IDs.
	 */
	private function filter_registered_features( $feature_ids ): array {
		if ( ! is_array( $feature_ids ) ) {
			return array();
		}

		$registered = array();

		foreach ( $feature_ids as $feature_id ) {
			if ( ! is_string( $feature_id ) || ! $this->registry->has_feature( $feature_id ) ) {
				continue;
			}

			if ( in_array( $feature_id, $registered, true ) ) {
				continue;
			}

			$registered[] = $feature_id;
		}

		return $registered;
	}

	/**
	 * Returns the value when it is a string, or an empty string otherwise.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The value to check.
	 * @return string The string value.
	 */
	private function to_string( $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
