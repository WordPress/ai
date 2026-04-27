/**
 * WordPress dependencies
 */
import { Guide } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { GuideGraphic } from './guide-graphic';

interface AIOnboardingGuideProps {
	onFinish: () => void;
	guideDismissed: boolean;
	currentUser: { id: number } | null;
}

export function AIOnboardingGuide( {
	onFinish,
	guideDismissed,
	currentUser,
}: AIOnboardingGuideProps ) {
	const handleFinish = () => {
		onFinish();
		if ( ! guideDismissed && currentUser ) {
			apiFetch( {
				path: `/wp/v2/users/${ currentUser.id }`,
				method: 'POST',
				data: {
					meta: {
						wpai_settings_guide_dismissed: true,
					},
				},
			} ).catch( () => {} );
		}
	};

	return (
		<Guide
			className="ai-settings-guide"
			contentLabel={ __( 'Welcome to WordPress AI', 'ai' ) }
			onFinish={ handleFinish }
			pages={ [
				{
					image: <GuideGraphic />,
					content: (
						<Stack direction="column" gap="sm">
							<h1 className="components-guide__title">
								{ __( 'Welcome to WordPress AI', 'ai' ) }
							</h1>
							<p className="components-guide__description">
								{ __(
									'Get started by supercharging your site with AI.',
									'ai'
								) }
							</p>
						</Stack>
					),
				},
				{
					image: <GuideGraphic />,
					content: (
						<Stack direction="column" gap="sm">
							<h1 className="components-guide__title">
								{ __( 'Connect an AI provider', 'ai' ) }
							</h1>
							<p className="components-guide__description">
								{ __(
									'Ensure you have an AI Connector set up so the plugin can communicate with your chosen AI models.',
									'ai'
								) }
							</p>
						</Stack>
					),
				},
				{
					image: <GuideGraphic />,
					content: (
						<Stack direction="column" gap="sm">
							<h1 className="components-guide__title">
								{ __( 'Enable experiments', 'ai' ) }
							</h1>
							<p className="components-guide__description">
								{ __(
									'Turn on experiments like AI-assisted title and image generation directly from this settings page.',
									'ai'
								) }
							</p>
						</Stack>
					),
				},
			] }
		/>
	);
}
