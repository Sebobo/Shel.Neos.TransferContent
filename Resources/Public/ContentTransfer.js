;(function () {
  function init() {
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
