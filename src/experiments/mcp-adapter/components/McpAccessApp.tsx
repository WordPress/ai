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
import {
	DataViews,
	filterSortAndPaginate,
	type View,
} from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getErrorMessage } from '../../../utils/errors';
import type {
	McpAbility,
	McpPluginState,
	McpSettings,
	PendingOverrides,
} from '../types';

const SETTINGS_PATH = '/ai/v1/mcp/settings';

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	titleField: 'ability',
	fields: [ 'exposed', 'description', 'status' ],
	layout: {
		styles: {
			exposed: { width: 100 },
			description: { maxWidth: 400 },
			status: { width: 130 },
		},
	},
};

function autoinstallMessage( plugin: McpPluginState, canFix: boolean ): string {
	if ( plugin.autoinstall_error ) {
		return sprintf(
			/* translators: %s: error message. */
			__(
				'The automatic installation failed and will not retry on its own: %s',
				'ai'
			),
			plugin.autoinstall_error
		);
	}

	if ( plugin.autoinstall_handled ) {
		return __(
			'Automatic installation already ran for this experiment activation; it will run again if the experiment is turned off and on.',
			'ai'
		);
	}

	if ( ! canFix ) {
		return plugin.status === 'missing'
			? __(
					'It is installed and activated automatically when an administrator with plugin-install permissions next visits the dashboard.',
					'ai'
			  )
			: __(
					'It is activated automatically when an administrator with plugin-activation permissions next visits the dashboard.',
					'ai'
			  );
	}

	return __(
		'It is installed and activated automatically on the next admin page load.',
		'ai'
	);
}

export default function McpAccessApp() {
	const [ settings, setSettings ] = useState< McpSettings | null >( null );
	const [ pending, setPending ] = useState< PendingOverrides >( {} );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
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

	const hasSavedOverride = useCallback(
		( name: string ): boolean =>
			Boolean( settings && name in settings.overrides ),
		[ settings ]
	);

	// A pending entry always wins over the saved state; `null` means the
	// saved override is being cleared back to the ability's default.
	const hasOverride = useCallback(
		( ability: McpAbility ): boolean => {
			const edit = pending[ ability.name ];
			if ( edit !== undefined ) {
				return edit !== null;
			}
			return hasSavedOverride( ability.name );
		},
		[ pending, hasSavedOverride ]
	);

	const effectiveExposed = useCallback(
		( ability: McpAbility ): boolean => {
			const edit = pending[ ability.name ];
			if ( edit !== undefined ) {
				return edit === null ? ability.default : edit;
			}
			return ability.exposed;
		},
		[ pending ]
	);

	const setExposed = useCallback(
		( ability: McpAbility, checked: boolean ) => {
			setPending( ( prev ) => {
				const next = { ...prev };
				const saved = settings?.overrides[ ability.name ];
				if ( saved !== undefined && checked === saved ) {
					// Back to the saved state: nothing to change.
					delete next[ ability.name ];
				} else if (
					checked === ability.default &&
					! hasSavedOverride( ability.name )
				) {
					delete next[ ability.name ];
				} else if (
					checked === ability.default &&
					hasSavedOverride( ability.name )
				) {
					next[ ability.name ] = null;
				} else {
					next[ ability.name ] = checked;
				}
				return next;
			} );
		},
		[ hasSavedOverride, settings ]
	);

	const resetToDefault = useCallback(
		( abilities: McpAbility[] ) => {
			setPending( ( prev ) => {
				const next = { ...prev };
				for ( const ability of abilities ) {
					if ( hasSavedOverride( ability.name ) ) {
						next[ ability.name ] = null;
					} else {
						delete next[ ability.name ];
					}
				}
				return next;
			} );
		},
		[ hasSavedOverride ]
	);

	const fields = useMemo(
		() => [
			{
				id: 'exposed',
				label: __( 'Exposed', 'ai' ),
				type: 'boolean' as const,
				enableSorting: true,
				enableHiding: false,
				getValue: ( { item }: { item: McpAbility } ) =>
					effectiveExposed( item ),
				render: ( { item }: { item: McpAbility } ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						checked={ effectiveExposed( item ) }
						onChange={ ( checked ) => setExposed( item, checked ) }
						aria-label={ sprintf(
							/* translators: %s: ability name. */
							__( 'Expose %s over MCP', 'ai' ),
							item.name
						) }
					/>
				),
			},
			{
				id: 'ability',
				label: __( 'Ability', 'ai' ),
				enableSorting: true,
				enableGlobalSearch: true,
				getValue: ( { item }: { item: McpAbility } ) =>
					`${ item.label } ${ item.name }`,
				render: ( { item }: { item: McpAbility } ) => (
					<div className="ai-mcp-access__ability">
						<strong>{ item.label }</strong>
						<code>{ item.name }</code>
					</div>
				),
			},
			{
				id: 'description',
				label: __( 'Description', 'ai' ),
				enableSorting: false,
				enableGlobalSearch: true,
				getValue: ( { item }: { item: McpAbility } ) =>
					item.description,
				render: ( { item }: { item: McpAbility } ) => (
					<div
						className="ai-mcp-access__description"
						title={ item.description }
					>
						{ item.description }
					</div>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'ai' ),
				enableSorting: false,
				getValue: ( { item }: { item: McpAbility } ) =>
					hasOverride( item )
						? __( 'Overridden', 'ai' )
						: __( 'Default', 'ai' ),
				render: ( { item }: { item: McpAbility } ) =>
					hasOverride( item )
						? __( 'Overridden', 'ai' )
						: __( 'Default', 'ai' ),
			},
		],
		[ effectiveExposed, hasOverride, setExposed ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'reset-to-default',
				label: __( 'Reset to default', 'ai' ),
				supportsBulk: true,
				isEligible: ( item: McpAbility ) => hasOverride( item ),
				callback: ( items: McpAbility[] ) => resetToDefault( items ),
			},
		],
		[ hasOverride, resetToDefault ]
	);

	const { data: shownAbilities, paginationInfo } = useMemo( () => {
		if ( ! settings ) {
			return {
				data: [],
				paginationInfo: { totalItems: 0, totalPages: 0 },
			};
		}
		return filterSortAndPaginate( settings.abilities, view, fields );
	}, [ settings, view, fields ] );

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
			const updated = await apiFetch< McpSettings >( {
				path: SETTINGS_PATH,
			} );
			setSettings( updated );
		} catch ( error ) {
			setSaveError(
				getErrorMessage(
					error,
					settings.plugin.status === 'missing'
						? __( 'Failed to install the plugin.', 'ai' )
						: __( 'Failed to activate the plugin.', 'ai' )
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
								'The MCP Adapter plugin is not active. Exposure choices are saved and take effect once it is active.',
								'ai'
						  )
						: __(
								'The MCP Adapter plugin is installed but not active. Exposure choices are saved and take effect once it is active.',
								'ai'
						  ) }{ ' ' }
					{ autoinstallMessage( plugin, canFix ) }{ ' ' }
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

			<div className="ai-mcp-access__dataviews-wrap">
				<DataViews
					data={ shownAbilities }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					getItemId={ ( item: McpAbility ) => item.name }
					isLoading={ false }
					defaultLayouts={ { table: {} } }
				/>
			</div>

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
