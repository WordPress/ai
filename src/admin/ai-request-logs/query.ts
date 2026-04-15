import type { LogsQuery } from './types';

const DEFAULT_PAGE = 1;
const DEFAULT_PER_PAGE = 25;

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

export const isModelDiscoveryOperation = ( operation: string ): boolean =>
	operation.endsWith( ':models' ) || 'models' === operation;

export const getDefaultOperationSelection = (
	operations: string[]
): string[] => {
	const availableOperations = sanitizeStringArray( operations );
	const nonModelOperations = availableOperations.filter(
		( operation ) => ! isModelDiscoveryOperation( operation )
	);

	return nonModelOperations.length > 0
		? nonModelOperations
		: availableOperations;
};

const normalizeOperationSelection = (
	raw: unknown,
	availableOperations: string[]
): string[] => {
	if ( undefined === raw ) {
		return getDefaultOperationSelection( availableOperations );
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

	const filteredOperations = normalizedOperations.filter( ( operation ) =>
		availableOperations.includes( operation )
	);

	return filteredOperations.length > 0
		? filteredOperations
		: getDefaultOperationSelection( availableOperations );
};

export const getDefaultLogsQuery = ( operations: string[] ): LogsQuery => ( {
	page: DEFAULT_PAGE,
	perPage: DEFAULT_PER_PAGE,
	search: '',
	type: '',
	status: '',
	provider: '',
	operation: getDefaultOperationSelection( operations ),
	tokensFilter: '',
} );

export const normalizeLogsQuery = (
	raw: unknown,
	availableOperations: string[]
): LogsQuery => {
	const parsed =
		raw && typeof raw === 'object' ? ( raw as Partial< LogsQuery > ) : {};
	const defaultQuery = getDefaultLogsQuery( availableOperations );

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
	};
};

export const areOperationsEqual = (
	left: string[],
	right: string[]
): boolean => {
	const normalizedLeft = [ ...left ].sort();
	const normalizedRight = [ ...right ].sort();

	if ( normalizedLeft.length !== normalizedRight.length ) {
		return false;
	}

	return normalizedLeft.every(
		( operation, index ) => operation === normalizedRight[ index ]
	);
};

export const serializeOperationSelection = ( operations: string[] ): string =>
	operations.join( ',' );
