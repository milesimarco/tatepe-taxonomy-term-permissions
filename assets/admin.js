/* Shift-click to check or uncheck a range of terms. */
( function () {
	var selector = '.taxperm-terms input[type="checkbox"]';
	var last = null;

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
				item.checked = box.checked;
			} );
		}

		last = box;
	} );
} )();
