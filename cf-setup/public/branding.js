// Gewähltes Bild sofort hochladen: Formular ohne «Weiter» absenden, die Seite zeigt danach die Vorschau.
(function () {
  ['logo_datei', 'hintergrund_datei', 'logo_url', 'hintergrund_url'].forEach(function (id) {
    var e = document.getElementById(id);
    if (!e) { return; }
    e.addEventListener('change', function () {
      if (e.type !== 'file' && e.value.trim() === '') { return; }
      var f = e.form, h = document.createElement('input');
      h.type = 'hidden'; h.name = 'nav'; h.value = 'test';
      f.appendChild(h);
      f.submit();
    });
  });
})();
