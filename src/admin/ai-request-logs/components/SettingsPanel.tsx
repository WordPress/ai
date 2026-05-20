/**
 * WordPress dependencies
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

interface SettingsPanelProps {
	onPurgeLogs: () => void;
	purging: boolean;
}

const SettingsPanel: React.FC< SettingsPanelProps > = ( {
	onPurgeLogs,
	purging,
} ) => {
	const [ showPurgeConfirm, setShowPurgeConfirm ] = useState( false );

	const handlePurge = () => {
		if ( showPurgeConfirm ) {
			onPurgeLogs();
			setShowPurgeConfirm( false );
		} else {
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
							>
								{ __( 'Yes, Purge All', 'ai' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => setShowPurgeConfirm( false ) }
								disabled={ purging }
							>
								{ __( 'Cancel', 'ai' ) }
							</Button>
						</div>
					) : (
						<Button
							variant="secondary"
							isDestructive
							onClick={ handlePurge }
							disabled={ purging }
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
