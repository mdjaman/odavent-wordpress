// Block editor: the Odavent blocks register (apiVersion 3), the Inspector's event picker lists the stub events,
// ServerSideRender previews the chosen event, attribute toggles re-render, a slug-only block selects its event;
// the Event page block's section toggles change its preview, and the "Full event page" pattern inserts.
const { test, expect } = require( '@playwright/test' );
const { stub, refresh, login, preparePage, BROKEN_IMAGE } = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

/** The ten individual blocks in the order of an event page (the "Full event page" pattern's order). */
const PAGE_ORDER = [ 'event-header', 'countdown', 'register-button', 'tickets', 'find-registration', 'speakers', 'agenda', 'sponsors', 'venue', 'add-to-calendar' ].map(
	( slug ) => 'odavent-events/' + slug
);

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

/**
 * Closes the welcome guide. bin/e2e also stores the admin's editor preferences (no welcome guide, no "Choose a
 * pattern" modal for new pages on WordPress 6.6+) before the browser starts: that modal opens a moment after
 * load and would otherwise cover the inserter at random.
 */
async function dismissWelcomeGuide( page ) {
	await page.evaluate( () => {
		try {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		} catch ( e ) {}
		try {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'enableChoosePatternModal', false );
		} catch ( e ) {}
		try {
			const select = window.wp.data.select( 'core/edit-post' );
			if ( select && select.isFeatureActive && select.isFeatureActive( 'welcomeGuide' ) ) {
				window.wp.data.dispatch( 'core/edit-post' ).toggleFeature( 'welcomeGuide' );
			}
		} catch ( e ) {}
	} );
}

/** Opens the inserter and searches it, the way an editor looks for a block or a pattern. */
async function searchInserter( page, text ) {
	await page.getByRole( 'button', { name: /^(Toggle block inserter|Block Inserter|Add block)$/ } ).first().click();
	const search = page.getByRole( 'searchbox' ).or( page.getByPlaceholder( /^Search/ ) ).first();
	await search.fill( text );
}

async function openSettingsSidebar( page ) {
	const toggle = page.getByRole( 'button', { name: 'Settings', exact: true } ).first();
	if ( ( await toggle.getAttribute( 'aria-pressed' ) ) !== 'true' && ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await toggle.click();
	}
}

test( 'insert the Speakers block from the inserter, pick event 1, toggle the biography', async ( { page } ) => {
	const errors = await preparePage( page );
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=page' );
	const editor = await canvas( page );
	await dismissWelcomeGuide( page );

	// The block types exist client-side with the API version from block.json.
	const registered = await page.evaluate( () =>
		window.wp.blocks.getBlockTypes().filter( ( b ) => b.name.indexOf( 'odavent-events/' ) === 0 ).map( ( b ) => [ b.name, b.apiVersion ] )
	);
	expect( registered.map( ( entry ) => entry[ 0 ] ).sort() ).toEqual( PAGE_ORDER.concat( 'odavent-events/event-page' ).sort() );
	expect( registered.every( ( entry ) => entry[ 1 ] === 3 ) ).toBe( true );

	// Inserter search, the way an editor finds the block.
	await page.getByRole( 'button', { name: /^(Toggle block inserter|Block Inserter|Add block)$/ } ).first().click();
	const search = page.getByRole( 'searchbox' ).or( page.getByPlaceholder( /^Search/ ) ).first();
	await search.fill( 'Speakers' );
	// The accessible name carries the icon's text alternative, so match on the visible title.
	const option = page.getByRole( 'option' ).filter( { hasText: /^\W*Speakers\s*$/ } ).first();
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
	test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
} );

test( 'a block holding only eventSlug selects that event, not "Default event"', async ( { page, request } ) => {
	const errors = await preparePage( page );
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
	test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
} );

test( 'insert the Event page block, pick event 6, turn the Speakers section off: the preview drops it', async ( { page } ) => {
	const errors = await preparePage( page, { expected404: [ BROKEN_IMAGE ] } ); // event 6's cover and one logo 404 on purpose
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=page' );
	const editor = await canvas( page );
	await dismissWelcomeGuide( page );

	await searchInserter( page, 'Event page' );
	const option = page.getByRole( 'option' ).filter( { hasText: /^\W*Event page\s*$/ } ).first();
	await expect( option ).toBeVisible();
	await option.click();
	const selected = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getSelectedBlock().name );
	expect( selected ).toBe( 'odavent-events/event-page' );

	await openSettingsSidebar( page );
	await page.getByRole( 'combobox', { name: 'Event', exact: true } ).selectOption( '6' );
	const preview = editor.locator( '.odv-event-page' );
	await expect( preview ).toBeVisible();
	await expect( preview.locator( '.odv-event-page__section--speakers .odv-event-page__heading' ) ).toHaveText( 'Speakers' );
	await expect( preview.locator( '.odv-event-page__section--agenda' ) ).toHaveCount( 1 );
	await expect( preview.locator( '.odv-sponsors__group-title', { hasText: 'Sponsors and exhibitors' } ) ).toHaveCount( 1 );

	// One toggle per section, in page order, all on for a new block.
	const sections = page.getByRole( 'group', { name: 'Sections to show' } );
	const toggles = sections.getByRole( 'checkbox' );
	await expect( toggles ).toHaveCount( 10 );
	const speakers = sections.getByRole( 'checkbox', { name: 'Speakers', exact: true } );
	await expect( speakers ).toBeChecked();
	await speakers.click();
	await expect( speakers ).not.toBeChecked();

	// ServerSideRender asks the server again: the section and its heading are gone, the others stay.
	await expect( preview.locator( '.odv-event-page__section--speakers' ) ).toHaveCount( 0 );
	await expect( editor.locator( '.odv-event-page .odv-event-page__section--agenda' ) ).toHaveCount( 1 );
	await expect( editor.locator( '.odv-event-page .odv-event-page__heading', { hasText: /^Speakers$/ } ) ).toHaveCount( 0 );
	const attrs = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getSelectedBlock().attributes );
	expect( attrs.eventId ).toBe( 6 );
	expect( attrs.sections ).toBe( 'header,countdown,register,tickets,find-registration,agenda,sponsors,venue,calendar' );

	// Back on: every section again, stored as the empty list (= every section).
	await speakers.click();
	await expect( editor.locator( '.odv-event-page .odv-event-page__section--speakers' ) ).toHaveCount( 1 );
	expect( await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getSelectedBlock().attributes.sections ) ).toBe( '' );

	expect( errors.relevant ).toEqual( [] );
	test.info().annotations.push( { type: 'console noise', description: JSON.stringify( errors.noise ) } );
	test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
} );

test( 'the "Full event page" pattern inserts the ten blocks in page order', async ( { page } ) => {
	const errors = await preparePage( page );
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=page' );
	const editor = await canvas( page );
	await dismissWelcomeGuide( page );

	await searchInserter( page, 'Full event page' );
	// A pattern is an option named after its title (its preview is an image of the blocks).
	const pattern = page.getByRole( 'option', { name: 'Full event page' } ).first();
	await expect( pattern ).toBeVisible();
	await pattern.click();

	await expect.poll( () => page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks().map( ( b ) => b.name ) ) ).toEqual( PAGE_ORDER );
	// The blocks are on the canvas, not only in the store (they use the default event: no eventId in the pattern).
	await expect( editor.locator( '.wp-block[data-type="odavent-events/speakers"]' ) ).toHaveCount( 1 );

	expect( errors.relevant ).toEqual( [] );
	test.info().annotations.push( { type: 'console noise', description: JSON.stringify( errors.noise ) } );
	test.info().annotations.push( { type: 'external requests', description: JSON.stringify( errors.external ) } );
} );
