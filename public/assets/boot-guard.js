/**
 * quickinfo – Startwächter.
 *
 * Wird vor app.js geladen. Stirbt das Hauptskript beim Start (z. B. weil der
 * Browser Website-Daten blockiert oder ein veraltetes Skript geladen wurde),
 * bleibt die Seite sonst komplett leer. Stattdessen wird der Hinweis
 * #boot-error eingeblendet.
 */
(function () {
  'use strict';

  function show() {
    if (document.documentElement.dataset.booted === '1') return;
    const el = document.getElementById('boot-error');
    if (el) el.hidden = false;
  }

  window.addEventListener('error', function (event) {
    // Fehlgeschlagene Ressourcen (Bilder o. Ä.) sind kein Startfehler.
    if (!(event instanceof ErrorEvent)) return;
    show();
  }, true);

  window.addEventListener('unhandledrejection', show);
})();
