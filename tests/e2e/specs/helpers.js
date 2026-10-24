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

// Console messages that WordPress itself produces in a fresh dev site, not the plugin. Listed in the report.
const KNOWN_NOISE = [
	/Download the React DevTools/i,
	/JQMIGRATE/i,
	/wp\.editPost\.\w+ is deprecated/i,
	/is deprecated since version/i,
	/Failed to load resource: the server responded with a status of 404/i, // favicon.ico
	/\[DOM\] Input elements should have autocomplete attributes/i,
];

/** Collects page errors and console errors, split into plugin-relevant and known WordPress noise. */
function collectErrors( page ) {
	const errors = { relevant: [], noise: [] };
	page.on( 'pageerror', ( error ) => errors.relevant.push( 'pageerror: ' + error.message ) );
	page.on( 'console', ( message ) => {
		if ( message.type() !== 'error' && message.type() !== 'warning' ) {
			return;
		}
		const text = message.type() + ': ' + message.text();
		if ( message.type() === 'warning' || KNOWN_NOISE.some( ( re ) => re.test( text ) ) ) {
			errors.noise.push( text );
		} else {
			errors.relevant.push( text );
		}
	} );
	return errors;
}

module.exports = { stub, refresh, login, collectErrors, SITE_KEY };
