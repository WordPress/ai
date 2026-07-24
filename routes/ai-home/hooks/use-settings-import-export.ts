/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useRegistry } from '@wordpress/data';
import { useCallback, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/** Shape of an AI settings export payload (matches the PHP schema). */
export interface ExportPayload {
	version: number;
	exported_at: string;
	plugin_version: string;
	providers: Record< string, unknown >;
	settings: Record< string, unknown >;
}
function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

/**
 * Checks whether a parsed JSON value matches the export payload shape.
 *
 * @param {unknown} value The value to check.
 * @return {boolean} Whether the value is a valid export payload.
 */
export function isExportPayload( value: unknown ): value is ExportPayload {
	if ( ! isRecord( value ) ) {
		return false;
	}
	return (
		// eslint-disable-next-line dot-notation
		typeof value[ 'version' ] === 'number' && // eslint-disable-next-line dot-notation
		isRecord( value[ 'providers' ] ) && // eslint-disable-next-line dot-notation
		isRecord( value[ 'settings' ] )
	);
}

interface UseSettingsImportExportReturn {
	fileInputRef: React.RefObject< HTMLInputElement >;
	pendingImport: ExportPayload | null;
	isImporting: boolean;
	handleExport: () => Promise< void >;
	handleImportFileSelect: (
		event: React.ChangeEvent< HTMLInputElement >
	) => void;
	handleImportConfirm: () => Promise< void >;
	handleImportCancel: () => void;
}

/**
 * useSettingsImportExport hook.
 *
 * Encapsulates the export/import file handling, confirmation state, and the
 * REST requests used to export and import non-sensitive AI settings.
 *
 * @return {UseSettingsImportExportReturn} The import/export return object.
 */
export function useSettingsImportExport(): UseSettingsImportExportReturn {
	const fileInputRef = useRef< HTMLInputElement >( null );
	const [ pendingImport, setPendingImport ] =
		useState< ExportPayload | null >( null );
	const [ isImporting, setIsImporting ] = useState( false );
	const registry = useRegistry();

	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const handleExport = useCallback( async () => {
		try {
			const data = await apiFetch< ExportPayload >( {
				path: '/ai/v1/settings/export',
				method: 'GET',
			} );

			const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], {
				type: 'application/json',
			} );
			const url = URL.createObjectURL( blob );
			const anchor = document.createElement( 'a' );
			anchor.href = url;
			anchor.download = 'ai-settings-export.json';
			anchor.click();
			URL.revokeObjectURL( url );
		} catch {
			createErrorNotice( __( 'Failed to export settings.', 'ai' ), {
				type: 'snackbar',
			} );
		}
	}, [ createErrorNotice ] );

	const handleImportFileSelect = useCallback(
		( event: React.ChangeEvent< HTMLInputElement > ) => {
			const file = event.target.files?.[ 0 ];
			if ( ! file ) {
				return;
			}

			// Reset value so the same file can be re-selected after cancel.
			event.target.value = '';

			const reader = new FileReader();
			reader.onload = ( e ) => {
				try {
					const parsed: unknown = JSON.parse(
						( e.target?.result as string ) ?? ''
					);

					if ( ! isExportPayload( parsed ) ) {
						createErrorNotice(
							__(
								'The selected file is not a valid AI settings export.',
								'ai'
							),
							{ type: 'snackbar' }
						);
						return;
					}

					setPendingImport( parsed );
				} catch {
					createErrorNotice(
						__(
							'The selected file could not be read. Please choose a valid JSON file.',
							'ai'
						),
						{ type: 'snackbar' }
					);
				}
			};
			reader.readAsText( file );
		},
		[ createErrorNotice ]
	);

	const handleImportConfirm = useCallback( async () => {
		if ( ! pendingImport ) {
			return;
		}

		setIsImporting( true );
		try {
			await apiFetch( {
				path: '/ai/v1/settings/import',
				method: 'POST',
				data: pendingImport,
			} );
			setPendingImport( null );
			// Invalidate the cached site entity so the store immediately
			// re-fetches the new values from the server, updating the UI
			// without requiring a manual page refresh.
			registry
				.dispatch( coreStore )
				.invalidateResolution( 'getEntityRecord', [ 'root', 'site' ] );
			createSuccessNotice(
				__( 'Settings imported successfully.', 'ai' ),
				{
					type: 'snackbar',
				}
			);
		} catch {
			createErrorNotice( __( 'Failed to import settings.', 'ai' ), {
				type: 'snackbar',
			} );
		} finally {
			setIsImporting( false );
		}
	}, [ pendingImport, createSuccessNotice, createErrorNotice, registry ] );

	const handleImportCancel = useCallback( () => {
		setPendingImport( null );
	}, [] );

	return {
		fileInputRef,
		pendingImport,
		isImporting,
		handleExport,
		handleImportFileSelect,
		handleImportConfirm,
		handleImportCancel,
	};
}
