<?php
/**
 * Privacy integration.
 *
 * @package WordPress\AI\Privacy
 */

declare( strict_types=1 );

namespace WordPress\AI\Privacy;

use WordPress\AI\Logging\Log_Data_Extractor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers suggested privacy policy text and the personal data tools.
 *
 * @since x.x.x
 */
class Privacy {

	/**
	 * Registers the privacy hooks.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		( new Request_Log_Personal_Data() )->init();
	}

	/**
	 * Adds the plugin's suggested text to the Privacy Policy Guide.
	 *
	 * @since x.x.x
	 */
	public function add_privacy_policy_content(): void {
		wp_add_privacy_policy_content( __( 'AI', 'ai' ), $this->get_privacy_policy_content() );
	}

	/**
	 * Returns the suggested privacy policy text.
	 *
	 * The text is the same whichever experiments are enabled, because WordPress
	 * flags the Privacy Policy Guide as changed whenever suggested text changes.
	 *
	 * @since x.x.x
	 *
	 * @return string The suggested text as HTML.
	 */
	public function get_privacy_policy_content(): string {
		$suggested = '<strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'ai' ) . ' </strong>';

		$content = '<div class="wp-suggested-text">';

		$content .= '<p class="privacy-policy-tutorial">' . __( 'The AI plugin sends content to the AI provider(s) configured under Settings &rarr; Connectors when one of its features or experiments is used. Nothing is sent until a feature or experiment is enabled. Each section below applies only when the experiment it names is enabled; include the sections that match your settings.', 'ai' ) . '</p>';
		$content .= '<p class="privacy-policy-tutorial">' . __( 'List the AI providers your site uses and link to each provider&#8217;s privacy policy, so visitors can see how those providers handle the data they receive.', 'ai' ) . '</p>';

		$content .= '<h3 class="wp-block-heading">' . __( 'Content sent to AI providers', 'ai' ) . '</h3>';
		$content .= '<p>' . $suggested . __( 'This site uses third-party AI services to help create and manage content. When these tools are used, the content involved is sent to the AI provider for processing.', 'ai' ) . '</p>';

		$content .= '<h3 class="wp-block-heading">' . __( 'Comments', 'ai' ) . '</h3>';
		$content .= '<p class="privacy-policy-tutorial">' . __( 'Applies only when the Comment Moderation or Suggest Reply experiment is enabled. Comment Moderation checks each new comment automatically when it is submitted, including comments from visitors who are not logged in unless that option is turned off. Suggest Reply runs only when a moderator asks for a reply.', 'ai' ) . '</p>';
		$content .= '<p>' . $suggested . __( 'When you leave a comment, the name you entered, the text of your comment and a short excerpt of the post may be sent to an AI service, which rates the comment&#8217;s tone and relevance. These ratings are stored with your comment, and a comment rated as abusive may be held for review. Your email address and IP address are not sent. Site moderators may also send your name and comment, with the post&#8217;s title and excerpt, to an AI service to draft a reply.', 'ai' ) . '</p>';

		$content .= '<h3 class="wp-block-heading">' . __( 'Content created by site users', 'ai' ) . '</h3>';
		$content .= '<p class="privacy-policy-tutorial">' . __( 'Applies only when Image Generation or an editor or admin experiment is enabled, such as Title, Excerpt, Summarization, Content Resizing, Content Translation, Content Classification, Editorial Notes, Editorial Updates, Meta Description, Slug Generation, Type-ahead or Alt Text Generation. Most of these run when a user asks for a suggestion; Type-ahead sends text automatically while the user types.', 'ai' ) . '</p>';
		$content .= '<p>' . $suggested . __( 'Users who write or edit content on this site can use AI tools that send the content they are working on to an AI service to suggest titles, excerpts, summaries, translations, image descriptions, images and other edits. This can include the post title and content, the name of the post&#8217;s author, and images they choose, including any information stored in the image file.', 'ai' ) . '</p>';

		$content .= '<h3 class="wp-block-heading">' . __( 'AI request logs', 'ai' ) . '</h3>';
		$content .= '<p class="privacy-policy-tutorial">' . __( 'Applies only when the AI Request Logging experiment is enabled, or while logs recorded before it was disabled remain. While the experiment is disabled, those logs are not deleted automatically and cannot be deleted from Tools &rarr; AI Request Logs; enable the experiment again to delete them.', 'ai' ) . '</p>';
		$content .= '<p class="privacy-policy-tutorial">' . sprintf(
			/* translators: %s: name of the retention filter. */
			__( 'Logs are kept indefinitely unless a retention period is set with the %s filter. Administrators can delete older logs, or all logs, from Tools &rarr; AI Request Logs. If you set a retention period, state it in your policy.', 'ai' ),
			'<code>wpai_request_log_retention_days</code>'
		) . '</p>';
		$content .= '<p>' . $suggested . sprintf(
			/* translators: %s: maximum number of characters stored from each request and response. */
			__( 'This site keeps a log of AI requests. Each entry records the date, the AI provider and model used, the result, the account of the logged-in user who made the request, and up to %s characters of the request and of the response. Because requests can include comments, a log entry can include a comment and the name of its author.', 'ai' ),
			number_format_i18n( Log_Data_Extractor::PAYLOAD_PREVIEW_LIMIT )
		) . '</p>';
		$content .= '<p>' . $suggested . __( 'If you have an account on this site, you can request an export or erasure of the log entries linked to it. Entries for requests made by visitors who were not logged in, such as checks on their comments, are not linked to an account and cannot be found by email address. They are removed when the logs are deleted.', 'ai' ) . '</p>';

		$content .= '<h3 class="wp-block-heading">' . __( 'Experiments that keep data on this site', 'ai' ) . '</h3>';
		$content .= '<p class="privacy-policy-tutorial">' . __( 'Content Verification sends nothing to AI providers. It stores a copy of the Content Credentials found in uploaded images, which can include the name of the image&#8217;s creator. Markdown Feeds sends nothing to AI providers either; it republishes public posts, author names and approved comments in Markdown format.', 'ai' ) . '</p>';

		$content .= '</div>';

		return wp_kses_post( $content );
	}
}
