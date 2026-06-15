document.querySelectorAll('[data-auto-submit]').forEach(function(el) {
  el.addEventListener('change', function() {
    this.form.submit();
  });
});
