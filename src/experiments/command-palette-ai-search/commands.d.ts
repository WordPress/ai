/**
 * Type declarations for the parts of `@wordpress/commands` used here, which
 * ships without type declarations.
 */

declare module '@wordpress/commands' {
	export type WPCommand = {
		name: string;
		label: string;
		searchLabel?: string;
		keywords?: unknown[];
		category?: string;
		context?: string;
		icon?: import('react').JSX.Element;
		callback: ( options: { close: () => void } ) => void;
	};

	export type WPCommandLoader = {
		name: string;
		context?: string;
		hook: ( props: { search: string } ) => {
			commands: WPCommand[];
			isLoading: boolean;
		};
	};

	export const store: import('@wordpress/data').StoreDescriptor<
		import('@wordpress/data').ReduxStoreConfig<
			unknown,
			{
				registerCommandLoader: ( config: WPCommandLoader ) => unknown;
			},
			{
				getCommands: (
					state: unknown,
					contextual?: boolean
				) => WPCommand[];
			}
		>
	>;
}
