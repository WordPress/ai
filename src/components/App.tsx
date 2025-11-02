/**
 * Main application component.
 *
 * @package WordPress\AI
 */

import { useState, useMemo, useCallback, Fragment } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import ToggleSection from './ToggleSection';
import FeatureSection from './FeatureSection';
import type { SettingsPayload } from '../types';

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

const App = ({ settings }: AppProps) => {
	const [enabled, setEnabled] = useState(settings.toggle.enabled);
	const [featureToggles, setFeatureToggles] = useState(
		settings.featureToggles.toggles
	);
	const [isSaving, setIsSaving] = useState(false);
	const [notice, setNotice] = useState<NoticeState | null>(null);

	const toggleSection = useMemo(
		() =>
			settings.sections.find(
				(section) => section.id === TOGGLE_SECTION_ID
			) ?? settings.sections[0],
		[settings.sections]
	);

	const otherSections = useMemo(
		() =>
			settings.sections
				.filter((section) => section.id !== toggleSection?.id)
				.map((section) => {
					const enabled =
						section.featureId && section.featureId in featureToggles
							? featureToggles[section.featureId]
							: section.enabled;

					return {
						...section,
						enabled,
					};
				}),
		[settings.sections, toggleSection, featureToggles]
	);

	const handleToggleChange = useCallback(
		(value: boolean) => {
			if (value === enabled) {
				return;
			}

			const previous = enabled;
			setEnabled(value);
			setIsSaving(true);
			setNotice(null);

			apiFetch({
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					[settings.toggle.restField]: value,
				},
			})
				.then(() => {
					setNotice({
						status: 'success',
						message: __(
							'Experimental features setting updated.',
							'ai'
						),
					});
				})
				.catch(() => {
					setEnabled(previous);
					setNotice({
						status: 'error',
						message: __('Saving failed. Please try again.', 'ai'),
					});
				})
				.finally(() => {
					setIsSaving(false);
				});
		},
		[enabled, settings.toggle.restField]
	);

	const handleFeatureToggle = useCallback(
		(featureId: string, value: boolean) => {
			const previous = featureToggles[featureId];
			const updated = { ...featureToggles, [featureId]: value };

			setFeatureToggles(updated);
			setIsSaving(true);
			setNotice(null);

			apiFetch({
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					[settings.featureToggles.restField]: updated,
				},
			})
				.then(() => {
					setNotice({
						status: 'success',
						message: __('Feature setting updated.', 'ai'),
					});
				})
				.catch(() => {
					setFeatureToggles({
						...featureToggles,
						[featureId]: previous !== undefined ? previous : true,
					});
					setNotice({
						status: 'error',
						message: __('Saving failed. Please try again.', 'ai'),
					});
				})
				.finally(() => {
					setIsSaving(false);
				});
		},
		[featureToggles, settings.featureToggles.restField]
	);

	if (!toggleSection) {
		return (
			<div className="ai-experiments-settings-app">
				<Notice status="warning" isDismissible={false}>
					{__('No settings sections are currently registered.', 'ai')}
				</Notice>
			</div>
		);
	}

	return (
		<div className="ai-experiments-settings-app">
			{notice ? (
				<Notice
					status={notice.status}
					isDismissible
					onRemove={() => setNotice(null)}
				>
					{notice.message}
				</Notice>
			) : null}
			<ToggleSection
				section={toggleSection}
				enabled={enabled}
				isSaving={isSaving}
				onChange={handleToggleChange}
			/>
			{otherSections.length > 0 ? (
				<Fragment>
					<div className="ai-experiments-settings-app__divider" />
					<div className="ai-experiments-settings-app__sections">
						{otherSections.map((section) => (
							<FeatureSection
								key={section.id}
								section={section}
								masterEnabled={enabled}
								isSaving={isSaving}
								onToggle={handleFeatureToggle}
							/>
						))}
					</div>
				</Fragment>
			) : (
				<p className="ai-experiments-settings-app__empty">
					{__(
						'Additional experimental features will surface their configuration here.',
						'ai'
					)}
				</p>
			)}
		</div>
	);
};

export default App;
