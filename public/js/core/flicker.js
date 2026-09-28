/**
 * public/js/core/flicker.js
 * NiXon.fear — Simula hardware dañado: variaciones aleatorias de opacidad y
 * pequeños saltos de posición (transform: translate) en el contenedor
 * principal de la página. Script plano (sin módulos) pensado para
 * incluirse con <script src="..." defer></script> en cualquier vista.
 *
 * Uso:
 *   <div data-flicker> ... contenido de la página ... </div>
 *   <script src="assets/js/core/flicker.js" defer></script>
 *
 * Si no existe ningún elemento [data-flicker] en la página, el efecto se
 * aplica sobre <body> como respaldo.
 */

(function () {
  'use strict';

  const CONFIG = {
    minIntervalMs: 2200,
    maxIntervalMs: 6000,
    burstDurationMs: [70, 160],
    maxTranslatePx: 3,
    minOpacity: 0.82,
    maxOpacity: 1,
    doubleGlitchChance: 0.25 // probabilidad de un segundo "salto" inmediato
  };

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function randomBetween(min, max) {
    return Math.random() * (max - min) + min;
  }

  function randomInt(min, max) {
    return Math.floor(randomBetween(min, max + 1));
  }

  function applyGlitchFrame(el) {
    const tx = randomInt(-CONFIG.maxTranslatePx, CONFIG.maxTranslatePx);
    const ty = randomInt(-CONFIG.maxTranslatePx, CONFIG.maxTranslatePx);
    const opacity = randomBetween(CONFIG.minOpacity, CONFIG.maxOpacity).toFixed(2);

    el.style.transform = `translate(${tx}px, ${ty}px)`;
    el.style.opacity = opacity;
  }

  function resetFrame(el) {
    el.style.transform = 'translate(0, 0)';
    el.style.opacity = '1';
  }

  function glitchBurst(el) {
    const duration = randomInt(CONFIG.burstDurationMs[0], CONFIG.burstDurationMs[1]);

    applyGlitchFrame(el);

    setTimeout(() => {
      resetFrame(el);

      if (Math.random() < CONFIG.doubleGlitchChance) {
        setTimeout(() => glitchBurst(el), randomInt(40, 120));
      }
    }, duration);
  }

  function scheduleNextTick(el) {
    const delay = randomInt(CONFIG.minIntervalMs, CONFIG.maxIntervalMs);

    setTimeout(() => {
      glitchBurst(el);
      scheduleNextTick(el);
    }, delay);
  }

  function initFlicker(target) {
    if (!target) return;

    target.style.willChange = 'transform, opacity';
    resetFrame(target);
    scheduleNextTick(target);
  }

  function boot() {
    if (prefersReducedMotion()) return; // respeta la preferencia de accesibilidad

    const target = document.querySelector('[data-flicker]') || document.body;
    initFlicker(target);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();