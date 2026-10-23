/**
 * WordPress dependencies
 */
import { getBlockType, type Block } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { getRichTextHTML } from '../../../utils/blocks';
import { runAbility } from '../../../utils/run-ability';
import type { AIContentTranslationData } from '../types';
import {
	TRANSLATION_EXCLUDED_BLOCK_TYPES,
	TRANSLATION_LOADING_CLASSES,
	TRANSLATION_MINIMUM_CONTENT_COUNT_DEFAULT,
} from '../constants';

/**
 * Path to a value inside a block's attributes, e.g. `[ 'content' ]` or
 * `[ 'body', 0, 'cells', 1, 'content' ]` for a table cell.
 */
type AttributePath = ( string | number )[];

/**
 * A single piece of rich text within a block that can be translated on its own.
 */
export type TranslatableSegment = {
	/** Unique identifier of the segment, used to retry failed translations. */
	key: string;
	clientId: string;
	path: AttributePath;
	content: string;
};

/**
 * Minimal shape of a registered block attribute definition.
 */
type AttributeSchema = {
	source?: string;
	query?: Record< string, AttributeSchema >;
};

/**
 * Retrieves the content translation settings from the global window object.
 *
 * @return The content translation settings.
 */
export const getSettings = (): AIContentTranslationData => {
	const settings = window?.aiContentTranslationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ??
			TRANSLATION_MINIMUM_CONTENT_COUNT_DEFAULT,
		languages: settings.languages ?? [],
	};
};

/**
 * Returns the paths of all rich text values in a set of attributes.
 *
 * Rich text attributes are found through the block type's registered schema
 * (`source: 'rich-text'`), including those nested inside `query` attributes
 * such as table cells.
 *
 * @param schema The attribute definitions to walk.
 * @param values The attribute values matching the definitions.
 * @param prefix The path of the values within the block's attributes.
 * @return The paths of the rich text values.
 */
function getRichTextPaths(
	schema: Record< string, AttributeSchema >,
	values: Record< string, unknown >,
	prefix: AttributePath = []
): AttributePath[] {
	return Object.entries( schema ).flatMap( ( [ name, definition ] ) => {
		const value = values?.[ name ];

		if ( definition.source === 'rich-text' ) {
			return [ [ ...prefix, name ] ];
		}

		if (
			definition.source === 'query' &&
			definition.query &&
			Array.isArray( value )
		) {
			const query = definition.query;

			return value.flatMap( ( item, index ) =>
				getRichTextPaths( query, item, [ ...prefix, name, index ] )
			);
		}

		return [];
	} );
}

/**
 * Returns the value at a path inside a block's attributes.
 *
 * @param attributes The block attributes.
 * @param path       The path to read.
 * @return The value at the path, or undefined if it doesn't exist.
 */
function getAttributeValue(
	attributes: Record< string, unknown >,
	path: AttributePath
): unknown {
	return path.reduce< unknown >(
		( value, segment ) =>
			value && typeof value === 'object'
				? ( value as Record< string | number, unknown > )[ segment ]
				: undefined,
		attributes
	);
}

/**
 * Returns a copy of a value with the value at a path replaced.
 *
 * @param value    The object or array to copy.
 * @param path     The path to replace.
 * @param newValue The new value.
 * @return The updated copy.
 */
function setAttributeValue(
	value: unknown,
	path: AttributePath,
	newValue: unknown
): unknown {
	if ( path.length === 0 ) {
		return newValue;
	}

	const [ segment, ...rest ] = path as [ string | number, ...AttributePath ];
	const copy = Array.isArray( value )
		? [ ...value ]
		: { ...( value as Record< string, unknown > ) };

	( copy as Record< string | number, unknown > )[ segment ] =
		setAttributeValue(
			( value as Record< string | number, unknown > )?.[ segment ],
			rest,
			newValue
		);

	return copy;
}

/**
 * Returns the translatable rich text segments of a block.
 *
 * Every rich text attribute registered for the block type is included, so
 * any block built with `RichText` (core or third-party) is supported without
 * being listed. Blocks in `TRANSLATION_EXCLUDED_BLOCK_TYPES` and empty values
 * are skipped.
 *
 * @param block The block to inspect.
 * @return The block's translatable segments.
 */
export function getTranslatableSegments( block: Block ): TranslatableSegment[] {
	if ( TRANSLATION_EXCLUDED_BLOCK_TYPES.includes( block.name ) ) {
		return [];
	}

	const schema = ( getBlockType( block.name )?.attributes ?? {} ) as Record<
		string,
		AttributeSchema
	>;

	return getRichTextPaths( schema, block.attributes )
		.map( ( path ) => ( {
			key: `${ block.clientId }:${ path.join( '.' ) }`,
			clientId: block.clientId,
			path,
			content: getRichTextHTML(
				getAttributeValue( block.attributes, path )
			),
		} ) )
		.filter( ( segment ) => segment.content.trim().length > 0 );
}

/**
 * Checks whether a segment is no longer safe to update: its block was removed,
 * or its content changed since it was captured.
 *
 * @param segment The segment to check.
 * @return True if the block is missing or the segment's content changed.
 */
export function isSegmentStale( segment: TranslatableSegment ): boolean {
	const block = select( blockEditorStore ).getBlock( segment.clientId );

	return (
		! block ||
		getRichTextHTML(
			getAttributeValue( block.attributes, segment.path )
		) !== segment.content
	);
}

/**
 * Returns the attribute update that writes a translation back to a segment.
 *
 * Nested values (e.g. table cells) are merged into the block's current
 * attributes so translations of sibling segments applied earlier are kept.
 *
 * @param segment     The segment that was translated.
 * @param translation The translated content.
 * @return The attributes to pass to `updateBlockAttributes()`.
 */
export function getSegmentUpdate(
	segment: TranslatableSegment,
	translation: string
): Record< string, unknown > {
	const [ attribute, ...rest ] = segment.path as [ string, ...AttributePath ];
	const attributes =
		select( blockEditorStore ).getBlockAttributes( segment.clientId ) ?? {};

	return {
		[ attribute ]: setAttributeValue(
			attributes[ attribute ],
			rest,
			translation
		),
	};
}

/**
 * Toggle the loading class used to show the translation-in-progress state.
 *
 * @param loadingClass The loading class to toggle.
 * @param isLoading    A boolean indicating whether the loading class should be toggled.
 */
export function setTranslationLoadingClass(
	loadingClass: keyof typeof TRANSLATION_LOADING_CLASSES,
	isLoading: boolean
) {
	const editorBody = document.querySelector< HTMLIFrameElement >(
		'iframe[name="editor-canvas"]'
	)?.contentDocument?.body;

	editorBody?.classList.toggle(
		TRANSLATION_LOADING_CLASSES[ loadingClass ],
		isLoading
	);
}

/**
 * Translates the content of a post using the AI API.
 *
 * @param content        The content to translate.
 * @param targetLanguage The target language to translate the content to.
 * @param postId         The ID of the post to translate the content for.
 * @return  A promise that resolves to the translated content.
 */
export function translateContent(
	content: string,
	targetLanguage: string,
	postId: number
): Promise< string > {
	return runAbility< string >( 'ai/content-translation', {
		content,
		target_language: targetLanguage,
		post_id: postId,
	} );
}
