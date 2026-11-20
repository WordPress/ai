/**
 * A restricted-subset markdown renderer for assistant output; a security boundary that never produces HTML strings.
 *
 * Raw HTML stays text. Images are never fetched (zero-click exfiltration).
 * Off-host or non-http(s) links are inert, so a link cannot carry retrieved content off-site.
 */

/**
 * A heading level in the allowlist.
 */
export type HeadingLevel = 1 | 2 | 3 | 4 | 5 | 6;

/**
 * An inline node.
 */
export type InlineNode =
	| { type: 'text'; value: string }
	// Emitted for a single newline, unlike CommonMark's soft break, to keep the model's line shape.
	| { type: 'break' }
	| { type: 'strong'; children: InlineNode[] }
	| { type: 'emphasis'; children: InlineNode[] }
	| { type: 'code'; value: string }
	| { type: 'link'; label: string; destination: string; inert: boolean }
	| { type: 'image'; alt: string; destination: string }
	// Live-site markup described as text, never drawn; only `renderHtml()` emits it.
	| {
			type: 'withheld';
			kind: 'block' | 'media' | 'embed' | 'control';
			name: string;
			detail: string;
	  };

/**
 * A block-level node.
 */
export type BlockNode =
	| { type: 'paragraph'; children: InlineNode[] }
	| { type: 'heading'; level: HeadingLevel; children: InlineNode[] }
	| { type: 'codeBlock'; language: string; value: string }
	| {
			type: 'list';
			ordered: boolean;
			start: number;
			items: InlineNode[][];
	  }
	| { type: 'blockquote'; children: BlockNode[] };

/**
 * Options for {@link renderMarkdown}.
 */
export interface MarkdownOptions {
	/** The only host whose links may render as live anchors. */
	allowedHost: string;
}

const FENCE_PATTERN = /^(```+|~~~+)\s*([^\s`]*)\s*$/;
const HEADING_PATTERN = /^(#{1,6})\s+(.*)$/;
const UNORDERED_ITEM_PATTERN = /^\s{0,3}[-*+]\s+(.*)$/;
const ORDERED_ITEM_PATTERN = /^\s{0,3}(\d{1,9})[.)]\s+(.*)$/;
const BLOCKQUOTE_PATTERN = /^\s{0,3}>\s?(.*)$/;
const LANGUAGE_PATTERN = /^[A-Za-z0-9_+#.-]{0,20}$/;

/**
 * Parses assistant output into a renderable node tree.
 *
 * @param source  The markdown source produced by the model.
 * @param options Renderer options.
 * @return The block nodes to render.
 */
export function renderMarkdown(
	source: string,
	options: MarkdownOptions
): BlockNode[] {
	return parseBlocks( source.split( /\r\n|\n|\r/ ), options );
}

/**
 * Parses a list of source lines into block nodes.
 *
 * @param lines   The source lines.
 * @param options Renderer options.
 * @return The block nodes.
 */
function parseBlocks( lines: string[], options: MarkdownOptions ): BlockNode[] {
	const blocks: BlockNode[] = [];
	let index = 0;

	while ( index < lines.length ) {
		const line = lines[ index ] ?? '';

		if ( '' === line.trim() ) {
			index++;
			continue;
		}

		const fence = FENCE_PATTERN.exec( line );

		if ( fence ) {
			const marker = fence[ 1 ] ?? '```';
			const language = fence[ 2 ] ?? '';
			const content: string[] = [];
			index++;

			while ( index < lines.length ) {
				const current = lines[ index ] ?? '';

				if (
					current.trim().startsWith( marker.slice( 0, 3 ) ) &&
					'' === current.trim().replace( /[`~]/g, '' )
				) {
					index++;
					break;
				}

				content.push( current );
				index++;
			}

			blocks.push( {
				type: 'codeBlock',
				language: LANGUAGE_PATTERN.test( language ) ? language : '',
				value: content.join( '\n' ),
			} );
			continue;
		}

		const heading = HEADING_PATTERN.exec( line );

		if ( heading ) {
			const hashes = heading[ 1 ] ?? '#';
			blocks.push( {
				type: 'heading',
				level: Math.min( 6, hashes.length ) as HeadingLevel,
				children: parseInline( ( heading[ 2 ] ?? '' ).trim(), options ),
			} );
			index++;
			continue;
		}

		if ( BLOCKQUOTE_PATTERN.test( line ) ) {
			const quoted: string[] = [];

			while ( index < lines.length ) {
				const match = BLOCKQUOTE_PATTERN.exec( lines[ index ] ?? '' );

				if ( ! match ) {
					break;
				}

				quoted.push( match[ 1 ] ?? '' );
				index++;
			}

			blocks.push( {
				type: 'blockquote',
				children: parseBlocks( quoted, options ),
			} );
			continue;
		}

		const isOrdered = ORDERED_ITEM_PATTERN.test( line );

		if ( isOrdered || UNORDERED_ITEM_PATTERN.test( line ) ) {
			const pattern = isOrdered
				? ORDERED_ITEM_PATTERN
				: UNORDERED_ITEM_PATTERN;
			const items: InlineNode[][] = [];

			// Keep the opening number so a list split by paragraphs stays numbered.
			let start = 1;

			while ( index < lines.length ) {
				const match = pattern.exec( lines[ index ] ?? '' );

				if ( ! match ) {
					break;
				}

				if ( isOrdered && 0 === items.length ) {
					start = Number( match[ 1 ] );
				}

				const text = isOrdered ? match[ 2 ] : match[ 1 ];

				items.push( parseInline( text ?? '', options ) );
				index++;
			}

			blocks.push( { type: 'list', ordered: isOrdered, start, items } );
			continue;
		}

		const paragraph: string[] = [];

		while ( index < lines.length ) {
			const current = lines[ index ] ?? '';

			if (
				'' === current.trim() ||
				FENCE_PATTERN.test( current ) ||
				HEADING_PATTERN.test( current ) ||
				BLOCKQUOTE_PATTERN.test( current ) ||
				ORDERED_ITEM_PATTERN.test( current ) ||
				UNORDERED_ITEM_PATTERN.test( current )
			) {
				break;
			}

			paragraph.push( current );
			index++;
		}

		const children: InlineNode[] = [];

		paragraph.forEach( ( text, position ) => {
			if ( position > 0 ) {
				// A newline in a text node would collapse to a space.
				children.push( { type: 'break' } );
			}

			children.push( ...parseInline( text, options ) );
		} );

		blocks.push( { type: 'paragraph', children } );
	}

	return blocks;
}

/**
 * Parses one run of inline markdown.
 *
 * @param text    The inline source.
 * @param options Renderer options.
 * @return The inline nodes.
 */
function parseInline( text: string, options: MarkdownOptions ): InlineNode[] {
	const nodes: InlineNode[] = [];
	let buffer = '';
	let index = 0;

	const flush = (): void => {
		if ( '' !== buffer ) {
			nodes.push( { type: 'text', value: buffer } );
			buffer = '';
		}
	};

	while ( index < text.length ) {
		const char = text.charAt( index );

		if ( '\\' === char && index + 1 < text.length ) {
			buffer += text.charAt( index + 1 );
			index += 2;
			continue;
		}

		if ( '`' === char ) {
			const code = /^(`+)([\s\S]*?)\1(?!`)/.exec( text.slice( index ) );

			if ( code && undefined !== code[ 2 ] ) {
				flush();
				nodes.push( { type: 'code', value: code[ 2 ] } );
				index += code[ 0 ].length;
				continue;
			}
		}

		if ( '!' === char && '[' === text.charAt( index + 1 ) ) {
			const image = matchBracketed( text, index + 1 );

			if ( image ) {
				flush();
				// An image never becomes a node that can request a URL.
				nodes.push( {
					type: 'image',
					alt: image.label,
					destination: image.destination,
				} );
				index = image.end;
				continue;
			}
		}

		if ( '[' === char ) {
			const link = matchBracketed( text, index );

			if ( link ) {
				flush();
				nodes.push( {
					type: 'link',
					label: link.label,
					destination: link.destination,
					inert: ! isLiveDestination(
						link.destination,
						options.allowedHost
					),
				} );
				index = link.end;
				continue;
			}
		}

		if ( '*' === char || '_' === char ) {
			const emphasized = matchEmphasis( text, index, char, options );

			if ( emphasized ) {
				flush();
				nodes.push( emphasized.node );
				index = emphasized.end;
				continue;
			}
		}

		buffer += char;
		index++;
	}

	flush();

	return nodes;
}

/**
 * Matches bold or italic starting at a delimiter.
 *
 * @param text      The inline source.
 * @param start     Index of the delimiter.
 * @param delimiter The delimiter character.
 * @param options   Renderer options.
 * @return The node and the index after it, or null when nothing matched.
 */
function matchEmphasis(
	text: string,
	start: number,
	delimiter: string,
	options: MarkdownOptions
): { node: InlineNode; end: number } | null {
	const strong = delimiter + delimiter;

	if ( text.startsWith( strong, start ) ) {
		const close = text.indexOf( strong, start + 2 );

		if ( close > start + 2 ) {
			return {
				node: {
					type: 'strong',
					children: parseInline(
						text.slice( start + 2, close ),
						options
					),
				},
				end: close + 2,
			};
		}
	}

	const close = text.indexOf( delimiter, start + 1 );

	if ( close > start + 1 ) {
		return {
			node: {
				type: 'emphasis',
				children: parseInline(
					text.slice( start + 1, close ),
					options
				),
			},
			end: close + 1,
		};
	}

	return null;
}

/**
 * Matches an inline `[label](destination)` construct; reference-style links stay literal text.
 *
 * @param text  The inline source.
 * @param start Index of the opening bracket.
 * @return The label, destination and index after the construct, or null.
 */
function matchBracketed(
	text: string,
	start: number
): { label: string; destination: string; end: number } | null {
	let index = start + 1;
	let depth = 1;
	let label = '';

	while ( index < text.length && depth > 0 ) {
		const char = text.charAt( index );

		if ( '\\' === char && index + 1 < text.length ) {
			label += text.charAt( index + 1 );
			index += 2;
			continue;
		}

		if ( '[' === char ) {
			depth++;
		} else if ( ']' === char ) {
			depth--;

			if ( 0 === depth ) {
				index++;
				break;
			}
		}

		label += char;
		index++;
	}

	if ( depth > 0 || '(' !== text.charAt( index ) ) {
		return null;
	}

	index++;
	depth = 1;
	let destination = '';

	while ( index < text.length && depth > 0 ) {
		const char = text.charAt( index );

		if ( '(' === char ) {
			depth++;
		} else if ( ')' === char ) {
			depth--;

			if ( 0 === depth ) {
				index++;
				break;
			}
		}

		destination += char;
		index++;
	}

	if ( depth > 0 ) {
		return null;
	}

	return {
		label,
		destination: normalizeDestination( destination ),
		end: index,
	};
}

/**
 * Strips the optional angle brackets and title from a link destination.
 *
 * @param destination The raw destination text.
 * @return The destination.
 */
function normalizeDestination( destination: string ): string {
	const trimmed = destination.trim();

	if ( trimmed.startsWith( '<' ) ) {
		const close = trimmed.indexOf( '>' );

		return close === -1 ? trimmed.slice( 1 ) : trimmed.slice( 1, close );
	}

	const space = trimmed.search( /\s/ );

	return space === -1 ? trimmed : trimmed.slice( 0, space );
}

/**
 * Decides whether a destination may be live: only http(s) on the allowed host, since an off-site link can exfiltrate data.
 *
 * @param destination The destination.
 * @param allowedHost The host whose links may be live.
 * @return True when the destination may be a live anchor.
 */
export function isLiveDestination(
	destination: string,
	allowedHost: string
): boolean {
	if ( '' === destination || '' === allowedHost ) {
		return false;
	}

	try {
		const url = new URL( destination, 'https://' + allowedHost + '/' );

		if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
			return false;
		}

		return url.host === allowedHost;
	} catch {
		return false;
	}
}
