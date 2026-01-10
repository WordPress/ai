/* eslint-disable react-hooks/rules-of-hooks -- wp-build requires lowercase 'stage' export name */
/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import {
	Notice,
	Button,
	Spinner,
	SnackbarList,
	__experimentalVStack as VStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
} from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
// eslint-disable-next-line import/no-extraneous-dependencies
import { external } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { ExperimentsList } from '../../src/admin/settings/components';
import type {
	SettingsData,
	DataFormField,
} from '../../src/admin/settings/types';

/**
 * Styles
 */
import '../../src/admin/settings/index.scss';

/**
 * Flask icon for AI Experiments header.
 */
const flaskIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="36"
		height="36"
		fill="currentColor"
	>
		<path d="M9 2v6.5L4.5 17c-.9 1.5.2 3.5 2 3.5h11c1.8 0 2.9-2 2-3.5L15 8.5V2h1V1H8v1h1zm1 0h4v7l4.3 8H5.7L10 9V2zm1 8.5l-.7 1.3h3.4l-.7-1.3V11h-2v-.5z" />
	</svg>
);

/**
 * Main settings stage component.
 */
export const stage = (): JSX.Element => {
	const initialData = window.aiExperimentsSettings;

	const [ data, setData ] = useState< SettingsData | undefined >(
		initialData
	);

	const { createSuccessNotice, createErrorNotice, removeNotice } =
		useDispatch( noticesStore );

	const snackbarNotices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => notice.type === 'snackbar' ),
		[]
	);

	/**
	 * Create a timed snackbar notice that auto-dismisses.
	 */
	const createTimedNotice = useCallback(
		(
			message: string,
			type: 'success' | 'error' = 'success',
			duration: number = 5000
		) => {
			const noticeId = `notice-${ Date.now() }`;
			const createNotice =
				type === 'success' ? createSuccessNotice : createErrorNotice;

			createNotice( message, {
				type: 'snackbar',
				id: noticeId,
			} );

			setTimeout( () => removeNotice( noticeId ), duration );
		},
		[ createSuccessNotice, createErrorNotice, removeNotice ]
	);

	/**
	 * Save settings via REST API.
	 */
	const saveSettings = useCallback(
		async ( settingsUpdate: Record< string, unknown > ) => {
			try {
				await apiFetch( {
					path: '/ai/v1/settings',
					method: 'POST',
					data: settingsUpdate,
				} );
				createTimedNotice( __( 'Settings saved.', 'ai' ) );
			} catch ( error ) {
				createTimedNotice(
					__( 'Failed to save settings.', 'ai' ),
					'error'
				);
			}
		},
		[ createTimedNotice ]
	);

	/**
	 * Handle global toggle change.
	 */
	const handleGlobalChange = useCallback(
		( changes: { globalEnabled?: boolean } ) => {
			if ( typeof changes.globalEnabled !== 'boolean' ) {
				return;
			}

			setData( ( prev ) =>
				prev ? { ...prev, globalEnabled: changes.globalEnabled! } : prev
			);

			saveSettings( { globalEnabled: changes.globalEnabled } );
		},
		[ saveSettings ]
	);

	/**
	 * Handle individual experiment toggle.
	 */
	const handleExperimentToggle = useCallback(
		( experimentId: string, enabled: boolean ) => {
			setData( ( prev ) => {
				if ( ! prev ) {
					return prev;
				}
				return {
					...prev,
					experiments: prev.experiments.map( ( exp ) =>
						exp.id === experimentId ? { ...exp, enabled } : exp
					),
				};
			} );

			saveSettings( {
				experiments: { [ experimentId ]: enabled },
			} );
		},
		[ saveSettings ]
	);

	/**
	 * Handle experiment settings change.
	 */
	const handleExperimentSettingsChange = useCallback(
		(
			experimentId: string,
			settingsChanges: Record< string, unknown >
		) => {
			setData( ( prev ) => {
				if ( ! prev ) {
					return prev;
				}
				return {
					...prev,
					experiments: prev.experiments.map( ( exp ) =>
						exp.id === experimentId
							? {
									...exp,
									settingsValues: {
										...exp.settingsValues,
										...settingsChanges,
									},
							  }
							: exp
					),
				};
			} );

			saveSettings( {
				experimentSettings: {
					[ experimentId ]: settingsChanges,
				},
			} );
		},
		[ saveSettings ]
	);

	// Loading state
	if ( ! data ) {
		return (
			<div className="ai-experiments-page">
				<Spinner />
			</div>
		);
	}

	// Credentials check
	if ( ! data.hasValidCredentials ) {
		return (
			<div className="ai-experiments-page">
				<Notice status="error" isDismissible={ false }>
					<p>
						{ __(
							'Before you can enable experiments, you need to set valid AI credentials.',
							'ai'
						) }
					</p>
					<Button
						href={ data.credentialsUrl }
						variant="secondary"
						style={ { marginTop: '1em' } }
					>
						{ __( 'Configure Credentials', 'ai' ) }
					</Button>
				</Notice>
			</div>
		);
	}

	const globalFields: DataFormField[] = [
		{
			id: 'globalEnabled',
			type: 'boolean',
			label: __( 'Enable Experiments', 'ai' ),
			description: __(
				'Control whether AI experiments are enabled for your site. When disabled, all experiments will be inactive regardless of their individual settings.',
				'ai'
			),
		},
	];

	const globalForm = {
		type: 'regular' as const,
		fields: [ 'globalEnabled' ],
	};

	return (
		<div className="ai-experiments-page">
			<header className="ai-experiments-page__header">
				<div className="ai-experiments-page__header-left">
					<span className="ai-experiments-page__icon">
						{ flaskIcon }
					</span>
					<Heading level={ 1 }>
						{ __( 'AI Experiments', 'ai' ) }
					</Heading>
				</div>
				<div className="ai-experiments-page__header-right">
					<Button
						variant="secondary"
						href="https://github.com/WordPress/ai/tree/develop/docs"
						target="_blank"
						rel="noopener noreferrer"
						icon={ external }
						iconPosition="right"
						iconSize={ 16 }
					>
						{ __( 'Docs', 'ai' ) }
					</Button>
					<Button
						variant="primary"
						href="https://github.com/WordPress/ai/blob/develop/CONTRIBUTING.md"
						target="_blank"
						rel="noopener noreferrer"
						icon={ external }
						iconPosition="right"
						iconSize={ 16 }
					>
						{ __( 'Contribute', 'ai' ) }
					</Button>
				</div>
			</header>

			<div className="ai-experiments-page__content">
				<VStack spacing={ 6 }>
					<VStack spacing={ 4 } className="ai-experiments__global">
						<VStack spacing={ 2 }>
							<Heading level={ 2 }>
								{ __( 'General Settings', 'ai' ) }
							</Heading>
							<Text>
								{ __(
									'Control whether AI experiments are enabled for your site. When disabled, all experiments will be inactive regardless of their individual settings.',
									'ai'
								) }
							</Text>
						</VStack>
						<DataForm
							data={ { globalEnabled: data.globalEnabled } }
							fields={ globalFields }
							form={ globalForm }
							onChange={ handleGlobalChange }
						/>
					</VStack>

					{ data.experiments.length > 0 && (
						<ExperimentsList
							experiments={ data.experiments }
							globalEnabled={ data.globalEnabled }
							onToggle={ handleExperimentToggle }
							onSettingsChange={ handleExperimentSettingsChange }
						/>
					) }
				</VStack>
			</div>

			<div className="ai-experiments-page__snackbar">
				<SnackbarList
					notices={ snackbarNotices }
					onRemove={ removeNotice }
				/>
			</div>
		</div>
	);
};
