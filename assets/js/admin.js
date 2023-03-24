'use strict';

/**
 * Generate a unique UUID to use for the token
 *
 * @link https://stackoverflow.com/a/2117523/1069914
 *
 * @returns A random UUID
 */
const generateUUID = () => {
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
};

/**
 * Generate a new token that can be used to download premium plugins
 */
const createNewTokenListener = () => {
    let generate_button = document.querySelector( '#cshp-generate-key' );
    let current_token = document.querySelector( '#cshp-token' );
    let delete_button = document.querySelector( '#cshp-delete-key' );

    if ( generate_button && current_token ) {
        generate_button.addEventListener( 'click', ( event ) => {
            if ( '' === current_token.value.trim() ) {
                current_token.value = generateUUID();
            } else if ( true === confirm( 'Generate a new token? WARNING: Old token will be deleted' ) ) {
                current_token.value = generateUUID();
            }
        } );
    }

    if ( delete_button ) {
        delete_button.addEventListener( 'click', ( event ) => {
            if ( true === confirm( 'Delete token? WARNING: You will not be able to download the plugins if the token is deleted and a new one is not generated' ) ) {
                current_token.value = '';
            }
        } );
    }
}

/**
 * Check if we can copy to the clipboard using the navigator API
 *
 * @param string str Value to copy to the clipboard
 * @returns {Promise<never>|Promise<void>}
 */
const copyToClipboard = ( str ) => {
    if ( navigator && navigator.clipboard && navigator.clipboard.writeText ) {
        return navigator.clipboard.writeText( str );
    }

    return Promise.reject('The Clipboard API is not available.');
};

/**
 * Copy the tokens to the clipboard if the user wants to copy
 */
const copyTokenToClipboardListener = () => {
    let copy_buttons = document.querySelectorAll( '.copy-button' );

    copy_buttons.forEach( ( copy_button ) => {
        copy_button.addEventListener( 'click', ( event ) => {
            let button = event.target;
            let text = '';
            // look for different types of inputs
            let element = document.querySelector( `#${ button.dataset.copy }, 
                input[name="${ button.dataset.copy }"], 
                select[name="${ button.dataset.copy }"], 
                textarea[name="${ button.dataset.copy }"]
                .${ button.dataset.copy }` );

            if ( element ) {
                if ( element.value ) {
                    text = element.value;
                } else if ( element.innerHTML ) {
                    text = element.innerHTML;
                }

                copyToClipboard( text );
            }
        } );
    } );
}

document.addEventListener( 'readystatechange', ( event ) => {
    if ( 'interactive' === event.target.readyState ) {
        createNewTokenListener();
        copyTokenToClipboardListener();
        if ( 'log' === cshp_pt.tab ) {
            let dataTable = new simpleDatatables.DataTable( '#cshpt-log', {
                searchable: true,
                fixedHeight: true,
            } );
        }
    }
} );