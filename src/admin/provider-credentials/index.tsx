/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { Popover } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import { createRoot } from 'react-dom/client';

/**
 * Internal dependencies
 */
import { getProviderIconComponent } from '../components/provider-icons';
import ProviderTooltipContent from '../components/ProviderTooltipContent';
import type { ProviderMetadata, ProviderMetadataMap } from '../types/providers';
import './style.scss';

declare global {
	interface Window {
		aiProviderCredentialsConfig?: {
			providers?: ProviderMetadataMap;
		};
	}
}

const ProviderBadge: React.FC< {
	providerId: string;
	config: ProviderMetadata;
} > = ( { providerId, config } ) => {
	const [ isOpen, setIsOpen ] = useState( false );
	const anchorRef = useRef< HTMLButtonElement | null >( null );
	const closeTimeout = useRef< ReturnType< typeof setTimeout > | null >( null );

	const clearCloseTimeout = () => {
		if ( closeTimeout.current ) {
			clearTimeout( closeTimeout.current );
			closeTimeout.current = null;
		}
	};

	const open = () => {
		clearCloseTimeout();
		setIsOpen( true );
	};

	const scheduleClose = () => {
		clearCloseTimeout();
		closeTimeout.current = setTimeout( () => setIsOpen( false ), 120 );
	};

	const close = () => {
		clearCloseTimeout();
		setIsOpen( false );
	};

	const IconComponent = getProviderIconComponent(
		config.icon || providerId,
		providerId
	);
	const icon = (
		<span
			className="ai-provider-credentials__icon"
			style={
				config.color
					? { ['--ai-provider-icon-color' as '--ai-provider-icon-color']: config.color }
					: undefined
			}
		>
			<IconComponent />
		</span>
	);

	return (
		<>
			<button
				type="button"
				className="ai-provider-credentials__icon-button"
				ref={ anchorRef }
				onClick={ open }
				onMouseEnter={ open }
				onMouseLeave={ scheduleClose }
				onFocus={ open }
				onBlur={ scheduleClose }
				aria-expanded={ isOpen }
				aria-haspopup="dialog"
			>
				{ icon }
			</button>
			{ isOpen && anchorRef.current && (
				<Popover
					anchor={ anchorRef.current }
					placement="right-start"
					offset={ 12 }
					onClose={ close }
					className="ai-provider-credentials__popover"
					focusOnMount={ false }
				>
					<div
						onMouseEnter={ open }
						onMouseLeave={ scheduleClose }
					>
						<ProviderTooltipContent metadata={ config } />
					</div>
				</Popover>
			) }
		</>
	);
};

const enhanceProviderRows = ( providers: ProviderMetadataMap ) => {
	const inputs = document.querySelectorAll<HTMLInputElement>(
		'input[id^="wp-ai-client-provider-api-key-"]'
	);

	inputs.forEach( ( input ) => {
		const providerId = input.id.replace(
			'wp-ai-client-provider-api-key-',
			''
		);
		const config = providers?.[ providerId ];

		if ( ! config ) {
			return;
		}

		const row = input.closest( 'tr' );
		const header = row?.querySelector< HTMLElement >( 'th' );
		if ( ! header ) {
			return;
		}

		const label =
			header.querySelector< HTMLElement >( 'label' ) ||
			header.querySelector< HTMLElement >( '.ai-provider-credentials__name' ) ||
			header.firstElementChild ||
			header;

		label.classList.add( 'ai-provider-credentials__name' );

		const wrapper = document.createElement( 'div' );
		wrapper.className = 'ai-provider-credentials__label-wrapper';

		const iconHost = document.createElement( 'span' );
		iconHost.className = 'ai-provider-credentials__icon-host';
		wrapper.appendChild( iconHost );
		wrapper.appendChild( label );

		header.innerHTML = '';
		header.appendChild( wrapper );

		const description = row?.querySelector< HTMLElement >( 'p.description' );
		if ( description ) {
			if ( config.keepDescription && config.tooltip ) {
				description.textContent = config.tooltip;
			} else if ( ! config.keepDescription ) {
				description.remove();
			}
		}

		const root = createRoot( iconHost );
		root.render(
			<ProviderBadge providerId={ providerId } config={ config } />
		);
	} );
};

domReady( () => {
	const providers =
		window.aiProviderCredentialsConfig?.providers ?? undefined;
	if ( providers ) {
		enhanceProviderRows( providers );
	}
} );
