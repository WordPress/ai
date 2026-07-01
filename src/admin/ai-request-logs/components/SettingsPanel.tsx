/**
 * WordPress dependencies
 */
import { Button, Card, CardBody, CardHeader, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';

const DELETE_OPTIONS = [
	{ value: '30', label: __( 'Older than 30 days', 'ai' ) },
	{ value: '90', label: __( 'Older than 90 days', 'ai' ) },
	{ value: '365', label: __( 'Older than 1 year', 'ai' ) },
	{ value: '0', label: __( 'All logs', 'ai' ) },
];

interface SettingsPanelProps {
	hasLogs: boolean;
	onDeleteLogs: ( days: number ) => void;
	purging: boolean;
}

const SettingsPanel: React.FC< SettingsPanelProps > = ( {
	hasLogs,
	onDeleteLogs,
	purging,
} ) => {
	const [ showConfirm, setShowConfirm ] = useState( false );
	const [ selectedDays, setSelectedDays ] = useState( '30' );
	const focusDeleteButtonRef = useRef< boolean >( false );
	const focusCancelButtonRef = useRef< boolean >( false );

	function focusDeleteButtonOnMount( node: HTMLButtonElement | null ) {
		if ( focusDeleteButtonRef.current && node ) {
			node.focus();
			focusDeleteButtonRef.current = false;
		}
	}

	function focusCancelButtonOnMount( node: HTMLButtonElement | null ) {
		if ( focusCancelButtonRef.current && node ) {
			node.focus();
			focusCancelButtonRef.current = false;
		}
	}

	const getConfirmMessage = () => {
		const days = parseInt( selectedDays, 10 );
		if ( days === 0 ) {
			return __( 'Permanently delete all logs? This action cannot be undone.', 'ai' );
		}
		const option = DELETE_OPTIONS.find( ( o ) => o.value === selectedDays );
		return sprintf(
			/* translators: %s: human-readable age threshold, e.g. "older than 30 days". */
			__( 'Permanently delete logs %s? This action cannot be undone.', 'ai' ),
			option?.label.toLowerCase() ?? ''
		);
	};

	const handleDelete = () => {
		if ( showConfirm ) {
			onDeleteLogs( parseInt( selectedDays, 10 ) );
			setShowConfirm( false );
			focusDeleteButtonRef.current = true;
		} else {
			focusCancelButtonRef.current = true;
			setShowConfirm( true );
		}
	};

	return (
		<Card className="ai-request-logs__card ai-request-logs__settings">
			<CardHeader>
				<h2>{ __( 'Manage Logs', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
				<div className="ai-request-logs__settings-danger">
					<h3>{ __( 'Danger Zone', 'ai' ) }</h3>
					<p className="description">
						{ __(
							'Delete log entries by age. This action cannot be undone.',
							'ai'
						) }
					</p>
					<SelectControl
						label={ __( 'Delete logs', 'ai' ) }
						value={ selectedDays }
						options={ DELETE_OPTIONS }
						onChange={ ( value ) => {
							setSelectedDays( value );
							setShowConfirm( false );
						} }
						disabled={ purging || ! hasLogs }
						__nextHasNoMarginBottom
					/>
					<div style={ { marginTop: '8px' } }>
					{ showConfirm ? (
						<div className="ai-request-logs__purge-confirm">
							<span>{ getConfirmMessage() }</span>
							<Button
								variant="primary"
								isDestructive
								onClick={ handleDelete }
								disabled={ purging }
								isBusy={ purging }
								accessibleWhenDisabled
								__next40pxDefaultSize
							>
								{ __( 'Yes, Delete', 'ai' ) }
							</Button>
							<Button
								variant="secondary"
								ref={ focusCancelButtonOnMount }
								onClick={ () => {
									setShowConfirm( false );
									focusDeleteButtonRef.current = true;
								} }
								disabled={ purging }
								accessibleWhenDisabled
								__next40pxDefaultSize
							>
								{ __( 'Cancel', 'ai' ) }
							</Button>
						</div>
					) : (
						<Button
							variant="secondary"
							isDestructive
							ref={ focusDeleteButtonOnMount }
							onClick={ handleDelete }
							disabled={ purging || ! hasLogs }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Delete', 'ai' ) }
						</Button>
					) }
					</div>
				</div>
			</CardBody>
		</Card>
	);
};

export default SettingsPanel;
