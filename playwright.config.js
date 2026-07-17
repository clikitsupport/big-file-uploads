/**
 * Playwright configuration for the Big File Uploads end-to-end suite.
 *
 * Targets the wp-env development site. Start it first with `npm run env:start`.
 */

const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',

	// A chunked 5MB upload over 4 requests is not instant, and CI is slower still.
	timeout: 120_000,
	expect: { timeout: 15_000 },

	// The uploads directory and the plugin's settings are global state; parallel tests would
	// clobber each other's media library.
	fullyParallel: false,
	workers: 1,

	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: [ [ 'list' ] ],

	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
