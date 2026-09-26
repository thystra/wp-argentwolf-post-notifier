/**
 * File: assets/runtime/subscribe-editor.js
 *
 * Human-readable editor registration for the dynamic subscribe block.
 */

/**
 * Register the dynamic subscribe block in the editor.
 *
 * @param {Object} blocks      WordPress blocks package.
 * @param {Object} blockEditor WordPress block-editor package.
 * @param {Object} components  WordPress components package.
 * @param {Object} element     WordPress element package.
 * @param {Object} i18n        WordPress internationalization package.
 */
( function ( blocks, blockEditor, components, element, i18n ) {
	const { registerBlockType } = blocks;
	const { InspectorControls, useBlockProps } = blockEditor;
	const { PanelBody, TextControl, TextareaControl } = components;
	const { createElement, Fragment } = element;
	const { __ } = i18n;

	const defaults = {
		heading: 'Get new post notifications by email',
		description:
			'Enter your email address and confirm the message we send you.',
		consentText:
			'I agree to receive email notifications when new posts are published.',
		buttonLabel: 'Subscribe',
	};

	registerBlockType( 'argentwolf-post-notifier/subscribe', {
		apiVersion: 3,
		title: __( 'Post notification signup', 'argentwolf-post-notifier' ),
		description: __(
			'Collect double-opt-in email subscriptions for new post notifications.',
			'argentwolf-post-notifier'
		),
		icon: 'email-alt',
		category: 'widgets',
		attributes: {
			heading: { type: 'string', default: defaults.heading },
			description: { type: 'string', default: defaults.description },
			consentText: { type: 'string', default: defaults.consentText },
			buttonLabel: { type: 'string', default: defaults.buttonLabel },
		},
		edit: function Edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps( {
				className: 'wp-block-argentwolf-post-notifier-subscribe',
			} );

			const controls = createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{
						title: __( 'Signup text', 'argentwolf-post-notifier' ),
						initialOpen: true,
					},
					createElement( TextControl, {
						label: __( 'Heading', 'argentwolf-post-notifier' ),
						value: attributes.heading,
						onChange: ( heading ) => setAttributes( { heading } ),
					} ),
					createElement( TextareaControl, {
						label: __( 'Description', 'argentwolf-post-notifier' ),
						value: attributes.description,
						onChange: ( description ) =>
							setAttributes( { description } ),
					} ),
					createElement( TextareaControl, {
						label: __( 'Consent text', 'argentwolf-post-notifier' ),
						value: attributes.consentText,
						onChange: ( consentText ) =>
							setAttributes( { consentText } ),
					} ),
					createElement( TextControl, {
						label: __( 'Button label', 'argentwolf-post-notifier' ),
						value: attributes.buttonLabel,
						onChange: ( buttonLabel ) =>
							setAttributes( { buttonLabel } ),
					} )
				)
			);

			const preview = createElement(
				'div',
				blockProps,
				createElement( 'h3', null, attributes.heading ),
				createElement( 'p', null, attributes.description ),
				createElement(
					'p',
					null,
					createElement(
						'strong',
						null,
						__( 'Email address', 'argentwolf-post-notifier' )
					),
					createElement( 'br' ),
					createElement( 'input', { type: 'email', disabled: true } )
				),
				createElement(
					'p',
					null,
					createElement( 'input', {
						type: 'checkbox',
						disabled: true,
					} ),
					' ',
					attributes.consentText
				),
				createElement(
					'button',
					{
						type: 'button',
						className: 'wp-element-button',
						disabled: true,
					},
					attributes.buttonLabel
				)
			);

			return createElement( Fragment, null, controls, preview );
		},
		save: () => null,
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n
);

// EOF: assets/runtime/subscribe-editor.js
