;(function () {
  function renderFlashMessages() {
    var messages = window.flashMessagesData;
    if (messages && messages.length) {
      messages.forEach(function(m) {
        if (window.NeosCMS && window.NeosCMS.Notification && window.NeosCMS.Notification[m.severity]) {
          window.NeosCMS.Notification[m.severity](m.title || m.message, m.message || '');
        }
      });
    }
  }

  function init() {
    renderFlashMessages();

    document.querySelectorAll('[data-auto-submit]').forEach(function(el) {
      el.addEventListener('change', function() {
        if (this.matches('.ct-dimension-select')) {
          var group = this.closest('.ct-dimension-group');
          if (group) {
            var hiddenInput = group.querySelector('[data-dimension-values]');
            if (hiddenInput) {
              var values = {};
              group.querySelectorAll('.ct-dimension-select').forEach(function(select) {
                values[select.getAttribute('data-dimension-id')] = select.value;
              });
              hiddenInput.value = JSON.stringify(values);
            }
          }
        }
        this.form.submit();
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
