/**
 * Bulk summary generation for the posts list table (edit.php).
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { runAbility } from '../../utils/run-ability';
import { isProviderAvailable } from '../../utils/provider-status';

type BulkData = {
	postIds: number[];
	restBase: string;
};

type PostResponse = {
	content: {
		raw: string;
	};
};

declare global {
	interface Window {
		aiSummarizationBulkData?: BulkData;
	}
}

/**
 * Creates and injects a dismissible admin notice into the page.
 *
 * @param message The initial message to display in the notice.
 * @param type    The admin notice type.
 * @return The paragraph element used to update the notice message.
 */
function createNotice(
	message: string,
	type: 'info' | 'error' = 'info'
): HTMLParagraphElement {
	const notice = document.createElement( 'div' );
	notice.className = `notice notice-${ type } is-dismissible`;

	const paragraph = document.createElement( 'p' );
	paragraph.textContent = message;
	notice.appendChild( paragraph );

	const dismissButton = document.createElement( 'button' );
	dismissButton.type = 'button';
	dismissButton.className = 'notice-dismiss';
	dismissButton.innerHTML = `<span class="screen-reader-text">${ __(
		'Dismiss this notice.',
		'ai'
	) }</span>`;
	dismissButton.addEventListener( 'click', () => notice.remove() );
	notice.appendChild( dismissButton );

	const container =
		document.querySelector< HTMLDivElement >( '#wpbody-content' );
	if ( container ) {
		container.prepend( notice );
	}

	return paragraph;
}

/**
 * Serializes a summary string into a WordPress block markup string.
 *
 * Mirrors the block the editor inserts: a core/group block with the
 * aiGeneratedSummary attribute set to true, containing one core/paragraph
 * block per non-empty paragraph in the summary.
 *
 * @param summary The plain-text summary from the AI.
 * @return Serialized block markup.
 */
function serializeSummaryBlock( summary: string ): string {
	const paragraphs = summary
		.split( /\n\n+/ )
		.map( ( p ) => p.trim() )
		.filter( Boolean );

	const innerBlocks = paragraphs
		.map(
			( text ) =>
				`<!-- wp:paragraph -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`
		)
		.join( '\n\n' );

	return (
		`<!-- wp:group {"className":"ai-summarization-summary","aiGeneratedSummary":true} -->\n` +
		`<div class="wp-block-group ai-summarization-summary">` +
		innerBlocks +
		`</div>\n` +
		`<!-- /wp:group -->`
	);
}

/**
 * Removes any existing AI-generated summary group block from serialized post content.
 *
 * @param content Serialized block content.
 * @return Content with the summary block removed.
 */
function removeExistingSummaryBlock( content: string ): string {
	return content
		.replace(
			/<!-- wp:group \{[^}]*"aiGeneratedSummary":true[^}]*\} -->[\s\S]*?<!-- \/wp:group -->\n*/,
			''
		)
		.trimStart();
}

/**
 * Processes bulk summary generation for all selected posts.
 */
async function processBulkSummary(): Promise< void > {
	const data = window.aiSummarizationBulkData;

	if ( ! data || ! data.postIds || data.postIds.length === 0 ) {
		return;
	}

	if ( ! isProviderAvailable() ) {
		createNotice(
			__(
				'This feature requires a valid AI Connector to function properly. Please set up a provider to use this feature in Settings → Connectors.',
				'ai'
			),
			'error'
		);
		return;
	}

	const { postIds, restBase } = data;
	const total = postIds.length;
	let processed = 0;
	const failedIds: number[] = [];

	const statusParagraph = createNotice(
		sprintf(
			// translators: 1: number processed so far, 2: total number of posts
			__( 'Generating summaries: %1$d / %2$d\u2026', 'ai' ),
			0,
			total
		)
	);

	for ( const id of postIds ) {
		try {
			// Generate summary the ability fetches the post content from the DB
			// using the post ID, so we only need to pass the context.
			const summary = await runAbility< string >( 'ai/summarization', {
				context: String( id ),
			} );

			if ( ! summary || typeof summary !== 'string' ) {
				throw new Error( __( 'Invalid response from API.', 'ai' ) );
			}

			// Fetch the current raw post content so we can splice in the summary block.
			const post = await apiFetch< PostResponse >( {
				path: `/wp/v2/${ restBase }/${ id }?context=edit`,
				method: 'GET',
			} );

			const existingContent = post?.content?.raw ?? '';
			const contentWithoutSummary =
				removeExistingSummaryBlock( existingContent );
			const summaryBlock = serializeSummaryBlock( summary );
			const newContent =
				summaryBlock +
				( contentWithoutSummary ? '\n' + contentWithoutSummary : '' );

			// Persist: save to both post content and post meta in a single request.
			await apiFetch( {
				path: `/wp/v2/${ restBase }/${ id }`,
				method: 'POST',
				data: {
					content: newContent,
					meta: { ai_generated_summary: summary },
				},
			} );
		} catch {
			failedIds.push( id );
		}

		processed++;

		statusParagraph.textContent = sprintf(
			// translators: 1: number processed so far, 2: total number of posts
			__( 'Generating summaries: %1$d / %2$d\u2026', 'ai' ),
			processed,
			total
		);
	}

	const successCount = processed - failedIds.length;

	if ( failedIds.length === 0 ) {
		statusParagraph.textContent = sprintf(
			// translators: %d: number of posts
			_n(
				'Summary generated for %d post.',
				'Summary generated for %d posts.',
				successCount,
				'ai'
			),
			successCount
		);
	} else {
		statusParagraph.textContent = sprintf(
			// translators: 1: number successfully processed, 2: total posts, 3: comma-separated list of failed post IDs
			__(
				'Summary generated for %1$d of %2$d posts. Failed post IDs: %3$s.',
				'ai'
			),
			successCount,
			total,
			failedIds.join( ', ' )
		);
	}
}

document.addEventListener( 'DOMContentLoaded', () => {
	void processBulkSummary().then( () => {
		// Clean up the bulk query args from the URL so a page refresh
		// does not re-trigger processing.
		const url = new URL( window.location.href );
		url.searchParams.delete( 'wpai_bulk_summary' );
		url.searchParams.delete( 'wpai_post_ids' );
		window.history.replaceState( {}, '', url.toString() );
	} );
} );
