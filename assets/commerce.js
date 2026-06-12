/* Gratis Commerce — WooCommerce enhancements front-end controller */
( function () {
    'use strict';

    var cfg = window.GratisCommerce || {};

    function post( action, data ) {
        var body = new URLSearchParams();
        body.append( 'action', action );
        body.append( 'nonce', cfg.nonce || '' );
        Object.keys( data || {} ).forEach( function ( key ) {
            var value = data[ key ];
            if ( Array.isArray( value ) ) {
                value.forEach( function ( item ) {
                    body.append( key + '[]', item );
                } );
            } else if ( value && typeof value === 'object' ) {
                Object.keys( value ).forEach( function ( taxonomy ) {
                    ( value[ taxonomy ] || [] ).forEach( function ( slug ) {
                        body.append( key + '[' + taxonomy + '][]', slug );
                    } );
                } );
            } else {
                body.append( key, value );
            }
        } );

        return fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        } ).then( function ( res ) {
            return res.json();
        } );
    }

    /* ---- Quick view ---- */
    var modal = document.getElementById( 'gratis-qv-modal' );
    var lastFocus = null;

    function openModal( html ) {
        if ( ! modal ) {
            return;
        }
        modal.querySelector( '.gratis-qv-modal__content' ).innerHTML = html;
        modal.hidden = false;
        lastFocus = document.activeElement;
        var dialog = modal.querySelector( '.gratis-qv-modal__dialog' );
        if ( dialog ) {
            dialog.focus();
        }
    }

    function closeModal() {
        if ( ! modal ) {
            return;
        }
        modal.hidden = true;
        modal.querySelector( '.gratis-qv-modal__content' ).innerHTML = '';
        if ( lastFocus && typeof lastFocus.focus === 'function' ) {
            lastFocus.focus();
        }
    }

    if ( modal ) {
        modal.addEventListener( 'click', function ( e ) {
            if ( e.target.hasAttribute( 'data-gratis-qv-close' ) ) {
                closeModal();
            }
        } );
        document.addEventListener( 'keydown', function ( e ) {
            if ( 'Escape' === e.key && ! modal.hidden ) {
                closeModal();
            }
        } );
    }

    /* ---- Delegated click handling ---- */
    document.addEventListener( 'click', function ( e ) {
        var qv = e.target.closest( '.gratis-quick-view-btn' );
        if ( qv ) {
            e.preventDefault();
            post( 'gratis_commerce_quick_view', { product: qv.getAttribute( 'data-product' ) } ).then( function ( r ) {
                if ( r && r.success ) {
                    openModal( r.data.html );
                }
            } );
            return;
        }

        var wish = e.target.closest( '.gratis-wishlist-btn' );
        if ( wish ) {
            e.preventDefault();
            post( 'gratis_commerce_wishlist_toggle', { product: wish.getAttribute( 'data-product' ) } ).then( function ( r ) {
                if ( r && r.success ) {
                    wish.classList.toggle( 'is-active', r.data.active );
                    wish.setAttribute( 'aria-pressed', r.data.active ? 'true' : 'false' );
                }
            } );
            return;
        }

        var cmp = e.target.closest( '.gratis-compare-btn' );
        if ( cmp ) {
            e.preventDefault();
            post( 'gratis_commerce_compare_toggle', { product: cmp.getAttribute( 'data-product' ) } ).then( function ( r ) {
                if ( r && r.success ) {
                    cmp.classList.toggle( 'is-active', r.data.active );
                    cmp.setAttribute( 'aria-pressed', r.data.active ? 'true' : 'false' );
                } else if ( r && r.data && r.data.full ) {
                    window.alert( ( cfg.i18n && cfg.i18n.compareFull ) || r.data.message );
                }
            } );
        }
    } );

    /* ---- AJAX attribute filtering ---- */
    document.querySelectorAll( '.gratis-filter' ).forEach( function ( panel ) {
        var form = panel.querySelector( '.gratis-filter__form' );
        var results = panel.querySelector( '.gratis-filter__results' );
        if ( ! form || ! results ) {
            return;
        }

        form.addEventListener( 'change', function () {
            var attributes = {};
            form.querySelectorAll( 'input[name^="filter_attributes"]:checked' ).forEach( function ( input ) {
                var match = input.name.match( /filter_attributes\[([^\]]+)\]/ );
                if ( ! match ) {
                    return;
                }
                var tax = match[ 1 ];
                attributes[ tax ] = attributes[ tax ] || [];
                attributes[ tax ].push( input.value );
            } );

            var inStock = form.querySelector( 'input[name="filter_in_stock"]:checked' ) ? 1 : 0;

            results.classList.add( 'is-loading' );
            post( 'gratis_commerce_filter', {
                attributes: attributes,
                in_stock: inStock,
                per_page: panel.getAttribute( 'data-per-page' ) || 12
            } ).then( function ( r ) {
                results.classList.remove( 'is-loading' );
                if ( r && r.success ) {
                    results.innerHTML = r.data.html;
                }
            } ).catch( function () {
                results.classList.remove( 'is-loading' );
            } );
        } );
    } );
}() );
