// Front end of /all-blocks-1/ (all ten blocks for event 1): agenda filter, countdown, screenshots; and of
// /event-page-6/ (the event page block): broken images degrade (image-fallback.js).
const { test, expect } = require( '@playwright/test' );
const { stub, refresh, preparePage } = require( './helpers' );

test.beforeEach( async ( { baseURL } ) => {
	await stub( { reset: true } );
	await refresh( baseURL, 1 );
} );

test.afterAll( async ( { baseURL } ) => {
	await stub( { reset: true } );
	await refresh( baseURL, 1 );
} );

test( 'agenda track filter hides and shows sessions, rooms follow their parent', async ( { page } ) => {
	const errors = await preparePage( page );
	await page.goto( '/all-blocks-1/' );

	const agenda = page.locator( '.odv-agenda' );
	const all = agenda.locator( '.odv-agenda__filter[data-track=""]' );
	const workshop = agenda.locator( '.odv-agenda__filter[data-track="Atelier"]' );
	const main = agenda.locator( '.odv-agenda__filter[data-track="Scène principale"]' );
	const parent = agenda.locator( '.odv-agenda__session:not(.odv-agenda__session--child)', { hasText: 'lever des fonds en Afrique' } );
	const room = agenda.locator( '.odv-agenda__session--child', { hasText: 'salle fintech' } );
	const keynote = agenda.locator( '.odv-agenda__session', { hasText: 'Ouverture et mot de bienvenue' } );

	await expect( all ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( keynote ).toBeVisible();
	await expect( room ).toBeVisible();

	await workshop.click();
	await expect( workshop ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( all ).toHaveAttribute( 'aria-pressed', 'false' );
	await expect( keynote ).toHaveAttribute( 'hidden', '' );
	await expect( keynote ).toBeHidden();
	await expect( parent ).toBeVisible();
	await expect( room ).toBeVisible();

	await main.click();
	await expect( main ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( workshop ).toHaveAttribute( 'aria-pressed', 'false' );
	await expect( keynote ).toBeVisible();
	await expect( parent ).toHaveAttribute( 'hidden', '' );
	await expect( room ).toBeHidden(); // The room has the "Atelier" track too, but follows its hidden parent.

	await all.click();
	await expect( all ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( parent ).not.toHaveAttribute( 'hidden', '' );
	await expect( room ).toBeVisible();
	await expect( keynote ).toBeVisible();

	expect( errors.relevant ).toEqual( [] );
	test.info().annotations.push( { type: 'console noise', description: JSON.stringify( errors.noise ) } );
	test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
} );

test( 'countdown to a start one hour ahead ticks and never shows NaN', async ( { page, baseURL } ) => {
	await stub( { starts_in: { 1: 3600 } } );
	await refresh( baseURL, 1 );
	const errors = await preparePage( page );
	await page.goto( '/all-blocks-1/' );

	const countdown = page.locator( '.odv-countdown' );
	await expect( countdown ).toBeVisible();
	await expect( countdown ).toHaveAttribute( 'data-state', 'upcoming' );
	await expect( countdown ).toHaveAttribute( 'data-odv-ready', '1' ); // countdown.js ran
	const seconds = countdown.locator( '[data-unit="seconds"]' );
	const minutes = countdown.locator( '[data-unit="minutes"]' );
	await expect( countdown.locator( '[data-unit="days"]' ) ).toHaveText( '0' );
	await expect( countdown.locator( '[data-unit="hours"]' ) ).toHaveText( /^0[01]$/ );
	await expect( minutes ).toHaveText( /^[0-5][0-9]$/ );

	const first = await seconds.textContent();
	await expect.poll( async () => seconds.textContent(), { timeout: 3000, intervals: [ 250 ] } ).not.toBe( first );
	await expect( countdown ).not.toContainText( 'NaN' );

	expect( errors.relevant ).toEqual( [] );
} );

test( 'a paused event: the countdown hides itself once the start has passed', async ( { page, baseURL } ) => {
	// 15 s ahead: enough for the ping, the page load and the 'visible before the start' checks on a slow runner.
	await stub( { status: { 1: 'paused' }, starts_in: { 1: 15 } } );
	await refresh( baseURL, 1 );
	const errors = await preparePage( page );
	await page.goto( '/all-blocks-1/' );

	const countdown = page.locator( '.odv-countdown' );
	await expect( countdown ).toHaveAttribute( 'data-odv-paused', '1' );
	await expect( countdown ).toBeVisible();
	await expect( page.locator( '.odv-notice' ).first() ).toContainText( 'Registration is temporarily closed.' );

	await expect( countdown ).toHaveAttribute( 'hidden', '', { timeout: 25000 } );
	await expect( countdown ).toBeHidden();
	await expect( countdown ).not.toContainText( 'Live now' );
	await page.waitForTimeout( 2000 );
	await expect( countdown ).toBeHidden(); // and stays hidden

	expect( errors.relevant ).toEqual( [] );
} );

test( 'event page: a 404 sponsor logo becomes the partner name, a 404 cover is removed', async ( { page } ) => {
	// Event 6's cover and the logo of its "both" partner point at the stub, which answers 404 for them.
	const broken = [ /^http:\/\/127\.0\.0\.1:8081\/storage\/missing\/cover-6\.webp$/, /^http:\/\/127\.0\.0\.1:8081\/storage\/missing\/partner-406\.png$/ ];
	const errors = await preparePage( page, { expected404: broken } );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( '/event-page-6/' );

	const eventPage = page.locator( '.odv-event-page' );
	await expect( eventPage ).toBeVisible();
	await expect( eventPage.locator( '.odv-header__cover' ) ).toHaveCount( 0 ); // removed, no broken-image icon
	await expect( eventPage.locator( '.odv-header__logo' ) ).toBeVisible(); // a logo that loads stays

	// The sponsor logos are lazy: they load (and the broken one fails) once the visitor scrolls to them.
	const sponsors = eventPage.locator( '.odv-sponsors' );
	await sponsors.scrollIntoViewIfNeeded();
	await expect( sponsors.locator( '.odv-sponsors__group-title', { hasText: 'Sponsors and exhibitors' } ) ).toBeVisible();
	const both = sponsors.locator( '.odv-sponsors__item', { hasText: 'Expo Partenaires CI' } );
	await expect( both.locator( 'img' ) ).toHaveCount( 0 );
	await expect( both.locator( '.odv-sponsors__name' ) ).toHaveText( 'Expo Partenaires CI' );
	await expect( both.locator( 'a.odv-sponsors__link' ) ).toHaveAttribute( 'href', 'https://expo-partenaires.example' ); // the link stays
	const orange = sponsors.locator( 'img.odv-sponsors__logo[alt="Orange Côte d\'Ivoire"]' );
	await expect( orange ).toBeVisible();
	await expect.poll( () => orange.evaluate( ( img ) => img.complete && img.naturalWidth > 0 ) ).toBe( true );
	await expect( eventPage.locator( 'img[data-odv-img="photo"]' ) ).toHaveCount( 3 ); // speaker photos that load stay

	// Both 404s really happened, and nothing else went wrong.
	await expect.poll( () => errors.expected.length ).toBeGreaterThanOrEqual( 2 );
	expect( [ ...new Set( errors.expected ) ].sort() ).toEqual( [
		'http://127.0.0.1:8081/storage/missing/cover-6.webp',
		'http://127.0.0.1:8081/storage/missing/partner-406.png',
	] );
	await page.screenshot( { path: `artifacts/event-page-6-wp${ process.env.WP_VERSION || 'x' }-1280.png`, fullPage: true } );
	expect( errors.relevant ).toEqual( [] );
	test.info().annotations.push( { type: 'console noise', description: JSON.stringify( errors.noise ) } );
	test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
} );

for ( const width of [ 390, 1280 ] ) {
	test( `screenshot of the front end at ${ width } px (light mode)`, async ( { page } ) => {
		const errors = await preparePage( page );
		await page.setViewportSize( { width, height: 900 } );
		await page.goto( '/all-blocks-1/' );
		await expect( page.locator( '.odv-header' ) ).toBeVisible();
		// Load the lazy images (placeholders served by preparePage) so the full-page screenshot shows them.
		await page.evaluate( () => document.querySelectorAll( 'img[loading="lazy"]' ).forEach( ( img ) => ( img.loading = 'eager' ) ) );
		await expect
			.poll( () => page.evaluate( () => [ ...document.images ].every( ( img ) => img.complete && img.naturalWidth > 0 ) ) )
			.toBe( true );
		const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		test.info().annotations.push( { type: 'horizontal overflow px', description: String( overflow ) } );
		await page.screenshot( { path: `artifacts/all-blocks-1-wp${ process.env.WP_VERSION || 'x' }-${ width }.png`, fullPage: true } );
		test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
		expect( overflow ).toBeLessThanOrEqual( 0 );
		expect( errors.relevant ).toEqual( [] );
	} );
}
