/* Interacciones generales del sistema */
(function () {
  'use strict';

  // Menú lateral responsive
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('#menuBtn');
    var side = document.getElementById('sidebar');
    if (btn && side) side.classList.toggle('open');
    else if (side && !e.target.closest('.sidebar')) side.classList.remove('open');
  });

  // Confirmación para operaciones sensibles
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-confirm]');
    if (a && a.tagName === 'A' && !window.confirm(a.getAttribute('data-confirm'))) e.preventDefault();
  });

  // Marcar notificación como leída al abrir el enlace
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-notif-id]');
    if (!a) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    fetch((window.BASE_URL || '/colegio/') + 'notificaciones/marcar_leida.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': meta ? meta.content : ''
      },
      body: 'id=' + encodeURIComponent(a.getAttribute('data-notif-id'))
    });
  });

  // Filtro rápido de tablas
  document.addEventListener('input', function (e) {
    var input = e.target.closest('[data-filter]');
    if (!input) return;
    var target = document.querySelector(input.getAttribute('data-filter'));
    if (!target) return;
    var q = input.value.toLowerCase();
    target.querySelectorAll('tbody tr').forEach(function (tr) {
      tr.style.display = tr.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
    });
  });

  // Mostrar/ocultar contraseña
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-toggle-pass]');
    if (!b) return;
    var input = document.querySelector(b.getAttribute('data-toggle-pass'));
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    b.textContent = input.type === 'password' ? 'Mostrar' : 'Ocultar';
  });
})();
