/**
 * Feature section component for individual feature toggles.
 */

import { Card, CardBody, ToggleControl } from '@wordpress/components';

import type { SectionPayload } from '../types';

type FeatureSectionProps = {
	section: SectionPayload;
	masterEnabled: boolean;
	isSaving: boolean;
	onToggle: (featureId: string, enabled: boolean) => void;
};

const FeatureSection = ({
	section,
	masterEnabled,
	isSaving,
	onToggle,
}: FeatureSectionProps) => {
	const isDisabled = !masterEnabled || isSaving;
	const canToggle = section.featureId !== null;

	return (
		<Card key={section.id} className="ai-experiments-settings-app__card">
			<CardBody>
				<div className="ai-experiments-settings-app__card-header">
					<div>
						<h3 className="ai-experiments-settings-app__card-title">
							{section.title}
						</h3>
						{section.description ? (
							<p className="ai-experiments-settings-app__card-description">
								{section.description}
							</p>
						) : null}
					</div>
					{canToggle && (
						<div className="ai-experiments-settings-app__card-action">
							<ToggleControl
								checked={section.enabled}
								disabled={isDisabled}
								onChange={(value) =>
									onToggle(section.featureId!, value)
								}
								__nextHasNoMarginBottom
							/>
						</div>
					)}
				</div>
			</CardBody>
		</Card>
	);
};

export default FeatureSection;
