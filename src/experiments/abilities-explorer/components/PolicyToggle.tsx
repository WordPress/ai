/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { changeSurface } from '../api';
import { useExplorer } from '../context';
import type { PolicyState, SurfaceResponse } from '../types';
import { getSurfaceChangeMessage } from './SurfaceActions';

export interface PolicyToggleProps {
	/** The assistant admission policy state. */
	policy: PolicyState;
	/** Applies a surface response; false when it was stale. */
	onSurfaceResponse: ( response: SurfaceResponse ) => boolean;
}

/**
 * The site-wide switch for the AI Workspace assistant's admission policy.
 *
 * @param props                   Component props.
 * @param props.policy            The policy state.
 * @param props.onSurfaceResponse Applies the surface route's response.
 * @return The toggle.
 */
export default function PolicyToggle( {
	policy,
	onSurfaceResponse,
}: PolicyToggleProps ) {
	const { notify, reportError } = useExplorer();
	const pendingRef = useRef( false );
	const [ isPending, setIsPending ] = useState( false );

	const onClick = async () => {
		if ( pendingRef.current ) {
			return;
		}

		const change = policy.disabled ? 'enable_policy' : 'disable_policy';

		pendingRef.current = true;
		setIsPending( true );

		try {
			const response = await changeSurface( change );

			onSurfaceResponse( response );
			notify( 'success', getSurfaceChangeMessage( change ) );
		} catch ( error ) {
			reportError( error );
		} finally {
			pendingRef.current = false;
			setIsPending( false );
		}
	};

	return (
		<div className="ai-abilities-explorer__policy">
			<span className="ai-abilities-explorer__policy-state">
				{ policy.disabled
					? __(
							'Assistant admission policy: off (built-in abilities only).',
							'ai'
					  )
					: __( 'Assistant admission policy: on.', 'ai' ) }
			</span>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				onClick={ onClick }
				isBusy={ isPending }
				disabled={ isPending }
				accessibleWhenDisabled
			>
				{ policy.disabled
					? __( 'Turn policy on', 'ai' )
					: __( 'Turn policy off', 'ai' ) }
			</Button>
		</div>
	);
}
