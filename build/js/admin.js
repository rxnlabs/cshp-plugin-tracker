(function () {
    'use strict';

    class Admin {
        /**
         * Generate a unique UUID.
         *
         * @link https://stackoverflow.com/a/2117523/1069914
         * @returns {string} A random UUID
         */ generateUUID() {
            return [
                1e7
            ].toString() + '-1e3-4e3-8e3-1e11'.replace(/[018]/g, (c)=>(+c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> +c / 4).toString(16));
        }
        /**
         * Add event listeners to handle generating and deleting tokens.
         */ createNewTokenListener() {
            const generateButton = document.querySelector('#cshp-generate-key');
            const currentTokenInput = document.querySelector('#cshp-token');
            const deleteButton = document.querySelector('#cshp-delete-key');
            if (generateButton && currentTokenInput) {
                generateButton.addEventListener('click', ()=>{
                    if (currentTokenInput.value.trim() === '') {
                        currentTokenInput.value = this.generateUUID();
                    } else if (confirm('Generate a new token? WARNING: Old token will be deleted')) {
                        currentTokenInput.value = this.generateUUID();
                    }
                });
            }
            if (deleteButton) {
                deleteButton.addEventListener('click', ()=>{
                    if (confirm('Delete token? WARNING: You will not be able to download the plugins if the token is deleted and a new one is not generated')) {
                        if (currentTokenInput) {
                            currentTokenInput.value = '';
                        }
                    }
                });
            }
        }
        /**
         * Copies a given text to the clipboard using the Clipboard API.
         *
         * @param text The value to copy to the clipboard.
         * @returns {Promise<void>} A promise for the clipboard operation.
         */ copyToClipboard(text) {
            if (navigator && navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            return Promise.reject(new Error('The Clipboard API is not available.'));
        }
        /**
         * Add event listeners to copy buttons for copying to the clipboard.
         */ copyTokenToClipboardListener() {
            const copyButtons = document.querySelectorAll('.copy-button');
            copyButtons.forEach((copyButton)=>{
                copyButton.addEventListener('click', (event)=>{
                    var _a;
                    const button = event.target;
                    const dataCopy = button.dataset.copy;
                    if (dataCopy) {
                        const selector = `#${dataCopy}, input[name="${dataCopy}"], select[name="${dataCopy}"], textarea[name="${dataCopy}"], .${dataCopy}`;
                        const element = document.querySelector(selector);
                        if (element) {
                            const text = element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement ? element.value : ((_a = element.textContent) === null || _a === void 0 ? void 0 : _a.trim()) || '';
                            if (text) {
                                this.copyToClipboard(text).catch((error)=>console.error(error));
                            }
                        }
                    }
                });
            });
        }
        /**
         * Initializes a data table for a given table selector.
         *
         * @param selector The CSS selector for the table element.
         */ initializeDataTable(selector) {
            // @ts-ignore: simpleDataTables is used directly in the browser from a third-party library
            new simpleDatatables.DataTable(selector, {
                searchable: true,
                fixedHeight: true
            });
        }
        constructor(){
            // Set up event listeners when the document is ready.
            document.addEventListener('readystatechange', ()=>{
                var _a;
                if (document.readyState === 'interactive') {
                    this.createNewTokenListener();
                    this.copyTokenToClipboardListener();
                    if (((_a = window.cshp_pt) === null || _a === void 0 ? void 0 : _a.tab) === 'log') {
                        this.initializeDataTable('#cshpt-log');
                    }
                }
            });
        }
    }
    new Admin();

})();
//# sourceMappingURL=admin.js.map
