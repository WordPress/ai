/**
 * Toggle section component for global experiments toggle.
 *
 * @package WordPress\AI
 */

import {
	Card,
	CardBody,
	CardDivider,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import type { SectionPayload } from '../types';

type ToggleSectionProps = {
	section: SectionPayload;
	enabled: boolean;
	isSaving: boolean;
	onChange: (value: boolean) => void;
};

const ToggleSection = ({
	section,
	enabled,
	isSaving,
	onChange,
}: ToggleSectionProps) => {
	return (
		<Card className="ai-experiments-settings-app__card">
			<CardBody>
				<div className="ai-experiments-settings-app__card-header">
					<div>
						<h2 className="ai-experiments-settings-app__card-title">
							{section.title || __('Experimental Features', 'ai')}
						</h2>
						{section.description ? (
							<p className="ai-experiments-settings-app__card-description">
								{section.description}
							</p>
						) : null}
					</div>
					<div className="ai-experiments-settings-app__card-action">
						{isSaving && <Spinner />}
					</div>
				</div>
			</CardBody>
			<CardDivider />
			<CardBody>
				<ToggleControl
					label={__('Enable Experimental Features', 'ai')}
					checked={enabled}
					help={
						section.description ||
						__(
							'Allow experimental AI features to run on this site.',
							'ai'
						)
					}
					onChange={onChange}
					disabled={isSaving}
					__nextHasNoMarginBottom
				/>
				<p className="ai-experiments-settings-app__helper">
					{__(
						'Toggling this switch enables or disables all experimental AI capabilities provided by this plugin.',
						'ai'
					)}
				</p>
			</CardBody>
		</Card>
	);
};

export default ToggleSection;
