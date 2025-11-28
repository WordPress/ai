import { Button, Card, CardBody, CardHeader, RangeControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import React, { useState } from 'react';

interface SettingsPanelProps {
	enabled: boolean;
	retentionDays: number;
	onToggleEnabled: ( enabled: boolean ) => void;
	onRetentionChange: ( days: number ) => void;
	onPurgeLogs: () => void;
	saving: boolean;
	purging: boolean;
}

const SettingsPanel: React.FC< SettingsPanelProps > = ( {
	enabled,
	retentionDays,
	onToggleEnabled,
	onRetentionChange,
	onPurgeLogs,
	saving,
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
				<h2>{ __( 'Settings', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
			<ToggleControl
				label={ __( 'Enable Logging', 'ai' ) }
					help={ __( 'When enabled, AI client requests will be logged for observability.', 'ai' ) }
					checked={ enabled }
					onChange={ onToggleEnabled }
				disabled={ saving }
				__nextHasNoMarginBottom
			/>

			<RangeControl
				label={ __( 'Log Retention', 'ai' ) }
					help={ sprintf(
						__( 'Logs older than %d days will be automatically deleted.', 'ai' ),
						retentionDays
					) }
					value={ retentionDays }
					onChange={ ( value ) => onRetentionChange( value ?? 30 ) }
					min={ 1 }
					max={ 365 }
				disabled={ saving }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

				<div className="ai-request-logs__settings-danger">
					<h3>{ __( 'Danger Zone', 'ai' ) }</h3>
					<p className="description">
						{ __( 'Permanently delete all logged requests. This action cannot be undone.', 'ai' ) }
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
