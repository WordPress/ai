/**
 * The test runner's client-side input checks.
 *
 * A port of the checks `Ability_Handler::validate_input()` runs on the server,
 * so "Validate Input" predicts what Invoke will say. To match the server, a
 * field present as `null` counts as missing (PHP's `isset()`), and input that
 * is not an object is reported rather than read with the `in` operator.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The outcome of checking the textarea.
 */
export interface ValidationResult {
	valid: boolean;
	messages: string[];
}

type JsonRecord = Record< string, unknown >;

/**
 * Reports whether a value is a plain JSON object (not an array or null).
 *
 * @param value The value.
 * @return True for a JSON object.
 */
export const isJsonRecord = ( value: unknown ): value is JsonRecord =>
	null !== value && 'object' === typeof value && ! Array.isArray( value );

/**
 * Reports whether a schema or JSON value is empty the way PHP's `empty()`
 * sees a decoded array: null, `[]` or `{}`.
 *
 * @param value The value.
 * @return True when empty.
 */
export const isEmptyJson = ( value: unknown ): boolean => {
	if ( null === value || undefined === value ) {
		return true;
	}

	if ( Array.isArray( value ) ) {
		return 0 === value.length;
	}

	if ( 'object' === typeof value ) {
		return 0 === Object.keys( value ).length;
	}

	return false;
};

/**
 * Reports whether a schema has anything to check against: a non-empty object.
 *
 * @param schema The schema.
 * @return True when there is a schema.
 */
const hasSchema = ( schema: unknown ): schema is JsonRecord =>
	isJsonRecord( schema ) && ! isEmptyJson( schema );

/**
 * Checks a value against one JSON Schema type name.
 *
 * @param value        The value.
 * @param expectedType The type name.
 * @return True when the value matches, or the type is not one this checks.
 */
const matchesTypeName = ( value: unknown, expectedType: unknown ): boolean => {
	switch ( expectedType ) {
		case 'string':
			return 'string' === typeof value;
		case 'number':
			return 'number' === typeof value && Number.isFinite( value );
		case 'integer':
			return Number.isInteger( value );
		case 'boolean':
			return 'boolean' === typeof value;
		case 'array':
			return Array.isArray( value );
		case 'object':
			return isJsonRecord( value );
		case 'null':
			return null === value;
		default:
			return true;
	}
};

/**
 * Checks a value against a schema `type`, which may be a list of names.
 *
 * @param value The value.
 * @param type  The schema's `type`.
 * @return True when the value matches.
 */
const matchesType = ( value: unknown, type: unknown ): boolean => {
	if ( Array.isArray( type ) ) {
		return (
			0 === type.length ||
			type.some( ( name ) => matchesTypeName( value, name ) )
		);
	}

	return matchesTypeName( value, type );
};

/**
 * Formats a schema `type` for a message.
 *
 * @param type The schema's `type`.
 * @return The type as text.
 */
const typeLabel = ( type: unknown ): string =>
	Array.isArray( type ) ? type.map( String ).join( ', ' ) : String( type );

/**
 * Checks one present property against its schema.
 *
 * @param name   The property name.
 * @param value  The property value.
 * @param schema The property schema.
 * @return The error messages.
 */
const validateProperty = (
	name: string,
	value: unknown,
	schema: JsonRecord
): string[] => {
	const errors: string[] = [];
	const { type, minimum, maximum, enum: allowed } = schema;

	if ( undefined !== type && ! matchesType( value, type ) ) {
		return [
			sprintf(
				/* translators: 1: field name, 2: JSON Schema type. */
				__( 'Field "%1$s" should be of type "%2$s"', 'ai' ),
				name,
				typeLabel( type )
			),
		];
	}

	if ( Array.isArray( allowed ) && ! allowed.includes( value ) ) {
		errors.push(
			sprintf(
				/* translators: 1: field name, 2: comma-separated allowed values. */
				__( 'Field "%1$s" must be one of: %2$s', 'ai' ),
				name,
				allowed.map( String ).join( ', ' )
			)
		);
	}

	if (
		'number' === typeof value &&
		'number' === typeof minimum &&
		value < minimum
	) {
		errors.push(
			sprintf(
				/* translators: 1: field name, 2: minimum value. */
				__( 'Field "%1$s" must be at least %2$s', 'ai' ),
				name,
				String( minimum )
			)
		);
	}

	if (
		'number' === typeof value &&
		'number' === typeof maximum &&
		value > maximum
	) {
		errors.push(
			sprintf(
				/* translators: 1: field name, 2: maximum value. */
				__( 'Field "%1$s" must be at most %2$s', 'ai' ),
				name,
				String( maximum )
			)
		);
	}

	return errors;
};

/**
 * Checks decoded input against an input schema.
 *
 * @param input  The decoded input; `null` means "no input".
 * @param schema The ability's input schema.
 * @return The error messages; empty when the input is valid.
 */
export function validateAgainstSchema(
	input: unknown,
	schema: unknown
): string[] {
	if ( ! hasSchema( schema ) ) {
		return [];
	}

	const errors: string[] = [];
	const { type, required, properties } = schema;

	// Input that is not an object is reported, not read with `in`.
	if (
		null !== input &&
		undefined !== type &&
		! matchesType( input, type )
	) {
		errors.push(
			sprintf(
				/* translators: %s: JSON Schema type. */
				__( 'Input should be of type "%s"', 'ai' ),
				typeLabel( type )
			)
		);
	}

	const fields: JsonRecord = isJsonRecord( input ) ? input : {};

	// A field present as null is missing, as PHP's isset() sees it.
	const isPresent = ( name: string ): boolean =>
		Object.prototype.hasOwnProperty.call( fields, name ) &&
		null !== fields[ name ];

	if ( Array.isArray( required ) ) {
		required.forEach( ( field ) => {
			const name = String( field );

			if ( ! isPresent( name ) ) {
				errors.push(
					sprintf(
						/* translators: %s: field name. */
						__( 'Required field "%s" is missing', 'ai' ),
						name
					)
				);
			}
		} );
	}

	if ( isJsonRecord( properties ) ) {
		Object.keys( properties ).forEach( ( name ) => {
			const propertySchema = properties[ name ];

			if ( ! isPresent( name ) || ! isJsonRecord( propertySchema ) ) {
				return;
			}

			errors.push(
				...validateProperty( name, fields[ name ], propertySchema )
			);
		} );
	}

	return errors;
}

/**
 * Checks the runner's textarea: its JSON syntax, then the input schema.
 *
 * An empty textarea means "no input", as it does when invoking, so it is
 * checked as `null` rather than reported as invalid JSON.
 *
 * @param rawInput The textarea's value.
 * @param schema   The ability's input schema.
 * @return The outcome and the messages to show.
 */
export function validateInput(
	rawInput: string,
	schema: unknown
): ValidationResult {
	const trimmed = rawInput.trim();
	let input: unknown = null;

	if ( '' !== trimmed ) {
		try {
			input = JSON.parse( trimmed );
		} catch ( error ) {
			return {
				valid: false,
				messages: [
					sprintf(
						/* translators: %s: the JSON parser's error message. */
						__( 'Invalid JSON input: %s', 'ai' ),
						error instanceof Error ? error.message : String( error )
					),
				],
			};
		}
	} else if ( ! hasSchema( schema ) ) {
		return {
			valid: true,
			messages: [ __( 'No input will be sent.', 'ai' ) ],
		};
	}

	if ( ! hasSchema( schema ) ) {
		return {
			valid: true,
			messages: [ __( 'JSON syntax is valid', 'ai' ) ],
		};
	}

	const errors = validateAgainstSchema( input, schema );

	if ( errors.length > 0 ) {
		return { valid: false, messages: errors };
	}

	return {
		valid: true,
		messages: [ __( 'Input is valid according to the schema', 'ai' ) ],
	};
}

/**
 * Reports whether the textarea holds JSON the server will accept as input:
 * empty ("no input") or parseable.
 *
 * @param rawInput The textarea's value.
 * @return True when the input can be sent.
 */
export function isSendableJson( rawInput: string ): boolean {
	const trimmed = rawInput.trim();

	if ( '' === trimmed ) {
		return true;
	}

	try {
		JSON.parse( trimmed );

		return true;
	} catch {
		return false;
	}
}
