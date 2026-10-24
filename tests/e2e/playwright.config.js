// Run by bin/e2e inside mcr.microsoft.com/playwright (same version as package.json), sharing the network
// namespace of the WordPress container: localhost:8089 is the site and 127.0.0.1:8081 the stub Odavent API.
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './specs',
	outputDir: './artifacts/test-results',
	timeout: 90000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ], [ 'json', { outputFile: 'artifacts/results.json' } ] ],
	use: {
		baseURL: process.env.BASE_URL || 'http://localhost:8089',
		colorScheme: 'light',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
