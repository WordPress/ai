/**
 * A minimal stand-in for Rank Math's `rank-math` editor store, used by the Meta Description
 * E2E spec to check the Rank Math adapter without installing Rank Math.
 *
 * It only has the parts the adapter uses: `updateDescription`, `updateSerpDescription`,
 * `getDescription` and `getSerpDescription`.
 */
( function ( wp ) {
	const DEFAULT_STATE = { description: '', serpDescription: '' };

	wp.data.register(
		wp.data.createReduxStore( 'rank-math', {
			reducer( state = DEFAULT_STATE, action ) {
				switch ( action.type ) {
					case 'UPDATE_DESCRIPTION':
						return { ...state, description: action.value };
					case 'UPDATE_SERP_DESCRIPTION':
						return { ...state, serpDescription: action.value };
					default:
						return state;
				}
			},
			actions: {
				updateDescription: ( value ) => ( {
					type: 'UPDATE_DESCRIPTION',
					value,
				} ),
				updateSerpDescription: ( value ) => ( {
					type: 'UPDATE_SERP_DESCRIPTION',
					value,
				} ),
			},
			selectors: {
				getDescription: ( state ) => state.description,
				getSerpDescription: ( state ) => state.serpDescription,
			},
		} )
	);
} )( window.wp );
