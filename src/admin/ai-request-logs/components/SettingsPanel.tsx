/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';

interface DeleteOption {
	value: string;
	label: string;
	confirm: string;
}

// Default to the least destructive option.
const DEFAULT_DELETE_OPTION: DeleteOption = {
	value: '365',
	label: __( 'Older than 1 year', 'ai' ),
	confirm: __( 'Delete logs older than 1 year?', 'ai' ),
};

/**
 * Age thresholds offered for deleting logs, in days. 0 deletes all logs.
 */
const DELETE_OPTIONS: DeleteOption[] = [
	{
		value: '30',
		label: __( 'Older than 30 days', 'ai' ),
		confirm: __( 'Delete logs older than 30 days?', 'ai' ),
	},
	{
		value: '90',
		label: __( 'Older than 90 days', 'ai' ),
		confirm: __( 'Delete logs older than 90 days?', 'ai' ),
	},
	DEFAULT_DELETE_OPTION,
	{
		value: '0',
		label: __( 'All logs', 'ai' ),
		confirm: __( 'Delete all logs?', 'ai' ),
	},
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
	const [ showDeleteConfirm, setShowDeleteConfirm ] = useState( false );
	const [ deleteOption, setDeleteOption ] = useState(
		DEFAULT_DELETE_OPTION.value
	);
	const focusDeleteButtonRef = useRef< boolean >( false );
	const focusCancelButtonRef = useRef< boolean >( false );

	const selectedOption =
		DELETE_OPTIONS.find( ( option ) => option.value === deleteOption ) ??
		DEFAULT_DELETE_OPTION;

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

	const handleDelete = () => {
		if ( showDeleteConfirm ) {
			onDeleteLogs( parseInt( selectedOption.value, 10 ) );
			setShowDeleteConfirm( false );
			focusDeleteButtonRef.current = true;
		} else {
			focusCancelButtonRef.current = true;
			setShowDeleteConfirm( true );
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
							'Permanently delete logged requests older than the selected age, or all of them. This action cannot be undone.',
							'ai'
						) }
					</p>
					<SelectControl
						label={ __( 'Logs to delete', 'ai' ) }
						value={ deleteOption }
						options={ DELETE_OPTIONS.map(
							( { value, label } ) => ( {
								value,
								label,
							} )
						) }
						onChange={ ( value: string ) =>
							setDeleteOption( value )
						}
						disabled={ purging || showDeleteConfirm }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					{ showDeleteConfirm ? (
						<div className="ai-request-logs__purge-confirm">
							<span>{ selectedOption.confirm }</span>
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
									setShowDeleteConfirm( false );
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
			</CardBody>
		</Card>
	);
};

export default SettingsPanel;
