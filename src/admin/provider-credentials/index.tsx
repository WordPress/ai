/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { Popover } from '@wordpress/components';
import { useRef, useState, useEffect } from '@wordpress/element';
import * as React from 'react';

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
			cloudflareAccountId?: string;
		};
	}
}

const ProviderBadge: React.FC< {
	providerId: string;
	config: ProviderMetadata;
	labelElement?: HTMLElement | null;
} > = ( { providerId, config, labelElement } ) => {
	const [ isOpen, setIsOpen ] = useState( false );
	const triggerRef = useRef< HTMLDivElement | null >( null );
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

	// Attach event listeners to the label element so it also triggers the popover
	React.useEffect( () => {
		if ( ! labelElement ) return;

		const handleMouseEnter = () => open();
		const handleMouseLeave = () => scheduleClose();
		const handleClick = () => open();

		labelElement.addEventListener( 'mouseenter', handleMouseEnter );
		labelElement.addEventListener( 'mouseleave', handleMouseLeave );
		labelElement.addEventListener( 'click', handleClick );
		labelElement.style.cursor = 'pointer';

		return () => {
			labelElement.removeEventListener( 'mouseenter', handleMouseEnter );
			labelElement.removeEventListener( 'mouseleave', handleMouseLeave );
			labelElement.removeEventListener( 'click', handleClick );
		};
	}, [ labelElement ] );

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
		<div
			ref={ triggerRef }
			className="ai-provider-credentials__trigger"
			onMouseEnter={ open }
			onMouseLeave={ scheduleClose }
			aria-expanded={ isOpen }
		>
			<button
				type="button"
				className="ai-provider-credentials__icon-button"
				onClick={ open }
				onFocus={ open }
				onBlur={ scheduleClose }
				aria-haspopup="dialog"
			>
				{ icon }
			</button>
			{ isOpen && triggerRef.current && (
				<Popover
					anchor={ triggerRef.current }
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
		</div>
	);
};

const injectCloudflareAccountField = (
	row: HTMLTableRowElement | null,
	currentValue: string
) => {
	if ( ! row ) {
		return;
	}

	const targetCell = row.querySelector< HTMLTableCellElement >( 'td' );
	if ( ! targetCell ) {
		return;
	}

	if ( targetCell.querySelector( '.ai-provider-credentials__cloudflare-account' ) ) {
		return;
	}

	const wrapper = document.createElement( 'div' );
	wrapper.className = 'ai-provider-credentials__cloudflare-account';

	const label = document.createElement( 'label' );
	label.htmlFor = 'ai-cloudflare-account-id';
	label.textContent = 'Account ID';

	const input = document.createElement( 'input' );
	input.type = 'text';
	input.id = 'ai-cloudflare-account-id';
	input.name = 'ai_cloudflare_account_id';
	input.className = 'regular-text';
	input.value = currentValue ?? '';
	input.placeholder = 'Enter your Cloudflare account ID';

	const helpText = document.createElement( 'p' );
	helpText.className = 'description';
	helpText.textContent =
		'Find this under Workers AI → Overview in the Cloudflare dashboard.';

	wrapper.appendChild( label );
	wrapper.appendChild( input );
	wrapper.appendChild( helpText );

	targetCell.appendChild( wrapper );
};

const enhanceProviderRows = (
	providers: ProviderMetadataMap,
	cloudflareAccountId: string
) => {
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
			<ProviderBadge providerId={ providerId } config={ config } labelElement={ label as HTMLElement } />
		);

		if ( providerId === 'cloudflare' ) {
			injectCloudflareAccountField( row, cloudflareAccountId );
		}
	} );
};

domReady( () => {
	const providers =
		window.aiProviderCredentialsConfig?.providers ?? undefined;
	const cloudflareAccountId =
		window.aiProviderCredentialsConfig?.cloudflareAccountId ?? '';
	if ( providers ) {
		enhanceProviderRows( providers, cloudflareAccountId );
	}
} );
