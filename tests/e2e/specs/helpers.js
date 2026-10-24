// Shared helpers for the browser tests. See bin/e2e.
const crypto = require( 'crypto' );

const STUB = 'http://127.0.0.1:8081';
const SITE_KEY = 'odv_wp_stubkey_for_dev_0123456789';

/** POSTs a control document to the stub Odavent API (see docker/stub/index.php). */
async function stub( control ) {
	const response = await fetch( STUB + '/__control', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( control ),
	} );
	if ( ! response.ok ) {
		throw new Error( 'stub control failed: ' + response.status );
	}
	return response.json();
}

let pingCount = 0;

/**
 * Sends a correctly signed refresh ping for an event, the way Odavent does: the site forgets its cached copy.
 * Each ping has a unique body (sent_at), so replay protection never rejects the next one.
 */
async function refresh( baseURL, eventId ) {
	pingCount++;
	const body = JSON.stringify( {
		event_id: eventId,
		event_slug: 'e2e',
		sent_at: new Date( Date.now() + pingCount ).toISOString(),
	} );
	const t = Math.floor( Date.now() / 1000 );
	const v1 = crypto.createHmac( 'sha256', SITE_KEY ).update( t + '.' + body ).digest( 'hex' );
	const response = await fetch( baseURL + '/wp-json/odavent/v1/refresh', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-Odavent-Signature': 't=' + t + ',v1=' + v1 },
		body,
	} );
	if ( response.status !== 200 ) {
		throw new Error( 'refresh ping answered ' + response.status + ': ' + ( await response.text() ) );
	}
}

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );
}

// Placeholder for the fixtures' images (https://odavent.com/storage/...) and Gravatar avatars: the browser never reaches production,
// so the tests work offline and the screenshots show a visible box where a photo or logo goes.
const PLACEHOLDER_SVG =
	'<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">' +
	'<rect width="400" height="300" fill="#d9dde3"/><path d="M0 300 140 150l80 90 60-60 120 120z" fill="#b4bcc8"/>' +
	'<circle cx="300" cy="80" r="36" fill="#b4bcc8"/></svg>';

const LOCAL = /^https?:\/\/(localhost:8089|127\.0\.0\.1(:\d+)?)(\/|$)/;

/**
 * Prepares a page: fixture images are answered locally, any other external request is recorded (and listed in the
 * test's annotations), and console output is collected (see collectErrors()).
 */
async function preparePage( page ) {
	const errors = collectErrors( page );
	// wp-admin's avatars come from Gravatar: answered locally too, so no test needs the network.
	await page.route( /^https:\/\/(odavent\.com\/storage|secure\.gravatar\.com\/avatar)\//, ( route ) =>
		route.fulfill( { status: 200, contentType: 'image/svg+xml', body: PLACEHOLDER_SVG } )
	);
	page.on( 'request', ( request ) => {
		const url = request.url();
		if ( ! LOCAL.test( url ) && ! /^(data|blob|about):/.test( url ) && ! /^https:\/\/(odavent\.com\/storage|secure\.gravatar\.com\/avatar)\//.test( url ) ) {
			errors.external.push( url );
		}
	} );
	return errors;
}

// Console output WordPress core itself prints on a fresh dev site and that the plugin cannot avoid. Exact texts
// only; anything else (any error, and any warning naming a wp.components control or the plugin's scripts) fails.
const KNOWN_CORE_NOISE = [
	// Chromium's notice for React development builds (SCRIPT_DEBUG is off, but some WP versions still print it).
	/^Download the React DevTools for a better development experience/,
	// jQuery Migrate's banner on wp-admin pages (core loads it; WordPress 6.1).
	/^JQMIGRATE: Migrate is installed, version \d/,
	// WordPress 6.9/7.x editor: core's own global-styles inline style copied into the iframed canvas.
	/^global-styles-css-custom-properties-inline-css was added to the iframe incorrectly\. Please use block\.json or enqueue_block_assets to add styles to the iframe\./,
];

const PLUGIN_RELATED = /wp\.components\.|odavent|editor\.js|countdown\.js|agenda-filter\.js/i;

/** Collects page errors and console output: `relevant` fails a test, `noise` is listed only. */
function collectErrors( page ) {
	const errors = { relevant: [], noise: [], external: [] };
	page.on( 'pageerror', ( error ) => errors.relevant.push( 'pageerror: ' + error.message ) );
	page.on( 'console', ( message ) => {
		const type = message.type();
		if ( type !== 'error' && type !== 'warning' ) {
			return;
		}
		const text = message.text();
		if ( KNOWN_CORE_NOISE.some( ( re ) => re.test( text ) ) && ! PLUGIN_RELATED.test( text ) ) {
			errors.noise.push( type + ': ' + text );
		} else {
			errors.relevant.push( type + ': ' + text ); // any other error or warning fails the test
		}
	} );
	return errors;
}

module.exports = { stub, refresh, login, preparePage, collectErrors, SITE_KEY };
