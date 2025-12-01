/**
 * External dependencies
 */
import Markdown from 'markdown-to-jsx';

/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { MessagePart } from '../../ai-client-types';

type MediaProps = {
	mimeType: string;
	src: string;
};

type JsonTextareaProps = {
	data: unknown;
	label: string;
};

type MessagePartsProps = {
	parts: MessagePart[];
};

/**
 * Renders a single media element.
 *
 * @since n.e.x.t
 *
 * @param props - Component props.
 * @return The component to be rendered.
 */
function Media( props: MediaProps ) {
	const { mimeType, src } = props;

	if ( mimeType.startsWith( 'image' ) ) {
		return <img src={ src } alt="" />;
	}

	if ( mimeType.startsWith( 'audio' ) ) {
		return <audio src={ src } controls />;
	}

	if ( mimeType.startsWith( 'video' ) ) {
		return <video src={ src } controls />;
	}

	return null;
}

/**
 * Renders a textarea with JSON formatted data.
 *
 * @since 0.5.0
 *
 * @param props - Component props.
 * @return The component to be rendered.
 */
function JsonTextarea( props: JsonTextareaProps ) {
	const { data, label } = props;

	const dataJson = useMemo( () => {
		return JSON.stringify( data, null, 2 );
	}, [ data ] );

	return (
		<textarea
			className="code"
			aria-label={ label }
			value={ dataJson }
			rows={ 5 }
			readOnly
		/>
	);
}

/**
 * Renders formatted message parts.
 *
 * @since n.e.x.t
 *
 * @param props - Component props.
 * @return The component to be rendered.
 */
export default function MessageParts( props: MessagePartsProps ) {
	const { parts } = props;

	return parts.map( ( part, index ) => {
		if ( 'text' in part ) {
			return (
				<div className="ai-playground__message-part" key={ index }>
					<Markdown
						options={ {
							forceBlock: true,
							forceWrapper: true,
						} }
					>
						{ part.text }
					</Markdown>
				</div>
			);
		}

		if ( 'file' in part ) {
			const { mimeType } = part.file;
			let src: string;
			if ( 'url' in part.file && part.file.url ) {
				src = part.file.url;
			} else if ( 'base64Data' in part.file && part.file.base64Data ) {
				src = `data:${ mimeType };base64,${ part.file.base64Data }`;
			} else {
				return null;
			}
			return (
				<div className="ai-playground__message-part" key={ index }>
					<Media mimeType={ mimeType } src={ src } />
				</div>
			);
		}

		if ( 'functionCall' in part ) {
			return (
				<div className="ai-playground__message-part" key={ index }>
					<JsonTextarea
						data={ part.functionCall }
						label={ __( 'Function call data', 'ai' ) }
					/>
				</div>
			);
		}

		if ( 'functionResponse' in part ) {
			return (
				<div className="ai-playground__message-part" key={ index }>
					<JsonTextarea
						data={ part.functionResponse }
						label={ __( 'Function response data', 'ai' ) }
					/>
				</div>
			);
		}

		return null;
	} );
}
