/**
 * AI Experiments admin application.
 *
 * @package WordPress\AI
 */

import domReady from '@wordpress/dom-ready';
import apiFetch from '@wordpress/api-fetch';
import {
	createElement,
	createRoot,
	render,
	useCallback,
	useMemo,
	useState,
	Fragment,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CardDivider,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import type { SettingsPayload, SectionPayload } from './types';
import './style.scss';

/**
 * Section ID for the global experiments toggle.
 * Must match Settings_Service::TOGGLE_SECTION_ID in PHP.
 */
const TOGGLE_SECTION_ID = 'ai-experiments-toggle';

type NoticeState = {
	status: 'success' | 'error';
	message: string;
};

type AppProps = {
	settings: SettingsPayload;
};

const ToggleSection = ( {
	section,
	enabled,
	isSaving,
	onChange,
}: {
	section: SectionPayload;
	enabled: boolean;
	isSaving: boolean;
	onChange: ( value: boolean ) => void;
} ) => {
	return (
		<Card className="ai-experiments-settings-app__card">
			<CardBody>
				<div className="ai-experiments-settings-app__card-header">
					<div>
						<h2 className="ai-experiments-settings-app__card-title">
							{ section.title || __(
								'Experimental Features',
								'ai'
							) }
						</h2>
						{ section.description ? (
							<p className="ai-experiments-settings-app__card-description">
								{ section.description }
							</p>
						) : null }
					</div>
					<div className="ai-experiments-settings-app__card-action">
						{ isSaving && <Spinner /> }
					</div>
				</div>
			</CardBody>
			<CardDivider />
			<CardBody>
				<ToggleControl
					label={ __(
						'Enable Experimental Features',
						'ai'
					) }
					checked={ enabled }
					help={
						section.description ||
						__(
							'Allow experimental AI features to run on this site.',
							'ai'
						)
					}
					onChange={ onChange }
					disabled={ isSaving }
					__nextHasNoMarginBottom
				/>
				<p className="ai-experiments-settings-app__helper">
					{ __(
						'Toggling this switch enables or disables all experimental AI capabilities provided by this plugin.',
						'ai'
					) }
				</p>
			</CardBody>
		</Card>
	);
};

const FeatureSection = ( {
	section,
	masterEnabled,
	isSaving,
	onToggle,
}: {
	section: SectionPayload;
	masterEnabled: boolean;
	isSaving: boolean;
	onToggle: ( featureId: string, enabled: boolean ) => void;
} ) => {
	const isDisabled = ! masterEnabled || isSaving;
	const canToggle = section.featureId !== null;

	return (
		<Card key={ section.id } className="ai-experiments-settings-app__card">
			<CardBody>
				<div className="ai-experiments-settings-app__card-header">
					<div>
						<h3 className="ai-experiments-settings-app__card-title">
							{ section.title }
						</h3>
						{ section.description ? (
							<p className="ai-experiments-settings-app__card-description">
								{ section.description }
							</p>
						) : null }
					</div>
					{ canToggle && (
						<div className="ai-experiments-settings-app__card-action">
							<ToggleControl
								checked={ section.enabled }
								disabled={ isDisabled }
								onChange={ ( value ) =>
									onToggle( section.featureId!, value )
								}
								__nextHasNoMarginBottom
							/>
						</div>
					) }
				</div>
			</CardBody>
		</Card>
	);
};

const App = ( { settings }: AppProps ) => {
	const [ enabled, setEnabled ] = useState( settings.toggle.enabled );
	const [ featureToggles, setFeatureToggles ] = useState(
		settings.featureToggles.toggles
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );

	const toggleSection = useMemo(
		() =>
			settings.sections.find(
				( section ) => section.id === TOGGLE_SECTION_ID
			) ?? settings.sections[ 0 ],
		[ settings.sections ]
	);

	const otherSections = useMemo(
		() =>
			settings.sections
				.filter( ( section ) => section.id !== toggleSection?.id )
				.map( ( section ) => {
					// Use local state if available, otherwise fall back to initial value
					const enabled =
						section.featureId && section.featureId in featureToggles
							? featureToggles[ section.featureId ]
							: section.enabled;

					return {
						...section,
						enabled,
					};
				} ),
		[ settings.sections, toggleSection, featureToggles ]
	);

	const handleToggleChange = useCallback(
		( value: boolean ) => {
			if ( value === enabled ) {
				return;
			}

			const previous = enabled;
			setEnabled( value );
			setIsSaving( true );
			setNotice( null );

			apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					[ settings.toggle.restField ]: value,
				},
			} )
				.then( () => {
					setNotice( {
						status: 'success',
						message: __(
							'Experimental features setting updated.',
							'ai'
						),
					} );
				} )
				.catch( () => {
					setEnabled( previous );
					setNotice( {
						status: 'error',
						message: __(
							'Saving failed. Please try again.',
							'ai'
						),
					} );
				} )
				.finally( () => {
					setIsSaving( false );
				} );
		},
		[ enabled, settings.toggle.restField ]
	);

	const handleFeatureToggle = useCallback(
		( featureId: string, value: boolean ) => {
			const previous = featureToggles[ featureId ];
			const updated = { ...featureToggles, [ featureId ]: value };

			setFeatureToggles( updated );
			setIsSaving( true );
			setNotice( null );

			apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					[ settings.featureToggles.restField ]: updated,
				},
			} )
				.then( () => {
					setNotice( {
						status: 'success',
						message: __(
							'Feature setting updated.',
							'ai'
						),
					} );
				} )
				.catch( () => {
					setFeatureToggles( {
						...featureToggles,
						[ featureId ]: previous !== undefined ? previous : true,
					} );
					setNotice( {
						status: 'error',
						message: __(
							'Saving failed. Please try again.',
							'ai'
						),
					} );
				} )
				.finally( () => {
					setIsSaving( false );
				} );
		},
		[ featureToggles, settings.featureToggles.restField ]
	);

	if ( ! toggleSection ) {
		return (
			<div className="ai-experiments-settings-app">
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No settings sections are currently registered.',
						'ai'
					) }
				</Notice>
			</div>
		);
	}

	return (
		<div className="ai-experiments-settings-app">
			{ notice ? (
				<Notice
					status={ notice.status }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) : null }
			<ToggleSection
				section={ toggleSection }
				enabled={ enabled }
				isSaving={ isSaving }
				onChange={ handleToggleChange }
			/>
			{ otherSections.length > 0 ? (
				<Fragment>
					<div className="ai-experiments-settings-app__divider" />
					<div className="ai-experiments-settings-app__sections">
						{ otherSections.map( ( section ) => (
							<FeatureSection
								key={ section.id }
								section={ section }
								masterEnabled={ enabled }
								isSaving={ isSaving }
								onToggle={ handleFeatureToggle }
							/>
						) ) }
					</div>
				</Fragment>
			) : (
				<p className="ai-experiments-settings-app__empty">
					{ __(
						'Additional experimental features will surface their configuration here.',
						'ai'
					) }
				</p>
			) }
		</div>
	);
};

const mountApp = ( container: HTMLElement, settings: SettingsPayload ) => {
	if ( typeof createRoot === 'function' ) {
		const root = createRoot( container );
		root.render( <App settings={ settings } /> );
		return;
	}

	render( <App settings={ settings } />, container );
};

domReady( () => {
	const container = document.getElementById( 'ai-experiments-settings-root' );
	if ( ! container ) {
		return;
	}

	const settings =
		window.wpAiExperimentsSettings ??
		( ( container.getAttribute( 'data-settings' )
			? JSON.parse(
					container.getAttribute( 'data-settings' ) ?? '{}'
			  )
			: null ) as SettingsPayload | null );

	if ( ! settings ) {
		return;
	}

	container.removeAttribute( 'data-settings' );

	const wrapper = container.closest( '.ai-experiments-settings' );
	if ( wrapper ) {
		wrapper.classList.add( 'is-app-ready' );
	}

	mountApp( container, settings );
} );
