/**
 * Renders a safe subset of HTML.
 */

/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';

const ALLOWED_TAGS = new Set( [
	'a',
	'b',
	'blockquote',
	'br',
	'code',
	'del',
	'em',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'hr',
	'i',
	'li',
	'ol',
	'p',
	'pre',
	's',
	'strong',
	'table',
	'tbody',
	'td',
	'tfoot',
	'th',
	'thead',
	'tr',
	'ul',
] );

const REMOVED_TAGS = new Set( [
	'embed',
	'iframe',
	'link',
	'meta',
	'object',
	'script',
	'style',
	'svg',
	'template',
] );

const ALLOWED_ATTRIBUTES: Record< string, string[] > = {
	a: [ 'href', 'title' ],
	code: [ 'class' ],
	td: [ 'colspan', 'rowspan' ],
	th: [ 'colspan', 'rowspan', 'scope' ],
};

/**
 * Removes attributes and elements that are not safe to render.
 *
 * @param node Node to clean.
 */
function cleanNode( node: Node ): void {
	if ( node.nodeType === Node.COMMENT_NODE ) {
		node.parentNode?.removeChild( node );
		return;
	}

	if ( node.nodeType !== Node.ELEMENT_NODE ) {
		return;
	}

	const element = node as HTMLElement;
	const tag = element.tagName.toLowerCase();

	if ( REMOVED_TAGS.has( tag ) ) {
		element.remove();
		return;
	}

	if ( ! ALLOWED_TAGS.has( tag ) ) {
		element.replaceWith(
			document.createTextNode( element.textContent ?? '' )
		);
		return;
	}

	const allowed = ALLOWED_ATTRIBUTES[ tag ] ?? [];

	for ( const attribute of Array.from( element.attributes ) ) {
		if ( ! allowed.includes( attribute.name.toLowerCase() ) ) {
			element.removeAttribute( attribute.name );
		}
	}

	if ( tag === 'a' ) {
		const href = element.getAttribute( 'href' ) ?? '';

		if ( ! isSafeHref( href ) ) {
			element.removeAttribute( 'href' );
		}

		element.setAttribute( 'rel', 'noopener noreferrer' );
	}

	if ( tag === 'code' ) {
		const className = element.getAttribute( 'class' ) ?? '';

		if ( ! /^language-[\w-]+$/.test( className ) ) {
			element.removeAttribute( 'class' );
		}
	}

	Array.from( element.childNodes ).forEach( cleanNode );
}

/**
 * Reports whether a link target is safe to keep.
 *
 * @param href The link target.
 */
function isSafeHref( href: string ): boolean {
	const value = href.trim();

	if ( '' === value ) {
		return false;
	}

	if ( value.startsWith( '/' ) || value.startsWith( '#' ) ) {
		return true;
	}

	return /^(https?:|mailto:)/i.test( value );
}

/**
 * Sanitizes an HTML string for rendering in the admin screen.
 *
 * @param html The HTML string.
 */
export function sanitizeHtml( html: string ): string {
	const documentNode = new DOMParser().parseFromString( html, 'text/html' );

	Array.from( documentNode.body.childNodes ).forEach( cleanNode );

	return documentNode.body.innerHTML;
}

interface SafeHtmlProps {
	html: string;
	className?: string;
}

/**
 * Renders sanitized HTML.
 *
 * @param props           Component props.
 * @param props.html      The HTML string.
 * @param props.className Optional class names.
 */
export default function SafeHtml( {
	html,
	className,
}: SafeHtmlProps ): React.JSX.Element {
	const clean = useMemo( () => sanitizeHtml( html ), [ html ] );

	return (
		<div
			className={ className }
			dangerouslySetInnerHTML={ { __html: clean } }
		/>
	);
}
