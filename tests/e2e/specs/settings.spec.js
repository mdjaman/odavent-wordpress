// Settings > Odavent Events > Appearance: the live preview follows the colour fields (with the WordPress colour
// picker) and warns about a hard-to-read text-on-accent pair. Nothing is saved here (bin/smoke.sh check 16 saves).
const { test, expect } = require( '@playwright/test' );
const { login, preparePage } = require( './helpers' );

const FIELD = '#odavent_events_appearance_';

/** Types a colour into a field wrapped by the WordPress colour picker (its input shows after "Select Color"). */
async function typeColor( page, name, value ) {
	const input = page.locator( FIELD + name );
	const container = page.locator( '.wp-picker-container', { has: input } );
	if ( ! ( await input.isVisible() ) ) {
		await container.locator( 'button.wp-color-result' ).click();
	}
	await input.fill( value );
	await input.press( 'Tab' );
}

async function previewVar( preview, name ) {
	return preview.evaluate( ( el, prop ) => el.style.getPropertyValue( prop ).trim(), name );
}

test( 'Appearance: the preview follows the accent colour and warns about a low-contrast pair', async ( { page } ) => {
	const errors = await preparePage( page );
	await login( page );
	await page.goto( '/wp-admin/options-general.php?page=odavent-events' );

	const preview = page.locator( '.odv-appearance-preview' );
	await expect( preview ).toHaveAttribute( 'data-odv-ready', '1' ); // admin-appearance.js ran
	await expect( page.locator( '.wp-picker-container' ).first() ).toBeVisible(); // the colour picker is on
	const button = preview.locator( '.odv-register__button' );
	const ratio = preview.locator( '.odv-appearance-preview__ratio' );
	const warning = preview.locator( '.odv-appearance-preview__warning' );

	// A readable accent with automatic text: the preview's button takes it, white text, no warning.
	await page.locator( FIELD + 'contrast_auto' ).check();
	await typeColor( page, 'accent', '#0055aa' );
	await expect.poll( () => previewVar( preview, '--odv-accent' ) ).toBe( '#0055aa' );
	await expect.poll( () => previewVar( preview, '--odv-accent-contrast' ) ).toBe( '#ffffff' );
	await expect( button ).toHaveCSS( 'background-color', 'rgb(0, 85, 170)' );
	await expect( button ).toHaveCSS( 'color', 'rgb(255, 255, 255)' );
	await expect( ratio ).toBeVisible();
	await expect( ratio ).toHaveText( /^Text on accent contrast: \d+\.\d\d:1$/ );
	await expect( warning ).toBeHidden();

	// Another accent: the preview follows again.
	await typeColor( page, 'accent', '#c0392b' );
	await expect.poll( () => previewVar( preview, '--odv-accent' ) ).toBe( '#c0392b' );
	await expect( button ).toHaveCSS( 'background-color', 'rgb(192, 57, 43)' );

	// White text on yellow, chosen by hand: 1.07:1, the warning shows.
	await typeColor( page, 'accent', '#ffff00' );
	await page.locator( FIELD + 'contrast_auto' ).uncheck();
	await expect( page.locator( FIELD + 'contrast_manual' ) ).toBeVisible();
	await typeColor( page, 'contrast', '#ffffff' );
	await expect.poll( () => previewVar( preview, '--odv-accent-contrast' ) ).toBe( '#ffffff' );
	await expect( ratio ).toHaveText( 'Text on accent contrast: 1.07:1' );
	await expect( warning ).toBeVisible();
	await expect( warning ).toHaveText( 'Below 4.5:1: button text may be hard to read.' );

	// Automatic again: black on yellow reads well, the warning goes.
	await page.locator( FIELD + 'contrast_auto' ).check();
	await expect.poll( () => previewVar( preview, '--odv-accent-contrast' ) ).toBe( '#000000' );
	await expect( warning ).toBeHidden();

	expect( errors.relevant ).toEqual( [] );
	test.info().annotations.push( { type: 'console noise', description: JSON.stringify( errors.noise ) } );
	test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
} );
