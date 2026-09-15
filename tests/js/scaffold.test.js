/**
 * File: tests/js/scaffold.test.js
 */

import { PLUGIN_SLUG } from '../../assets/src/editor';

describe( 'development scaffold', () => {
	it( 'exports canonical identifiers', () => {
		expect( PLUGIN_SLUG ).toBe( 'argentwolf-post-notifier' );
	} );
} );

// EOF: tests/js/scaffold.test.js
