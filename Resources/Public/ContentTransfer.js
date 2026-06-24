;(() => {
    'use strict';

    /**
     * @typedef {{ title: string, message: string, severity: string }} FlashMessage
     */

    const renderFlashMessages = () => {
        /** @type {FlashMessage[] | undefined} */
        const messages = window.flashMessagesData;
        if (messages?.length) {
            messages.forEach((m) => {
                if (window.NeosCMS?.Notification?.[m.severity]) {
                    window.NeosCMS.Notification[m.severity](m.title || m.message, m.message || '');
                }
            });
        }
    };

    const init = () => {
        renderFlashMessages();

        document.querySelectorAll('[data-auto-submit]').forEach((el) => {
            el.addEventListener('change', function () {
                if (this.matches('.ct-dimension-select')) {
                    /** @type {HTMLElement | null} */
                    const group = this.closest('.ct-dimension-group');
                    if (group) {
                        /** @type {HTMLInputElement | null} */
                        const hiddenInput = group.querySelector('[data-dimension-values]');
                        if (hiddenInput) {
                            /** @type {Record<string, string>} */
                            const values = {};
                            group.querySelectorAll('.ct-dimension-select').forEach((select) => {
                                values[select.getAttribute('data-dimension-id')] = select.value;
                            });
                            hiddenInput.value = JSON.stringify(values);
                        }
                    }
                }
                this.form.submit();
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
