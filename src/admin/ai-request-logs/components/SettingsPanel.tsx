/**
 * WordPress dependencies
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';

interface SettingsPanelProps {
	hasLogs: boolean;
	onPurgeLogs: () => void;
	purging: boolean;
}

const SettingsPanel: React.FC< SettingsPanelProps > = ( {
	hasLogs,
	onPurgeLogs,
	purging,
} ) => {
	const [ showPurgeConfirm, setShowPurgeConfirm ] = useState( false );
	const focusPurgeButtonRef = useRef< boolean >( false );
	const focusCancelButtonRef = useRef< boolean >( false );

	function focusPurgeButtonOnMount( node: HTMLButtonElement | null ) {
		if ( focusPurgeButtonRef.current && node ) {
			node.focus();
			focusPurgeButtonRef.current = false;
		}
	}

	function focusCancelButtonOnMount( node: HTMLButtonElement | null ) {
		if ( focusCancelButtonRef.current && node ) {
			node.focus();
			focusCancelButtonRef.current = false;
		}
	}

	const handlePurge = () => {
		if ( showPurgeConfirm ) {
			onPurgeLogs();
			setShowPurgeConfirm( false );
			focusPurgeButtonRef.current = true;
		} else {
			focusCancelButtonRef.current = true;
			setShowPurgeConfirm( true );
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
							'Permanently delete all logged requests. This action cannot be undone.',
							'ai'
						) }
					</p>
					{ showPurgeConfirm ? (
						<div className="ai-request-logs__purge-confirm">
							<span>{ __( 'Are you sure?', 'ai' ) }</span>
							<Button
								variant="primary"
								isDestructive
								onClick={ handlePurge }
								disabled={ purging }
								isBusy={ purging }
								accessibleWhenDisabled
								__next40pxDefaultSize
							>
								{ __( 'Yes, Purge All', 'ai' ) }
							</Button>
							<Button
								variant="secondary"
								ref={ focusCancelButtonOnMount }
								onClick={ () => {
									setShowPurgeConfirm( false );
									focusPurgeButtonRef.current = true;
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
							ref={ focusPurgeButtonOnMount }
							onClick={ handlePurge }
							disabled={ purging || ! hasLogs }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Purge All Logs', 'ai' ) }
						</Button>
					) }
				</div>
			</CardBody>
		</Card>
	);
};

export default SettingsPanel;
