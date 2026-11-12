/**
 * Narrows an untrusted `ai/show-screen` tool result to the A2UI messages the
 * screen card renders.
 */

/**
 * The ability whose successful result carries a screen.
 */
export const SCREEN_ABILITY = 'ai/show-screen';

/**
 * Keys whose value A2UI expects to be an object.
 *
 * The messages pass through PHP on the way back, and `json_decode()` turns an
 * empty object into an empty array, so `"context": {}` comes back as
 * `"context": []`, which the schema refuses. Under these keys an empty array
 * can only have been an empty object. A data model `value` is left alone,
 * since an empty list there is as likely as an empty object.
 */
const OBJECT_KEYS = new Set( [ 'context', 'args', 'theme' ] );

/**
 * Restores the empty objects PHP turned into empty arrays.
 *
 * @param value A decoded JSON value.
 * @param key   The key it was found under, if any.
 * @return The value, with empty arrays under object keys made objects again.
 */
function restoreEmptyObjects( value: unknown, key?: string ): unknown {
	if ( Array.isArray( value ) ) {
		if ( 0 === value.length && key && OBJECT_KEYS.has( key ) ) {
			return {};
		}

		return value.map( ( item ) => restoreEmptyObjects( item ) );
	}

	if ( value && 'object' === typeof value ) {
		return Object.fromEntries(
			Object.entries( value ).map( ( [ name, item ] ) => [
				name,
				restoreEmptyObjects( item, name ),
			] )
		);
	}

	return value;
}

/**
 * Narrows a tool result to a list of A2UI messages.
 *
 * The server checked the messages before it returned them, but the result
 * still arrives over the wire, so anything that is not a non-empty list of
 * objects is no screen at all. The renderer validates each message again.
 *
 * @param value The raw tool result.
 * @return The messages, or null when the result is not a screen.
 */
export function toScreen( value: unknown ): object[] | null {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return null;
	}

	const { messages } = value as Record< string, unknown >;

	if ( ! Array.isArray( messages ) || 0 === messages.length ) {
		return null;
	}

	if (
		! messages.every(
			( message ) =>
				message &&
				'object' === typeof message &&
				! Array.isArray( message )
		)
	) {
		return null;
	}

	return messages.map(
		( message ) => restoreEmptyObjects( message ) as object
	);
}

/**
 * Writes a button press as the message the model receives.
 *
 * The format is the one the system instruction describes: `[screen]`, the
 * event name, the button's text as a JSON string, then the event context as
 * JSON when there is any. The transcript shows it through `toScreenPress()`.
 *
 * @param name    The event name.
 * @param label   The pressed button's text, or an empty string.
 * @param context The resolved event context.
 * @return The message text.
 */
export function describeAction(
	name: string,
	label: string,
	context: Record< string, unknown > | undefined
): string {
	const parts = [ '[screen]', name, JSON.stringify( label ) ];

	if ( context && Object.keys( context ).length > 0 ) {
		parts.push( JSON.stringify( context ) );
	}

	return parts.join( ' ' );
}

/**
 * A button press, read back from the message that reported it.
 */
export interface ScreenPress {
	label: string;
	details: string[];
}

/**
 * Formats a context value for the person, or null when it means nothing to them.
 *
 * Strings and lists of strings are shown. Numbers are shown too, except under a
 * key ending in "id": a post ID is for the model, not the reader.
 *
 * @param key   The context key.
 * @param value The context value.
 * @return The text to show, or null.
 */
function formatDetail( key: string, value: unknown ): string | null {
	if ( 'string' === typeof value ) {
		return '' === value.trim() ? null : value;
	}

	if ( 'number' === typeof value ) {
		return /id$/i.test( key ) ? null : String( value );
	}

	if (
		Array.isArray( value ) &&
		value.length > 0 &&
		value.every( ( item ) => 'string' === typeof item )
	) {
		return value.join( ', ' );
	}

	return null;
}

/**
 * Reads a message written by `describeAction()` back as a button press.
 *
 * The person sees the button's text and the readable parts of what it sent,
 * instead of the event name and its JSON. A message that does not parse is
 * not a press, and is shown as typed.
 *
 * @param prompt The message text.
 * @return The press, or null when the message is not one.
 */
export function toScreenPress( prompt: string ): ScreenPress | null {
	const match = /^\[screen\] (\S+) ("(?:[^"\\]|\\.)*")(?: (\{.*\}))?$/s.exec(
		prompt
	);

	if ( ! match || ! match[ 1 ] || ! match[ 2 ] ) {
		return null;
	}

	const [ , name, encodedLabel, encodedContext ] = match;

	try {
		const label = JSON.parse( encodedLabel ) as unknown;
		const context = encodedContext
			? ( JSON.parse( encodedContext ) as unknown )
			: {};

		if (
			'string' !== typeof label ||
			! context ||
			'object' !== typeof context ||
			Array.isArray( context )
		) {
			return null;
		}

		const details = Object.entries( context ).flatMap(
			( [ key, value ] ) => {
				const detail = formatDetail( key, value );

				return null === detail ? [] : [ detail ];
			}
		);

		return { label: '' === label.trim() ? name : label, details };
	} catch {
		return null;
	}
}
