/* global wpfcsConfig */
( function () {
	'use strict';

	const root = document.getElementById( 'wpfcs-root' );
	if ( ! root || typeof wpfcsConfig === 'undefined' ) {
		return;
	}

	function post( data ) {
		const body = new URLSearchParams( Object.assign( { nonce: wpfcsConfig.nonce }, data ) );
		return fetch( wpfcsConfig.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( r ) => r.json() );
	}

	function loadStats( force ) {
		root.classList.add( 'is-busy' );
		return post( { action: 'wpfcs_stats', force: force ? '1' : '' } )
			.then( ( res ) => {
				if ( res.success ) {
					// Nur den Inhalt ersetzen – der Widget-Rahmen samt Titelleiste bleibt stehen.
					root.innerHTML = res.data.html;
				} else {
					throw new Error( ( res.data && res.data.message ) || 'Unbekannter Fehler' );
				}
			} )
			.catch( ( err ) => {
				root.insertAdjacentHTML( 'beforeend', '<p class="wpfcs-warn">⚠️ Statistiken konnten nicht geladen werden: ' + String( err.message ).replace( /</g, '&lt;' ) + '</p>' );
			} )
			.finally( () => root.classList.remove( 'is-busy' ) );
	}

	// Event-Delegation: funktioniert auch nach dem Austausch des Inhalts per AJAX.
	root.addEventListener( 'click', ( e ) => {
		if ( e.target.closest( '.wpfcs-refresh' ) ) {
			e.preventDefault();
			loadStats( true );
			return;
		}

		const btn = e.target.closest( '.wpfcs-clear' );
		if ( ! btn ) {
			return;
		}
		const minified = root.querySelector( '.wpfcs-minified' );
		const withMin = !! ( minified && minified.checked );
		const msg = 'Wirklich den kompletten Seiten-Cache leeren?\n\n'
			+ 'Danach muss WordPress jede Seite beim nächsten Aufruf neu erzeugen. '
			+ 'Bei viel Besucher- oder Crawler-Verkehr kann das den Server vorübergehend stark belasten.'
			+ ( withMin ? '\n\nZusätzlich werden die minifizierten CSS/JS-Dateien gelöscht.' : '' );
		if ( ! window.confirm( msg ) ) {
			return;
		}

		const status = root.querySelector( '.wpfcs-status' );
		btn.disabled = true;
		status.textContent = '⏳ Bitte warten …';
		post( { action: 'wpfcs_clear', minified: withMin ? '1' : '' } )
			.then( ( res ) => {
				if ( ! res.success ) {
					throw new Error( ( res.data && res.data.message ) || 'Unbekannter Fehler' );
				}
				return loadStats( true ).then( () => {
					const s = root.querySelector( '.wpfcs-status' );
					if ( s ) {
						s.textContent = '✅ ' + res.data.message;
					}
				} );
			} )
			.catch( ( err ) => {
				status.textContent = '❌ ' + err.message;
				btn.disabled = false;
			} );
	} );

	if ( root.querySelector( '[data-wpfcs-autoload]' ) ) {
		loadStats( false );
	}
}() );
