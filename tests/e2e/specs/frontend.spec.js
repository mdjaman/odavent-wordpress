// Front end of /all-blocks-1/ (all ten blocks for event 1): agenda filter, countdown, screenshots.
const { test, expect } = require( '@playwright/test' );
const { stub, refresh, collectErrors } = require( './helpers' );

test.beforeEach( async ( { baseURL } ) => {
	await stub( { reset: true } );
	await refresh( baseURL, 1 );
} );

test.afterAll( async ( { baseURL } ) => {
	await stub( { reset: true } );
	await refresh( baseURL, 1 );
} );

test( 'agenda track filter hides and shows sessions, rooms follow their parent', async ( { page } ) => {
	const errors = collectErrors( page );
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
} );

test( 'countdown to a start one hour ahead ticks and never shows NaN', async ( { page, baseURL } ) => {
	await stub( { starts_in: { 1: 3600 } } );
	await refresh( baseURL, 1 );
	const errors = collectErrors( page );
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
	await stub( { status: { 1: 'paused' }, starts_in: { 1: 6 } } );
	await refresh( baseURL, 1 );
	const errors = collectErrors( page );
	await page.goto( '/all-blocks-1/' );

	const countdown = page.locator( '.odv-countdown' );
	await expect( countdown ).toHaveAttribute( 'data-odv-paused', '1' );
	await expect( countdown ).toBeVisible();
	await expect( page.locator( '.odv-notice' ).first() ).toContainText( 'Registration is temporarily closed.' );

	await expect( countdown ).toHaveAttribute( 'hidden', '', { timeout: 12000 } );
	await expect( countdown ).toBeHidden();
	await expect( countdown ).not.toContainText( 'Live now' );
	await page.waitForTimeout( 2000 );
	await expect( countdown ).toBeHidden(); // and stays hidden

	expect( errors.relevant ).toEqual( [] );
} );

for ( const width of [ 390, 1280 ] ) {
	test( `screenshot of the front end at ${ width } px (light mode)`, async ( { page } ) => {
		await page.setViewportSize( { width, height: 900 } );
		await page.goto( '/all-blocks-1/' );
		await expect( page.locator( '.odv-header' ) ).toBeVisible();
		const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		test.info().annotations.push( { type: 'horizontal overflow px', description: String( overflow ) } );
		await page.screenshot( { path: `artifacts/all-blocks-1-${ width }.png`, fullPage: true } );
		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
}
