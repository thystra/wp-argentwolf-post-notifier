/**
 * File: webpack.config.cjs
 */

const path = require( 'node:path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		editor: path.resolve( process.cwd(), 'assets/src/editor.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'assets/build' ),
		filename: '[name].js',
		clean: true,
	},
};

// EOF: webpack.config.cjs
