/* Shift-click to check or uncheck a range of terms; a checked term locks its child terms. */
( function () {
	var selector = '.taxperm-terms input[type="checkbox"]';
	var last = null;

	// Child terms of a checked term are restricted anyway: show them checked and disabled.
	function sync( list ) {
		list.querySelectorAll( selector ).forEach( function ( item ) {
			var parent = item.closest( 'ul' ).closest( 'li' );
			var parentBox = parent ? parent.querySelector( 'input[type="checkbox"]' ) : null;
			var locked = !! ( parentBox && parentBox.checked );

			if ( locked && ! item.disabled ) {
				item.dataset.own = item.checked ? '1' : '';
				item.checked = true;
			} else if ( ! locked && item.disabled ) {
				item.checked = '1' === item.dataset.own;
			}
			item.disabled = locked;
		} );
	}

	document.querySelectorAll( '.taxperm-terms' ).forEach( sync );

	document.addEventListener( 'click', function ( event ) {
		var box = event.target;

		if ( ! box.matches || ! box.matches( selector ) ) {
			return;
		}

		var list = box.closest( '.taxperm-terms' );

		if ( event.shiftKey && last && last !== box && list.contains( last ) ) {
			var boxes = Array.prototype.slice.call( list.querySelectorAll( selector ) );
			var from = boxes.indexOf( last );
			var to = boxes.indexOf( box );

			boxes.slice( Math.min( from, to ), Math.max( from, to ) + 1 ).forEach( function ( item ) {
				if ( ! item.disabled ) {
					item.checked = box.checked;
				}
			} );
		}

		last = box;
		sync( list );
	} );
} )();
