/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useCopyToClipboardFeedback } from '../../../hooks/use-copy-to-clipboard-feedback';

/**
 * Pretty-prints a value as JSON.
 *
 * `JSON.stringify()` leaves non-ASCII characters and slashes as they are, so
 * Unicode reads as written (#740), like PHP's `JSON_UNESCAPED_UNICODE |
 * JSON_UNESCAPED_SLASHES`.
 *
 * @param value The value.
 * @return The JSON text.
 */
export function formatJson( value: unknown ): string {
	const json = JSON.stringify( value, null, 2 );

	// `undefined` has no JSON form; show it as null rather than nothing.
	return undefined === json ? 'null' : json;
}

interface JsonBlockProps {
	/** The value to show. */
	value: unknown;
	/** What the block holds, for the Copy button's accessible name. */
	label: string;
}

/**
 * A pretty-printed JSON block with a Copy button.
 *
 * @param props       Component props.
 * @param props.value The value to show.
 * @param props.label What the block holds.
 * @return The block.
 */
export default function JsonBlock( { value, label }: JsonBlockProps ) {
	const json = useMemo( () => formatJson( value ), [ value ] );

	const { ref, hasCopied } = useCopyToClipboardFeedback< HTMLButtonElement >(
		{
			text: json,
			announcement: __( 'Copied!', 'ai' ),
		}
	);

	return (
		<div className="ai-abilities-explorer__json">
			<Button
				ref={ ref }
				className="ai-abilities-explorer__json-copy"
				variant="secondary"
				size="small"
				label={ sprintf(
					/* translators: %s: what the JSON block holds, such as "Input Schema". */
					__( 'Copy %s', 'ai' ),
					label
				) }
				showTooltip={ false }
			>
				{ hasCopied ? __( 'Copied!', 'ai' ) : __( 'Copy', 'ai' ) }
			</Button>
			<pre className="ai-abilities-explorer__json-display">{ json }</pre>
		</div>
	);
}
