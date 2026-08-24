/**
 * MCP Access management screen.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { McpSettings } from '../types';

const SETTINGS_PATH = '/ai/v1/mcp/settings';

export default function McpAccessApp() {
	const [ settings, setSettings ] = useState< McpSettings | null >( null );
	const [ overrides, setOverrides ] = useState<
		Record< string, boolean | null >
	>( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ savedNotice, setSavedNotice ] = useState( false );

	useEffect( () => {
		apiFetch< McpSettings >( { path: SETTINGS_PATH } )
			.then( ( data ) => setSettings( data ) )
			.catch( () =>
				setError( __( 'Failed to load MCP settings.', 'ai' ) )
			);
	}, [] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( ! settings ) {
		return <Spinner />;
	}

	const effectiveExposed = ( name: string, fallback: boolean ): boolean => {
		const pending = overrides[ name ];
		if ( pending !== undefined && pending !== null ) {
			return pending;
		}

		const saved = settings.overrides[ name ];
		if ( saved !== undefined ) {
			return saved;
		}

		return fallback;
	};

	const isDirty = Object.keys( overrides ).length > 0;

	const save = async () => {
		setIsSaving( true );
		setError( null );
		try {
			const updated = await apiFetch< McpSettings >( {
				path: SETTINGS_PATH,
				method: 'POST',
				data: { overrides },
			} );
			setSettings( updated );
			setOverrides( {} );
			setSavedNotice( true );
		} catch {
			setError( __( 'Failed to save MCP settings.', 'ai' ) );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<div className="ai-mcp-access">
			{ ! settings.adapter_active && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The MCP Adapter plugin is not active. Exposure choices are saved and will take effect once it is installed and activated.',
						'ai'
					) }
				</Notice>
			) }

			{ settings.adapter_active && settings.endpoint && (
				<p>
					{ sprintf(
						/* translators: %s: MCP server endpoint URL. */
						__( 'MCP server endpoint: %s', 'ai' ),
						settings.endpoint
					) }
				</p>
			) }

			{ savedNotice && (
				<Notice
					status="success"
					onRemove={ () => setSavedNotice( false ) }
				>
					{ __( 'MCP exposure settings saved.', 'ai' ) }
				</Notice>
			) }

			<p>
				{ __(
					'Choose which abilities are exposed to AI agents through the MCP server. Abilities keep their default visibility unless changed here.',
					'ai'
				) }
			</p>

			<table className="widefat striped">
				<thead>
					<tr>
						<th scope="col">{ __( 'Exposed', 'ai' ) }</th>
						<th scope="col">{ __( 'Ability', 'ai' ) }</th>
						<th scope="col">{ __( 'Description', 'ai' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ settings.abilities.map( ( ability ) => (
						<tr key={ ability.name }>
							<td>
								<CheckboxControl
									__nextHasNoMarginBottom
									checked={ effectiveExposed(
										ability.name,
										ability.exposed
									) }
									onChange={ ( checked ) =>
										setOverrides( ( prev ) => ( {
											...prev,
											[ ability.name ]: checked,
										} ) )
									}
									aria-label={ sprintf(
										/* translators: %s: ability name. */
										__( 'Expose %s over MCP', 'ai' ),
										ability.name
									) }
								/>
							</td>
							<td>
								<strong>{ ability.label }</strong>
								<br />
								<code>{ ability.name }</code>
							</td>
							<td>{ ability.description }</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<p>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ save }
					isBusy={ isSaving }
					disabled={ ! isDirty || isSaving }
				>
					{ __( 'Save changes', 'ai' ) }
				</Button>
			</p>
		</div>
	);
}
