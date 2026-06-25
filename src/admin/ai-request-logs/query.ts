/**
 * Internal dependencies
 */
import type { LogsQuery } from './types';

/**
 * Default values for the logs query.
 */
const DEFAULT_PAGE = 1;
const DEFAULT_PER_PAGE = 25;

export const DEFAULT_VIEW_FIELDS = [
	'timestamp',
	'operation',
	'provider',
	'tokens_total',
	'duration_ms',
	'status',
];

const sanitizeStringArray = ( value: unknown ): string[] => {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return Array.from(
		new Set(
			value.filter(
				( item ): item is string =>
					typeof item === 'string' && item.trim().length > 0
			)
		)
	);
};

const normalizeOperationSelection = (
	raw: unknown,
	availableOperations: string[]
): string[] => {
	if ( undefined === raw || ( Array.isArray( raw ) && 0 === raw.length ) ) {
		return [];
	}

	const rawOperations =
		typeof raw === 'string'
			? raw
					.split( ',' )
					.map( ( operation ) => operation.trim() )
					.filter( Boolean )
			: sanitizeStringArray( raw );

	const normalizedOperations = Array.from( new Set( rawOperations ) );

	if ( 0 === availableOperations.length ) {
		return normalizedOperations;
	}

	return normalizedOperations.filter( ( operation ) =>
		availableOperations.includes( operation )
	);
};

const normalizeFields = ( raw: unknown ): string[] => {
	// When no field order is stored yet, fall back to the default order.
	if ( undefined === raw || null === raw ) {
		return [ ...DEFAULT_VIEW_FIELDS ];
	}

	const sanitized = sanitizeStringArray( raw ).filter( ( field ) =>
		DEFAULT_VIEW_FIELDS.includes( field )
	);

	// If nothing valid was stored, fall back to the default order.
	// A shorter list is valid: the user may have hidden some columns.
	if ( 0 === sanitized.length ) {
		return [ ...DEFAULT_VIEW_FIELDS ];
	}

	return sanitized;
};

export const getDefaultLogsQuery = (): LogsQuery => ( {
	page: DEFAULT_PAGE,
	perPage: DEFAULT_PER_PAGE,
	search: '',
	type: '',
	status: '',
	provider: '',
	operation: [],
	tokensFilter: '',
	userId: '',
	orderby: 'timestamp',
	order: 'desc',
	fields: [ ...DEFAULT_VIEW_FIELDS ],
} );

export const normalizeLogsQuery = (
	raw: unknown,
	availableOperations: string[]
): LogsQuery => {
	const parsed =
		raw && typeof raw === 'object' ? ( raw as Partial< LogsQuery > ) : {};
	const defaultQuery = getDefaultLogsQuery();

	return {
		page:
			typeof parsed.page === 'number' && parsed.page > 0
				? Math.floor( parsed.page )
				: defaultQuery.page,
		perPage:
			typeof parsed.perPage === 'number' && parsed.perPage > 0
				? Math.floor( parsed.perPage )
				: defaultQuery.perPage,
		search:
			typeof parsed.search === 'string'
				? parsed.search
				: defaultQuery.search,
		type: typeof parsed.type === 'string' ? parsed.type : defaultQuery.type,
		status:
			typeof parsed.status === 'string'
				? parsed.status
				: defaultQuery.status,
		provider:
			typeof parsed.provider === 'string'
				? parsed.provider
				: defaultQuery.provider,
		operation: normalizeOperationSelection(
			parsed.operation,
			availableOperations
		),
		tokensFilter:
			typeof parsed.tokensFilter === 'string'
				? parsed.tokensFilter
				: defaultQuery.tokensFilter,
		userId:
			typeof parsed.userId === 'string'
				? parsed.userId
				: defaultQuery.userId,
		orderby:
			typeof parsed.orderby === 'string' && parsed.orderby.length > 0
				? parsed.orderby
				: defaultQuery.orderby,
		order:
			'asc' === parsed.order || 'desc' === parsed.order
				? parsed.order
				: defaultQuery.order,
		fields: normalizeFields( parsed.fields ),
	};
};

export const serializeOperationSelection = ( operations: string[] ): string =>
	operations.join( ',' );
