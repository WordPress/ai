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
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getErrorMessage } from '../../../utils/errors';
import type { McpAbility, McpSettings, PendingOverrides } from '../types';

const SETTINGS_PATH = '/ai/v1/mcp/settings';

export default function McpAccessApp() {
	const [ settings, setSettings ] = useState< McpSettings | null >( null );
	const [ pending, setPending ] = useState< PendingOverrides >( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isInstalling, setIsInstalling ] = useState( false );
	const [ loadError, setLoadError ] = useState< string | null >( null );
	const [ saveError, setSaveError ] = useState< string | null >( null );
	const [ savedNotice, setSavedNotice ] = useState( false );

	const load = useCallback( () => {
		setLoadError( null );
		apiFetch< McpSettings >( { path: SETTINGS_PATH } )
			.then( ( data ) => setSettings( data ) )
			.catch( ( error ) =>
				setLoadError(
					getErrorMessage(
						error,
						__( 'Failed to load MCP settings.', 'ai' )
					)
				)
			);
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }{ ' ' }
				<Button variant="link" onClick={ load }>
					{ __( 'Retry', 'ai' ) }
				</Button>
			</Notice>
		);
	}

	if ( ! settings ) {
		return <Spinner />;
	}

	const hasSavedOverride = ( name: string ): boolean =>
		name in settings.overrides;

	// A pending entry always wins over the saved state; `null` means the
	// saved override is being cleared back to the ability's default.
	const hasOverride = ( ability: McpAbility ): boolean => {
		const edit = pending[ ability.name ];
		if ( edit !== undefined ) {
			return edit !== null;
		}
		return hasSavedOverride( ability.name );
	};

	const effectiveExposed = ( ability: McpAbility ): boolean => {
		const edit = pending[ ability.name ];
		if ( edit !== undefined ) {
			return edit === null ? ability.default : edit;
		}
		return ability.exposed;
	};

	const setExposed = ( ability: McpAbility, checked: boolean ) => {
		setPending( ( prev ) => {
			const next = { ...prev };
			if (
				checked === ability.default &&
				! hasSavedOverride( ability.name )
			) {
				// Back to the untouched default: no override needed.
				delete next[ ability.name ];
			} else if (
				checked === ability.default &&
				hasSavedOverride( ability.name )
			) {
				// Matches the default but an override is stored: clear it.
				next[ ability.name ] = null;
			} else {
				next[ ability.name ] = checked;
			}
			return next;
		} );
	};

	const resetToDefault = ( ability: McpAbility ) => {
		setPending( ( prev ) => {
			const next = { ...prev };
			if ( hasSavedOverride( ability.name ) ) {
				next[ ability.name ] = null;
			} else {
				delete next[ ability.name ];
			}
			return next;
		} );
	};

	const isDirty = Object.keys( pending ).length > 0;

	const save = async () => {
		setIsSaving( true );
		setSaveError( null );
		try {
			const updated = await apiFetch< McpSettings >( {
				path: SETTINGS_PATH,
				method: 'POST',
				data: { overrides: pending },
			} );
			setSettings( updated );
			setPending( {} );
			setSavedNotice( true );
		} catch ( error ) {
			setSaveError(
				getErrorMessage(
					error,
					__( 'Failed to save MCP settings.', 'ai' )
				)
			);
		} finally {
			setIsSaving( false );
		}
	};

	const installOrActivate = async () => {
		setIsInstalling( true );
		setSaveError( null );
		try {
			if ( settings.plugin.status === 'missing' ) {
				await apiFetch( {
					path: '/wp/v2/plugins',
					method: 'POST',
					data: { slug: settings.plugin.slug, status: 'active' },
				} );
			} else {
				await apiFetch( {
					path: `/wp/v2/plugins/${ settings.plugin.file?.replace(
						/\.php$/,
						''
					) }`,
					method: 'PUT',
					data: { status: 'active' },
				} );
			}
			// Re-fetch: plugin state, adapter detection, and endpoint all changed.
			const updated = await apiFetch< McpSettings >( {
				path: SETTINGS_PATH,
			} );
			setSettings( updated );
		} catch ( error ) {
			setSaveError(
				getErrorMessage(
					error,
					__( 'Failed to install the plugin.', 'ai' )
				)
			);
		} finally {
			setIsInstalling( false );
		}
	};

	const plugin = settings.plugin;
	const canFix =
		plugin.status === 'missing' ? plugin.can_install : plugin.can_activate;

	return (
		<div className="ai-mcp-access">
			{ plugin.status !== 'active' && (
				<Notice status="warning" isDismissible={ false }>
					{ plugin.status === 'missing'
						? __(
								'The MCP Adapter plugin is not active yet. It is installed and activated automatically from WordPress.org while this experiment is enabled. Exposure choices are saved and take effect once it is active.',
								'ai'
						  )
						: __(
								'The MCP Adapter plugin is installed but not active. It is activated automatically while this experiment is enabled. Exposure choices are saved and take effect once it is active.',
								'ai'
						  ) }{ ' ' }
					{ plugin.autoinstall_error &&
						sprintf(
							/* translators: %s: error message. */
							__( 'The last automatic attempt failed: %s', 'ai' ),
							plugin.autoinstall_error
						) }{ ' ' }
					{ canFix && (
						<Button
							__next40pxDefaultSize
							variant="secondary"
							isBusy={ isInstalling }
							disabled={ isInstalling }
							onClick={ installOrActivate }
						>
							{ plugin.status === 'missing'
								? sprintf(
										/* translators: %s: plugin slug. */
										__( 'Install & activate %s', 'ai' ),
										plugin.slug
								  )
								: sprintf(
										/* translators: %s: plugin slug. */
										__( 'Activate %s', 'ai' ),
										plugin.slug
								  ) }
						</Button>
					) }
				</Notice>
			) }

			<Notice status="info" isDismissible={ false }>
				{ __(
					'Exposure choices apply only while the MCP Access experiment is enabled. When it is disabled, abilities return to the defaults the MCP Adapter serves on its own.',
					'ai'
				) }
			</Notice>

			{ settings.adapter_active && settings.endpoint && (
				<p>
					{ sprintf(
						/* translators: %s: MCP server endpoint URL. */
						__( 'MCP server endpoint: %s', 'ai' ),
						settings.endpoint
					) }
				</p>
			) }

			{ settings.adapter_active && ! settings.endpoint && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The MCP Adapter is active but no MCP server is registered on this site.',
						'ai'
					) }
				</Notice>
			) }

			{ saveError && (
				<Notice status="error" onRemove={ () => setSaveError( null ) }>
					{ saveError }
				</Notice>
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
						<th scope="col">{ __( 'Status', 'ai' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ settings.abilities.map( ( ability ) => (
						<tr key={ ability.name }>
							<td>
								<CheckboxControl
									__nextHasNoMarginBottom
									checked={ effectiveExposed( ability ) }
									onChange={ ( checked ) =>
										setExposed( ability, checked )
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
							<td>
								{ hasOverride( ability ) ? (
									<>
										{ __( 'Overridden', 'ai' ) }{ ' ' }
										<Button
											variant="link"
											onClick={ () =>
												resetToDefault( ability )
											}
										>
											{ __( 'Reset to default', 'ai' ) }
										</Button>
									</>
								) : (
									__( 'Default', 'ai' )
								) }
							</td>
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
