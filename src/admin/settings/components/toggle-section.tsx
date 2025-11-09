/**
 * Toggle section component for global experiments toggle.
 */

import {
	Card,
	CardBody,
	CardDivider,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import type { SectionPayload } from '../../../types';

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
						<ToggleControl
							label=""
							aria-label={__('Enable Experimental Features', 'ai')}
							checked={enabled}
							onChange={onChange}
							disabled={isSaving}
							__nextHasNoMarginBottom
						/>
						{isSaving && <Spinner />}
					</div>
				</div>
			</CardBody>
		</Card>
	);
};

export default ToggleSection;
