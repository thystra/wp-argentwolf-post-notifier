/* global awpnTemplatePreview */
/**
 * Read-only in-memory preview for unsaved administrator template fields.
 */
( function () {
	'use strict';

	const button = document.getElementById( 'awpn_preview_unsaved' );
	const form = document.getElementById( 'awpn_template_settings_form' );
	const select = document.getElementById( 'awpn_preview_post' );
	const feedback = document.getElementById( 'awpn_preview_feedback' );
	const output = document.getElementById( 'awpn_live_preview' );

	if ( ! button || ! form || ! select || ! feedback || ! output ) {
		return;
	}

	button.addEventListener( 'click', async function () {
		if ( ! select.value ) {
			feedback.textContent = awpnTemplatePreview.strings.select;
			return;
		}

		button.disabled = true;
		feedback.textContent = awpnTemplatePreview.strings.loading;
		output.hidden = true;
		output.replaceChildren();

		const data = new FormData( form );
		data.set( 'action', awpnTemplatePreview.action );
		data.set( 'nonce', awpnTemplatePreview.nonce );
		data.set( 'post_id', select.value );

		try {
			const response = await fetch( awpnTemplatePreview.url, {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
			} );
			const payload = await response.json();
			if ( ! response.ok || ! payload.success ) {
				throw new Error(
					payload.data?.message || awpnTemplatePreview.strings.failed
				);
			}

			const message = payload.data;
			const subject = document.createElement( 'p' );
			subject.textContent = `${ awpnTemplatePreview.strings.subject } ${ message.subject }`;
			const source = document.createElement( 'p' );
			source.textContent = `${ awpnTemplatePreview.strings.source } ${ message.source }`;
			const heading = document.createElement( 'h3' );
			heading.textContent = awpnTemplatePreview.strings.html;
			const frame = document.createElement( 'iframe' );
			frame.title = awpnTemplatePreview.strings.frame;
			frame.setAttribute( 'sandbox', '' );
			frame.setAttribute( 'referrerpolicy', 'no-referrer' );
			frame.style.cssText =
				'width:100%;height:420px;border:1px solid #8c8f94;pointer-events:none';
			frame.srcdoc = message.html;
			const textHeading = document.createElement( 'h3' );
			textHeading.textContent = awpnTemplatePreview.strings.text;
			const textarea = document.createElement( 'textarea' );
			textarea.readOnly = true;
			textarea.rows = 14;
			textarea.className = 'large-text code';
			textarea.value = message.text;

			output.replaceChildren(
				subject,
				source,
				heading,
				frame,
				textHeading,
				textarea
			);
			output.hidden = false;
			feedback.textContent = awpnTemplatePreview.strings.complete;
		} catch ( error ) {
			feedback.textContent = error.message || 'Preview failed.';
		} finally {
			button.disabled = false;
		}
	} );
} )();
