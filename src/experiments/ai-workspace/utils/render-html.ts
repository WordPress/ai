/**
 * Reads post markup into the node tree the markdown renderer draws.
 *
 * A post's current and proposed content is HTML: block markup, model output,
 * or the person's own edit. The card shows it rendered, as the reader will see
 * it, without ever handing markup to the browser to render. The markup is
 * parsed in a `DOMParser` document, which runs no script and loads nothing, and
 * then walked against an allowlist into the same {@link BlockNode} tree
 * `renderMarkdown()` produces, so the same React renderer draws it and the same
 * rules hold (R19, KTD9):
 *
 * - Elements outside the allowlist are not rendered; their text is.
 * - Block comments, scripts, styles and embeds produce nothing.
 * - An image is described in place and never fetched.
 * - A link is live only on this site's host, and always shows where it goes.
 */

/**
 * Internal dependencies
 */
import {
	isLiveDestination,
	type BlockNode,
	type HeadingLevel,
	type InlineNode,
	type MarkdownOptions,
} from './render-markdown';

/**
 * Elements whose content is never shown, not even as text.
 */
const SKIPPED = [
	'script',
	'style',
	'template',
	'noscript',
	'iframe',
	'object',
	'embed',
	'svg',
	'math',
	'video',
	'audio',
	'canvas',
	'form',
	'input',
	'button',
	'select',
	'textarea',
	'head',
	'title',
	'meta',
	'link',
];

/**
 * Elements that start a block of their own.
 */
const BLOCKS = [
	'p',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'ul',
	'ol',
	'blockquote',
	'pre',
	'div',
	'figure',
	'figcaption',
	'section',
	'article',
	'aside',
	'header',
	'footer',
	'main',
	'nav',
	'table',
	'thead',
	'tbody',
	'tfoot',
	'tr',
	'td',
	'th',
	'hr',
	'details',
	'summary',
	'dl',
	'dt',
	'dd',
	'address',
	'body',
];

/**
 * Collapses runs of whitespace, as the browser would when laying out text.
 *
 * @param text The text.
 * @return The collapsed text.
 */
function collapse( text: string ): string {
	return text.replace( /\s+/g, ' ' );
}

/**
 * Reads the inline nodes under a node.
 *
 * @param node    The parent node.
 * @param options The renderer options.
 * @return The inline nodes.
 */
function inlineChildren( node: Node, options: MarkdownOptions ): InlineNode[] {
	const nodes: InlineNode[] = [];

	node.childNodes.forEach( ( child ) => {
		nodes.push( ...inline( child, options ) );
	} );

	return nodes;
}

/**
 * Reads one node as inline content.
 *
 * @param node    The node.
 * @param options The renderer options.
 * @return The inline nodes it stands for.
 */
function inline( node: Node, options: MarkdownOptions ): InlineNode[] {
	if ( node.nodeType === node.TEXT_NODE ) {
		const value = collapse( node.textContent ?? '' );

		return '' === value ? [] : [ { type: 'text', value } ];
	}

	if ( node.nodeType !== node.ELEMENT_NODE ) {
		return [];
	}

	const element = node as Element;
	const tag = element.tagName.toLowerCase();

	if ( SKIPPED.includes( tag ) ) {
		return [];
	}

	switch ( tag ) {
		case 'br':
			return [ { type: 'break' } ];
		case 'strong':
		case 'b':
			return [
				{
					type: 'strong',
					children: inlineChildren( element, options ),
				},
			];
		case 'em':
		case 'i':
			return [
				{
					type: 'emphasis',
					children: inlineChildren( element, options ),
				},
			];
		case 'code':
		case 'kbd':
		case 'samp':
			return [ { type: 'code', value: element.textContent ?? '' } ];
		case 'img':
			return [
				{
					type: 'image',
					alt: element.getAttribute( 'alt' ) ?? '',
					destination: element.getAttribute( 'src' ) ?? '',
				},
			];
		case 'a': {
			const destination = ( element.getAttribute( 'href' ) ?? '' ).trim();
			const label = collapse( element.textContent ?? '' ).trim();

			if ( '' === destination ) {
				return inlineChildren( element, options );
			}

			return [
				{
					type: 'link',
					label,
					destination,
					inert: ! isLiveDestination(
						destination,
						options.allowedHost
					),
				},
			];
		}
		default:
			return inlineChildren( element, options );
	}
}

/**
 * Trims the whitespace a run of inline nodes starts and ends with.
 *
 * @param nodes The inline nodes.
 * @return The trimmed nodes, empty when nothing but whitespace was left.
 */
function trimInline( nodes: InlineNode[] ): InlineNode[] {
	const trimmed = [ ...nodes ];
	const first = trimmed[ 0 ];

	if ( first && 'text' === first.type ) {
		trimmed[ 0 ] = { type: 'text', value: first.value.trimStart() };
	}

	const lastIndex = trimmed.length - 1;
	const last = trimmed[ lastIndex ];

	if ( last && 'text' === last.type ) {
		trimmed[ lastIndex ] = { type: 'text', value: last.value.trimEnd() };
	}

	return trimmed.filter(
		( node ) => 'text' !== node.type || '' !== node.value
	);
}

/**
 * Reads the block nodes under a node.
 *
 * Inline content between blocks is gathered into a paragraph of its own, as a
 * browser lays out text that sits directly in a container.
 *
 * @param node    The parent node.
 * @param options The renderer options.
 * @return The block nodes.
 */
function blockChildren( node: Node, options: MarkdownOptions ): BlockNode[] {
	const blocks: BlockNode[] = [];
	let run: InlineNode[] = [];

	const flush = (): void => {
		const children = trimInline( run );

		if ( children.length > 0 ) {
			blocks.push( { type: 'paragraph', children } );
		}

		run = [];
	};

	node.childNodes.forEach( ( child ) => {
		const isBlock =
			child.nodeType === child.ELEMENT_NODE &&
			BLOCKS.includes( ( child as Element ).tagName.toLowerCase() );

		if ( ! isBlock ) {
			run.push( ...inline( child, options ) );
			return;
		}

		flush();
		blocks.push( ...block( child as Element, options ) );
	} );

	flush();

	return blocks;
}

/**
 * Reads one block element.
 *
 * @param element The element.
 * @param options The renderer options.
 * @return The block nodes it stands for.
 */
function block( element: Element, options: MarkdownOptions ): BlockNode[] {
	const tag = element.tagName.toLowerCase();

	switch ( tag ) {
		case 'p': {
			const children = trimInline( inlineChildren( element, options ) );

			return children.length > 0
				? [ { type: 'paragraph', children } ]
				: [];
		}
		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			return [
				{
					type: 'heading',
					level: Number( tag.slice( 1 ) ) as HeadingLevel,
					children: trimInline( inlineChildren( element, options ) ),
				},
			];
		case 'ul':
		case 'ol': {
			const items: InlineNode[][] = [];

			Array.from( element.children ).forEach( ( child ) => {
				if ( 'li' === child.tagName.toLowerCase() ) {
					items.push(
						trimInline( inlineChildren( child, options ) )
					);
				}
			} );

			const start = Number( element.getAttribute( 'start' ) ?? '1' );

			return [
				{
					type: 'list',
					ordered: 'ol' === tag,
					start: Number.isInteger( start ) ? start : 1,
					items,
				},
			];
		}
		case 'blockquote':
			return [
				{
					type: 'blockquote',
					children: blockChildren( element, options ),
				},
			];
		case 'pre':
			return [
				{
					type: 'codeBlock',
					language: '',
					value: element.textContent ?? '',
				},
			];
		case 'hr':
			return [];
		default:
			return blockChildren( element, options );
	}
}

/**
 * Reads post markup into block nodes.
 *
 * @param html    The markup.
 * @param options The renderer options.
 * @return The block nodes.
 */
export function renderHtml(
	html: string,
	options: MarkdownOptions
): BlockNode[] {
	if ( '' === html.trim() || 'undefined' === typeof window.DOMParser ) {
		return [];
	}

	// An inert document: nothing in it runs or loads.
	const parsed = new window.DOMParser().parseFromString( html, 'text/html' );

	return blockChildren( parsed.body, options );
}
