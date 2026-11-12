/**
 * Renders assistant replies as Markdown.
 */

/**
 * External dependencies
 */
import { marked } from 'marked';

/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import SafeHtml, { sanitizeHtml } from './SafeHtml';

interface MarkdownProps {
	text: string;
}

/**
 * Converts Markdown to sanitized HTML.
 *
 * @param text The Markdown text.
 */
function toHtml( text: string ): string {
	const html = marked.parse( text, {
		async: false,
		breaks: true,
		gfm: true,
	} );

	return sanitizeHtml( typeof html === 'string' ? html : text );
}

/**
 * Renders Markdown without allowing raw scripts or unsafe links.
 *
 * @param props      Component props.
 * @param props.text The Markdown text.
 */
export default function Markdown( { text }: MarkdownProps ): React.JSX.Element {
	const html = useMemo( () => toHtml( text ), [ text ] );

	return <SafeHtml html={ html } className="ai-markdown" />;
}
