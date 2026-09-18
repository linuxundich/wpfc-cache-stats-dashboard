/* global wpfcsConfig */
( function () {
	'use strict';

	const root = document.getElementById( 'wpfcs-root' );
	if ( ! root || typeof wpfcsConfig === 'undefined' ) {
		return;
	}

	/**
	 * POST an admin-ajax.php. Werte, die Arrays sind, werden als name[]=…
	 * mehrfach angehängt (URLSearchParams aus einem Objekt kann das nicht).
	 */
	function post( data ) {
		const body = new URLSearchParams();
		body.append( 'nonce', wpfcsConfig.nonce );
		Object.keys( data ).forEach( ( key ) => {
			const value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( ( v ) => body.append( key + '[]', v ) );
			} else {
				body.append( key, value );
			}
		} );
		return fetch( wpfcsConfig.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( r ) => r.json() )
			.then( ( res ) => {
				if ( ! res.success ) {
					throw new Error( ( res.data && res.data.message ) || 'Unbekannter Fehler' );
				}
				return res.data;
			} );
	}

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

	function loadStats( force ) {
		root.classList.add( 'is-busy' );
		return post( { action: 'wpfcs_stats', force: force ? '1' : '' } )
			.then( ( data ) => {
				// Nur den Inhalt ersetzen – der Widget-Rahmen samt Titelleiste bleibt stehen.
				root.innerHTML = data.html;
			} )
			.catch( ( err ) => {
				root.insertAdjacentHTML( 'beforeend', '<p class="wpfcs-warn">⚠️ Statistiken konnten nicht geladen werden: ' + escapeHtml( err.message ) + '</p>' );
			} )
			.finally( () => root.classList.remove( 'is-busy' ) );
	}

	function onWarm( btn ) {
		const status = btn.parentNode.querySelector( '.wpfcs-warm-status' );
		const ids = ( btn.dataset.ids || '' ).split( ',' ).filter( Boolean );
		btn.disabled = true;
		status.textContent = '⏳ …';
		post( { action: 'wpfcs_warm', ids } )
			.then( ( data ) => {
				status.textContent = '✅ ' + data.message;
			} )
			.catch( ( err ) => {
				status.textContent = '❌ ' + err.message;
				btn.disabled = false;
			} );
	}

	function onClear( btn ) {
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
			.then( ( data ) => loadStats( true ).then( () => {
				const s = root.querySelector( '.wpfcs-status' );
				if ( s ) {
					s.textContent = '✅ ' + data.message;
				}
			} ) )
			.catch( ( err ) => {
				status.textContent = '❌ ' + err.message;
				btn.disabled = false;
			} );
	}

	// Event-Delegation: funktioniert auch nach dem Austausch des Inhalts per AJAX.
	root.addEventListener( 'click', ( e ) => {
		if ( e.target.closest( '.wpfcs-refresh' ) ) {
			e.preventDefault();
			loadStats( true );
			return;
		}
		const warm = e.target.closest( '.wpfcs-warm' );
		if ( warm ) {
			onWarm( warm );
			return;
		}
		const clear = e.target.closest( '.wpfcs-clear' );
		if ( clear ) {
			onClear( clear );
		}
	} );

	if ( root.querySelector( '[data-wpfcs-autoload]' ) ) {
		loadStats( false );
	}
}() );
