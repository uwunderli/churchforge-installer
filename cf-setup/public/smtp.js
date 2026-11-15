// Port folgt der Verschlüsselung (STARTTLS 587, SSL/TLS 465, keine 25).
(function () {
  var s = document.getElementById('ssicher'), p = document.getElementById('sport');
  if (!s || !p) { return; }
  var port = { starttls: '587', ssl: '465', none: '25' };
  s.addEventListener('change', function () { if (port[s.value]) { p.value = port[s.value]; } });
})();
