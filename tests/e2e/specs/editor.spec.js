// Block editor: the Odavent blocks register (apiVersion 3), the Inspector's event picker lists the stub events,
// ServerSideRender previews the chosen event, attribute toggles re-render, a slug-only block selects its event.
const { test, expect } = require( '@playwright/test' );
const { stub, refresh, login, collectErrors } = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

test.beforeAll( async ( { baseURL } ) => {
	await stub( { reset: true } );
	await refresh( baseURL, 1 );
} );

/** The editor canvas: the iframe (WordPress 6.3+ with apiVersion 3 blocks) or the page itself (6.1/6.2). */
async function canvas( page ) {
	const frame = page.locator( 'iframe[name="editor-canvas"]' );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
	if ( await frame.count() ) {
		await frame.waitFor();
		return page.frameLocator( 'iframe[name="editor-canvas"]' );
	}
	return page;
}

async function dismissWelcomeGuide( page ) {
	await page.evaluate( () => {
		try {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		} catch ( e ) {}
		try {
			const select = window.wp.data.select( 'core/edit-post' );
			if ( select && select.isFeatureActive && select.isFeatureActive( 'welcomeGuide' ) ) {
				window.wp.data.dispatch( 'core/edit-post' ).toggleFeature( 'welcomeGuide' );
			}
		} catch ( e ) {}
	} );
	const close = page.getByRole( 'dialog' ).getByRole( 'button', { name: /close/i } );
	if ( await close.count() ) {
		await close.first().click();
	}
}

async function openSettingsSidebar( page ) {
	const toggle = page.getByRole( 'button', { name: 'Settings', exact: true } ).first();
	if ( ( await toggle.getAttribute( 'aria-pressed' ) ) !== 'true' && ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await toggle.click();
	}
}

test( 'insert the Speakers block from the inserter, pick event 1, toggle the biography', async ( { page } ) => {
	const errors = collectErrors( page );
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=page' );
	const editor = await canvas( page );
	await dismissWelcomeGuide( page );

	// The block types exist client-side with the API version from block.json.
	const registered = await page.evaluate( () =>
		window.wp.blocks.getBlockTypes().filter( ( b ) => b.name.indexOf( 'odavent-events/' ) === 0 ).map( ( b ) => [ b.name, b.apiVersion ] )
	);
	expect( registered.length ).toBe( 10 );
	expect( registered.every( ( entry ) => entry[ 1 ] === 3 ) ).toBe( true );

	// Inserter search, the way an editor finds the block.
	await page.getByRole( 'button', { name: /^(Toggle block inserter|Block Inserter|Add block)$/ } ).first().click();
	const search = page.getByRole( 'searchbox' ).or( page.getByPlaceholder( /^Search/ ) ).first();
	await search.fill( 'Speakers' );
	const option = page.getByRole( 'option', { name: /^\s*Speakers\s*$/ } ).first();
	await expect( option ).toBeVisible();
	await option.click();

	const selected = await page.evaluate( () => {
		const block = window.wp.data.select( 'core/block-editor' ).getSelectedBlock();
		return block ? block.name : null;
	} );
	expect( selected ).toBe( 'odavent-events/speakers' );

	await openSettingsSidebar( page );
	const picker = page.getByRole( 'combobox', { name: 'Event', exact: true } );
	await expect( picker ).toBeVisible();
	const labels = await picker.locator( 'option' ).allTextContents();
	test.info().annotations.push( { type: 'event options', description: JSON.stringify( labels ) } );
	expect( labels ).toContain( 'Default event (from the settings)' );
	expect( labels ).toContain( "Sommet Tech d'Abidjan 2026 (Published)" );
	expect( labels ).toContain( 'Cancelled Conference 2026 (Cancelled)' );
	expect( labels ).toContain( 'Secret Draft Workshop 3 (Draft)' );
	expect( labels.some( ( label ) => /<script/i.test( label ) ) ).toBe( false );

	await picker.selectOption( '1' );
	const preview = editor.locator( '.odv-speakers' );
	await expect( preview ).toBeVisible();
	await expect( preview ).toContainText( 'Aïcha Koné' );
	await expect( preview ).toContainText( 'Fatoumata Diallo' );
	await expect( preview ).not.toContainText( 'transition vers les paiements mobiles' );

	const attrs = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getSelectedBlock().attributes );
	expect( attrs.eventId ).toBe( 1 );
	expect( attrs.eventSlug ).toBe( 'sommet-tech-abidjan-2026' );

	await page.getByRole( 'checkbox', { name: 'Show biography' } ).check();
	await expect( preview ).toContainText( 'transition vers les paiements mobiles' );

	expect( errors.relevant ).toEqual( [] );
	test.info().annotations.push( { type: 'console noise', description: JSON.stringify( errors.noise ) } );
} );

test( 'a block holding only eventSlug selects that event, not "Default event"', async ( { page, request } ) => {
	const errors = collectErrors( page );
	await login( page );
	const found = await page.evaluate( async () => {
		const pages = await window.fetch( '/wp-json/wp/v2/pages?slug=slug-only&_fields=id', { credentials: 'same-origin' } ).then( ( r ) => r.json() );
		return pages[ 0 ] ? pages[ 0 ].id : 0;
	} );
	expect( found ).toBeGreaterThan( 0 );

	await page.goto( '/wp-admin/post.php?action=edit&post=' + found );
	const editor = await canvas( page );
	await dismissWelcomeGuide( page );
	await expect( editor.locator( '.odv-speakers' ) ).toBeVisible(); // ServerSideRender of every block

	const blocks = await page.evaluate( () =>
		window.wp.data.select( 'core/block-editor' ).getBlocks().map( ( b ) => ( { id: b.clientId, name: b.name, attributes: b.attributes } ) )
	);
	expect( blocks.length ).toBe( 10 );
	expect( blocks.every( ( b ) => b.attributes.eventSlug === 'sommet-tech-abidjan-2026' && ! b.attributes.eventId ) ).toBe( true );

	const speakers = blocks.find( ( b ) => b.name === 'odavent-events/speakers' );
	await page.evaluate( ( id ) => window.wp.data.dispatch( 'core/block-editor' ).selectBlock( id ), speakers.id );
	await openSettingsSidebar( page );

	const picker = page.getByRole( 'combobox', { name: 'Event', exact: true } );
	await expect( picker ).toBeVisible();
	await expect( picker ).toHaveValue( '1' );
	const shown = await picker.evaluate( ( select ) => select.options[ select.selectedIndex ].textContent );
	expect( shown ).toBe( "Sommet Tech d'Abidjan 2026 (Published)" );
	expect( shown ).not.toContain( 'Default event' );

	expect( errors.relevant ).toEqual( [] );
	test.info().annotations.push( { type: 'console noise', description: JSON.stringify( errors.noise ) } );
} );
