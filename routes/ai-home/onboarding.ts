/**
 * Types and parsing for the Get started guide data passed from PHP.
 */

export interface TryPointer {
	label: string;
	description: string;
	url: string;
	linkText: string;
}

export interface UseCaseAddon {
	id: string;
	label: string;
	description: string;
	features: string[];
	try: TryPointer | null;
}

export interface UseCase {
	id: string;
	label: string;
	description: string;
	sprite: string;
	features: string[];
	unsupported: string[];
	addon: UseCaseAddon | null;
	try: TryPointer | null;
}

export interface OnboardingData {
	useCases: UseCase[];
	selected: string[];
	completed: boolean;
	hidden: boolean;
	enabledCount: number;
	providerName: string;
	sprites: Record< string, string >;
}

export const USE_CASES_SETTING = 'wpai_onboarding_use_cases';
export const COMPLETED_SETTING = 'wpai_onboarding_completed';
export const HIDDEN_USER_META = 'wpai_onboarding_guide_hidden';

type Raw< T > = { [ K in keyof T ]?: unknown };

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

function toStringValue( value: unknown ): string {
	return typeof value === 'string' ? value : '';
}

/**
 * Returns the string items of a value, or an empty list when it is not an array.
 *
 * @param value The value to read.
 * @return The string items.
 */
export function toStringList( value: unknown ): string[] {
	return Array.isArray( value )
		? value.filter( ( item ): item is string => typeof item === 'string' )
		: [];
}

function parseTryPointer( value: unknown ): TryPointer | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const raw = value as Raw< TryPointer >;

	const label = toStringValue( raw.label );
	const url = toStringValue( raw.url );
	if ( ! label || ! url ) {
		return null;
	}

	return {
		label,
		url,
		description: toStringValue( raw.description ),
		linkText: toStringValue( raw.linkText ),
	};
}

function parseAddon( value: unknown ): UseCaseAddon | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const raw = value as Raw< UseCaseAddon >;

	const id = toStringValue( raw.id );
	const features = toStringList( raw.features );
	if ( ! id || features.length === 0 ) {
		return null;
	}

	return {
		id,
		features,
		label: toStringValue( raw.label ) || id,
		description: toStringValue( raw.description ),
		try: parseTryPointer( raw.try ),
	};
}

function parseUseCase( value: unknown ): UseCase | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const raw = value as Raw< UseCase >;

	const id = toStringValue( raw.id );
	const features = toStringList( raw.features );
	if ( ! id || features.length === 0 ) {
		return null;
	}

	return {
		id,
		features,
		label: toStringValue( raw.label ) || id,
		description: toStringValue( raw.description ),
		sprite: toStringValue( raw.sprite ),
		unsupported: toStringList( raw.unsupported ),
		addon: parseAddon( raw.addon ),
		try: parseTryPointer( raw.try ),
	};
}

/**
 * Parses the `onboarding` entry of the settings page data.
 *
 * @param value The raw value from the script module data.
 * @return The guide data, or null when it is missing or has no use cases.
 */
export function parseOnboardingData( value: unknown ): OnboardingData | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const raw = value as Raw< OnboardingData >;
	if ( ! Array.isArray( raw.useCases ) ) {
		return null;
	}

	const useCases = raw.useCases
		.map( parseUseCase )
		.filter( ( useCase ): useCase is UseCase => useCase !== null );

	if ( useCases.length === 0 ) {
		return null;
	}

	const sprites: Record< string, string > = {};
	if ( isRecord( raw.sprites ) ) {
		for ( const [ pose, url ] of Object.entries( raw.sprites ) ) {
			if ( typeof url === 'string' && url ) {
				sprites[ pose ] = url;
			}
		}
	}

	return {
		useCases,
		sprites,
		selected: toStringList( raw.selected ),
		completed: Boolean( raw.completed ),
		hidden: Boolean( raw.hidden ),
		enabledCount:
			typeof raw.enabledCount === 'number' ? raw.enabledCount : 0,
		providerName: toStringValue( raw.providerName ),
	};
}

/**
 * Returns the setting name that turns a feature on or off.
 *
 * @param featureId The feature ID.
 * @return The setting name.
 */
export function getFeatureSettingName( featureId: string ): string {
	return `wpai_feature_${ featureId }_enabled`;
}

/**
 * Returns the feature IDs that a set of chosen use cases and add-ons turns on.
 *
 * An add-on only counts when its use case is chosen too.
 *
 * @param useCases The use case definitions.
 * @param chosen   The chosen use case and add-on IDs.
 * @return The feature IDs, without duplicates.
 */
export function getChosenFeatureIds(
	useCases: UseCase[],
	chosen: string[]
): string[] {
	const featureIds = new Set< string >();

	for ( const useCase of useCases ) {
		if ( ! chosen.includes( useCase.id ) ) {
			continue;
		}

		useCase.features.forEach( ( id ) => featureIds.add( id ) );

		if ( useCase.addon && chosen.includes( useCase.addon.id ) ) {
			useCase.addon.features.forEach( ( id ) => featureIds.add( id ) );
		}
	}

	return Array.from( featureIds );
}

/**
 * Builds the settings edits that move from one set of choices to another.
 *
 * Tools in the new choices are turned on. Tools that only the previous choices
 * turned on are turned off. Tools the guide never turned on are left alone.
 *
 * @param useCases The use case definitions.
 * @param previous The previously saved use case and add-on IDs.
 * @param next     The newly chosen use case and add-on IDs.
 * @return The edits to save, keyed by setting name.
 */
export function buildUseCaseEdits(
	useCases: UseCase[],
	previous: string[],
	next: string[]
): Record< string, unknown > {
	const edits: Record< string, unknown > = {};
	const nextFeatureIds = getChosenFeatureIds( useCases, next );

	for ( const featureId of getChosenFeatureIds( useCases, previous ) ) {
		if ( ! nextFeatureIds.includes( featureId ) ) {
			edits[ getFeatureSettingName( featureId ) ] = false;
		}
	}

	for ( const featureId of nextFeatureIds ) {
		edits[ getFeatureSettingName( featureId ) ] = true;
	}

	edits[ USE_CASES_SETTING ] = normalizeChosen( useCases, next );

	return edits;
}

/**
 * Orders chosen IDs like the definitions and drops add-ons without their use case.
 *
 * @param useCases The use case definitions.
 * @param chosen   The chosen use case and add-on IDs.
 * @return The normalized IDs.
 */
export function normalizeChosen(
	useCases: UseCase[],
	chosen: string[]
): string[] {
	const normalized: string[] = [];

	for ( const useCase of useCases ) {
		if ( ! chosen.includes( useCase.id ) ) {
			continue;
		}

		normalized.push( useCase.id );

		if ( useCase.addon && chosen.includes( useCase.addon.id ) ) {
			normalized.push( useCase.addon.id );
		}
	}

	return normalized;
}
