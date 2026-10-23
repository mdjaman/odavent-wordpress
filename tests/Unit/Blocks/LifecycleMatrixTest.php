<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

/**
 * Lifecycle state x block: a Register link (odv-register__button, odv-tickets__register) exists only when
 * registration is really open, and every other state says why.
 */
class LifecycleMatrixTest extends BlockTestCase {

	const ENDED     = 'This event has ended';
	const PAUSED    = 'Registration is temporarily closed';
	const CANCELLED = 'This event has been cancelled';
	const DRAFT     = 'Draft preview';
	const CLOSED    = 'Registration is closed';

	/**
	 * state label, editor?, links expected?, lifecycle text expected on header/register (null: none), text on tickets.
	 */
	public function states(): array {
		return array(
			'LIVE'                         => array( 'live', false, true, null, null ),
			'LIVE, registration closed'    => array( 'live closed', false, false, null, self::CLOSED ),
			'ENDED, registration open'     => array( 'ended open', false, true, self::ENDED, null ),
			'ENDED, registration closed'   => array( 'ended closed', false, false, self::ENDED, self::ENDED ),
			'PAUSED'                       => array( 'paused', false, false, self::PAUSED, self::PAUSED ),
			'CANCELLED'                    => array( 'cancelled', false, false, self::CANCELLED, self::CANCELLED ),
			'ARCHIVED'                     => array( 'archived', false, false, self::ENDED, self::ENDED ),
			'DRAFT, editor'                => array( 'draft', true, false, self::DRAFT, 'once the event is published' ),
		);
	}

	/**
	 * @dataProvider states
	 */
	public function test_register_links_only_when_registration_is_open( string $state, bool $editor, bool $links, ?string $notice, ?string $tickets_text ): void {
		$this->editor = $editor;
		$registry     = $this->registry( $this->payload( self::state_overrides()[ $state ] ) );

		$header   = $registry->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) );
		$register = $registry->render_block( 'odavent-events/register-button', array( 'eventId' => 42 ) );
		$tickets  = $registry->render_block( 'odavent-events/tickets', array( 'eventId' => 42 ) );

		// The header always shows the event (content stays in every state that has content).
		$this->assertStringContainsString( 'odv-header__title', $header );
		$this->assertStringContainsString( 'odv-tickets__item', $tickets );

		if ( $links ) {
			$this->assertMatchesRegularExpression( '/<a class="odv-register__button" href="https:/', $register );
			$this->assertMatchesRegularExpression( '/<a class="odv-tickets__register" href="https:/', $tickets );
			$this->assertStringContainsString( 'Registration and payment are handled securely by Odavent.', $register );
		} else {
			$this->assertStringNotContainsString( 'odv-register__button', $register );
			$this->assertStringNotContainsString( 'odv-tickets__register', $tickets );
			$this->assertStringNotContainsString( 'odavent.com/events/sommet-tech-abidjan-2026/register', $register . $tickets . $header );
		}

		foreach ( array( 'header' => $header, 'register' => $register ) as $block => $html ) {
			if ( null === $notice ) {
				$this->assertStringNotContainsString( 'odv-notice', $html, $block );
			} else {
				$this->assertStringContainsString( $notice, $html, $block );
				$this->assertMatchesRegularExpression( '/^<div class="odv-notice odv-notice--[a-z]+" role="status">/', $html, $block . ': notice above the content' );
			}
		}

		// Tickets never carry the lifecycle notice box (only a stale copy's notice); they replace their links with a line.
		$this->assertStringNotContainsString( 'odv-notice', $tickets );
		$this->assertStringNotContainsString( 'odv-notice--stale', $register . $header );
		if ( null === $tickets_text ) {
			$this->assertStringNotContainsString( 'odv-tickets__message', $tickets );
		} else {
			$this->assertStringContainsString( $tickets_text, $tickets );
		}

		if ( null === $notice && ! $links ) {
			$this->assertStringContainsString( self::CLOSED, $register );
		}
	}

	const STALE = 'Registration details could not be refreshed. Please check the event page on Odavent.';

	public function stale_states(): array {
		return array(
			'stale LIVE'                     => array( 'live', false ),
			'stale LIVE, editor'             => array( 'live', true ),
			'stale ENDED, registration open' => array( 'ended open', false ),
			'stale PAUSED'                   => array( 'paused', false ),
			'stale CANCELLED'                => array( 'cancelled', false ),
		);
	}

	/**
	 * A last-good copy served because Odavent failed never offers Register: the event may have been
	 * cancelled, paused or unpublished since. Content stays.
	 *
	 * @dataProvider stale_states
	 */
	public function test_a_stale_copy_never_offers_registration( string $state, bool $editor ): void {
		$this->editor = $editor;
		$payload      = $this->payload( self::state_overrides()[ $state ] );

		// Not vacuous: the same payload, fresh, offers the links when its state allows them.
		$fresh = $this->registry( $payload );
		if ( in_array( $state, array( 'live', 'ended open' ), true ) ) {
			$this->assertStringContainsString( 'odv-register__button', $fresh->render_block( 'odavent-events/register-button', array( 'eventId' => 42 ) ) );
			$this->assertStringContainsString( 'odv-tickets__register', $fresh->render_block( 'odavent-events/tickets', array( 'eventId' => 42 ) ) );
		}

		$registry = $this->registry( $payload, Event_View::UNAVAILABLE, array(), 0, true );
		$header   = $registry->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) );
		$register = $registry->render_block( 'odavent-events/register-button', array( 'eventId' => 42 ) );
		$tickets  = $registry->render_block( 'odavent-events/tickets', array( 'eventId' => 42 ) );

		$this->assertStringContainsString( 'odv-header__title', $header );
		$this->assertStringNotContainsString( 'odv-register__button', $register );
		$this->assertStringNotContainsString( 'odv-tickets__register', $tickets );
		$this->assertStringNotContainsString( 'odavent.com/events/sommet-tech-abidjan-2026/register', $register . $tickets );
		$this->assertStringNotContainsString( 'odv-register__notice', $register . $tickets );

		$this->assertStringContainsString( '<div class="odv-notice odv-notice--stale" role="status">' . self::STALE . '</div>', $register );
		$this->assertStringContainsString( '<div class="odv-notice odv-notice--stale" role="status">' . self::STALE . '</div>', $tickets );
		$this->assertLessThan( strpos( $tickets, 'odv-tickets__list' ), strpos( $tickets, 'odv-notice--stale' ), 'above the list' );
		$this->assertStringContainsString( 'Pass Standard', $tickets, 'ticket names stay' );
		$this->assertStringContainsString( 'odv-tickets__price', $tickets, 'prices stay' );
		$this->assertStringNotContainsString( 'odv-notice--stale', $header );
		$this->assertStringNotContainsString( 'odv-tickets__message', $tickets );
		$this->assertStringNotContainsString( 'offers', $header, 'no JSON-LD offers from a stale copy' );
	}

	public function test_a_draft_shows_nothing_to_visitors_and_the_repository_is_asked_without_preview(): void {
		$this->editor = false;
		$registry     = $this->registry( $this->payload( self::state_overrides()['draft'] ) );

		foreach ( array( 'event-header', 'register-button', 'tickets', 'find-registration' ) as $block ) {
			$this->assertSame( '', $registry->render_block( 'odavent-events/' . $block, array( 'eventId' => 42 ) ), $block );
		}
		foreach ( $this->repo_calls as $call ) {
			$this->assertFalse( $call[2], 'preview_allowed must be false for a visitor' );
		}
		$this->assertSame( array(), $this->enqueued_styles );
	}

	public function test_an_editor_asks_the_repository_for_a_preview(): void {
		$this->editor = true;
		$this->registry( $this->payload( self::state_overrides()['draft'] ) )->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) );

		$this->assertSame( array( array( 42, '', true ) ), $this->repo_calls );
	}

	public function empty_states(): array {
		return array(
			// Strings, not Event_View constants: data providers run before setUp() loads the plugin's autoloader.
			'GONE'        => array( 'gone' ),
			'UNAVAILABLE' => array( 'unavailable' ),
			'DRAFT'       => array( 'draft' ),
		);
	}

	/**
	 * @dataProvider empty_states
	 */
	public function test_an_empty_view_is_blank_for_visitors( string $state ): void {
		$registry = $this->registry( null, $state );

		foreach ( array( 'event-header', 'register-button', 'tickets', 'find-registration' ) as $block ) {
			$this->assertSame( '', $registry->render_block( 'odavent-events/' . $block, array( 'eventId' => 42 ) ), $block );
		}
		$this->assertSame( array(), $this->enqueued_styles, 'Nothing rendered: no stylesheet.' );
	}

	/**
	 * @dataProvider empty_states
	 */
	public function test_an_empty_view_is_a_notice_for_editors( string $state ): void {
		$this->editor = true;
		$registry     = $this->registry( null, $state );

		foreach ( array( 'event-header', 'register-button', 'tickets', 'find-registration' ) as $block ) {
			$html = $registry->render_block( 'odavent-events/' . $block, array( 'eventId' => 42 ) );
			$this->assertStringStartsWith( '<div class="odv-notice odv-notice--', $html, $block );
			$this->assertStringContainsString( 'role="status"', $html );
			$this->assertStringNotContainsString( 'odv-register__button', $html );
			$this->assertStringNotContainsString( 'odv-tickets__register', $html );
		}
	}

	public function test_hostile_payload_is_safe_in_every_block_and_state(): void {
		foreach ( array( false, true ) as $editor ) {
			$this->editor = $editor;
			foreach ( self::state_overrides() as $state => $overrides ) {
				// Safe register URLs here, so the links themselves are exercised with hostile text around them.
				$payload  = $this->payload( $overrides + array( 'register_url' => "https://odavent.com/register?x='onfocus=alert(1)", 'find_registration_url' => "https://odavent.com/f'onmouseover=alert(1)" ), 'payload-v1-hostile' );
				$registry = $this->registry( $payload );
				foreach ( array( 'event-header', 'register-button', 'tickets', 'find-registration' ) as $block ) {
					$html = $registry->render_block( 'odavent-events/' . $block, array( 'eventId' => 42, 'showAvailability' => true, 'className' => '"><script>' ) );
					$this->assertSafeHtml( $html );
					$this->assertStringNotContainsString( 'javascript:', $html, $state . ' ' . $block );
					$this->assertStringNotContainsString( 'data:text', $html, $state . ' ' . $block );
				}
			}
		}
	}

	public function test_find_registration_stays_for_published_states_and_is_hidden_for_empty_ones(): void {
		foreach ( array( 'live', 'ended closed', 'paused', 'cancelled', 'archived' ) as $state ) {
			$html = $this->registry( $this->payload( self::state_overrides()[ $state ] ) )->render_block( 'odavent-events/find-registration', array( 'eventId' => 42 ) );
			$this->assertStringContainsString( 'href="https://odavent.com/events/sommet-tech-abidjan-2026/find-registration"', $html, $state );
			$this->assertStringNotContainsString( 'odv-notice', $html, 'No lifecycle notice on other blocks.' );
		}
	}
}
