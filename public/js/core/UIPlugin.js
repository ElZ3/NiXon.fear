/**
 * public/js/core/UIPlugin.js
 * NiXon.fear — HUD gráfico como Phaser.Plugins.ScenePlugin.
 *
 * Se registra en el arranque del juego (ver level1.js -> config.plugins.scene)
 * y queda disponible en cada escena como `this.ui` (o `this[pluginKey]`).
 * No usa Atlas de texturas: toda la decoración vive horneada en una única
 * imagen estática (`window_frame`), fijada a la cámara con setScrollFactor(0).
 * Los valores dinámicos (paranoia, temporizador, objetivo, progreso) se
 * dibujan encima con Text y Graphics, en coordenadas calculadas como
 * fracciones del tamaño de `window_frame`.
 *
 * Uso desde una escena:
 *   preload() { this.load.image('window_frame', '.../window_frame.png'); }
 *   create()  {
 *     this.ui.createHUD({ frameKey: 'window_frame', timeLimitSeconds: 300 });
 *     this.ui.updateProgress(40);
 *   }
 */

const HUD_DEPTH = 2000; // por encima de cualquier elemento de juego/atmósfera

// Coordenadas de los elementos dinámicos, como fracción del ancho/alto de
// `window_frame` (0..1). No hay valores de atlas que sincronizar: ajustar
// aquí si cambia la composición del arte del marco.
const LAYOUT = {
  paranoiaBar:   { x0: 0.086, x1: 0.278, y0: 0.062, y1: 0.090 },
  paranoiaText:  { x: 0.182, y: 0.076 },
  timerText:     { x: 0.930, y: 0.098 },
  objectiveText: { x: 0.795, y: 0.205, wrapWidth: 300 },
  checkboxText:  { x: 0.975, y: 0.205 },
  progressBar:   { x0: 0.006, x1: 0.994, y0: 0.948, y1: 0.980 },
  progressText:  { x: 0.5, y: 0.964 }
};

export default class UIPlugin extends Phaser.Plugins.ScenePlugin {
  /**
   * @param {Phaser.Scene} scene
   * @param {Phaser.Plugins.PluginManager} pluginManager
   * @param {string} pluginKey
   */
  constructor(scene, pluginManager, pluginKey) {
    super(scene, pluginManager, pluginKey);
    this.scene = scene;
    this.systems = scene.sys;
    this._built = false;
  }

  /** Llamado automáticamente por Phaser cuando la escena arranca sus sistemas. */
  boot() {
    const eventEmitter = this.systems.events;
    eventEmitter.on('shutdown', this.shutdown, this);
    eventEmitter.on('destroy', this.destroy, this);
  }

  // ---------------------------------------------------------------
  // CONSTRUCCIÓN DEL HUD (invocar manualmente desde create(), una
  // vez que los assets ya están cargados)
  // ---------------------------------------------------------------

  /**
   * @param {object} opts
   * @param {string} opts.frameKey         Clave de la imagen estática del HUD ('window_frame').
   * @param {number} opts.timeLimitSeconds Duración total del temporizador.
   */
  createHUD(opts = {}) {
    if (this._built) return; // evita duplicar el HUD si create() se llama más de una vez

    this.frameKey = opts.frameKey || 'window_frame';
    this.timeLimitSeconds = opts.timeLimitSeconds || 300;

    this.buildFrame();
    this.buildParanoiaBlock();
    this.buildTimerBlock();
    this.buildProgressBlock();

    this.layout();
    this._onResize = () => this.layout();
    this.scene.scale.on(Phaser.Scale.Events.RESIZE, this._onResize);

    this._built = true;
  }

  buildFrame() {
    this.frameImg = this.scene.add.image(0, 0, this.frameKey)
      .setOrigin(0, 0)
      .setScrollFactor(0)
      .setDepth(HUD_DEPTH);
  }

  /** Barra de paranoia (Graphics) + porcentaje, sobre el círculo ya dibujado en window_frame. */
  buildParanoiaBlock() {
    this.paranoiaBar = this.scene.add.graphics().setScrollFactor(0).setDepth(HUD_DEPTH + 1);

    this.paranoiaPercentText = this.scene.add.text(0, 0, '0%', {
      fontFamily: 'VT323, monospace', fontSize: '16px', color: '#ffffff'
    }).setOrigin(0.5).setScrollFactor(0).setDepth(HUD_DEPTH + 2);
  }

  /** Temporizador digital + etiqueta/estado del objetivo actual. */
  buildTimerBlock() {
    this.timerText = this.scene.add.text(0, 0, '5:00', {
      fontFamily: 'VT323, monospace', fontSize: '28px', color: '#33ff66'
    }).setOrigin(0.5).setScrollFactor(0).setDepth(HUD_DEPTH + 1);

    this.objectiveText = this.scene.add.text(0, 0, '', {
      fontFamily: 'VT323, monospace', fontSize: '14px', color: '#e8e4d8',
      wordWrap: { width: LAYOUT.objectiveText.wrapWidth }
    }).setOrigin(0, 0.5).setScrollFactor(0).setDepth(HUD_DEPTH + 1);

    // Checkbox como glifo de texto (☐ / ☑); no requiere frames de atlas.
    this.checkboxText = this.scene.add.text(0, 0, '☐', {
      fontFamily: 'monospace', fontSize: '22px', color: '#e8e4d8'
    }).setOrigin(0.5).setScrollFactor(0).setDepth(HUD_DEPTH + 1);
  }

  /** Objeto Graphics que sirve como barra de progreso del nivel. */
  buildProgressBlock() {
    this.progressBar = new Phaser.GameObjects.Graphics(this.scene);
    this.scene.add.existing(this.progressBar);
    this.progressBar.setScrollFactor(0).setDepth(HUD_DEPTH + 1);

    this.progressPercentText = this.scene.add.text(0, 0, '0%', {
      fontFamily: 'VT323, monospace', fontSize: '14px', color: '#ffffff'
    }).setOrigin(0.5).setScrollFactor(0).setDepth(HUD_DEPTH + 2);

    this._progressPct = 0;
    this._paranoiaPct = 0;
  }

  // ---------------------------------------------------------------
  // LAYOUT / ANCLAJE A BORDES (recalculado en cada resize)
  // ---------------------------------------------------------------

  layout() {
    if (!this._built && !this.frameImg) return;

    const W = this.scene.scale.width;
    const H = this.scene.scale.height;

    this.frameImg.setDisplaySize(W, H);
    this.frameW = this.frameImg.displayWidth;
    this.frameH = this.frameImg.displayHeight;
    this.frameX = this.frameImg.x;
    this.frameY = this.frameImg.y;

    this.paranoiaPercentText.setPosition(
      this.frameX + LAYOUT.paranoiaText.x * this.frameW,
      this.frameY + LAYOUT.paranoiaText.y * this.frameH
    );
    this.timerText.setPosition(
      this.frameX + LAYOUT.timerText.x * this.frameW,
      this.frameY + LAYOUT.timerText.y * this.frameH
    );
    this.objectiveText.setPosition(
      this.frameX + LAYOUT.objectiveText.x * this.frameW,
      this.frameY + LAYOUT.objectiveText.y * this.frameH
    );
    this.checkboxText.setPosition(
      this.frameX + LAYOUT.checkboxText.x * this.frameW,
      this.frameY + LAYOUT.checkboxText.y * this.frameH
    );
    this.progressPercentText.setPosition(
      this.frameX + LAYOUT.progressText.x * this.frameW,
      this.frameY + LAYOUT.progressText.y * this.frameH
    );

    // Redibuja con el último valor conocido si el canvas cambió de tamaño a mitad de partida.
    this.updateParanoia(this._paranoiaPct);
    this.updateProgress(this._progressPct);
  }

  // ---------------------------------------------------------------
  // API PÚBLICA
  // ---------------------------------------------------------------

  /** @param {number} pct 0-100 */
  updateParanoia(pct) {
    const clamped = Phaser.Math.Clamp(pct, 0, 100);
    this._paranoiaPct = clamped;

    const L = LAYOUT.paranoiaBar;
    const x = this.frameX + L.x0 * this.frameW;
    const y = this.frameY + L.y0 * this.frameH;
    const maxWidth = (L.x1 - L.x0) * this.frameW;
    const height = (L.y1 - L.y0) * this.frameH;

    this.paranoiaBar.clear();
    this.paranoiaBar.fillStyle(0x8a0000, 1);
    this.paranoiaBar.fillRect(x, y, Math.max(1, maxWidth * (clamped / 100)), height);

    this.paranoiaPercentText.setText(`${Math.round(clamped)}%`);
  }

  /** @param {number} secondsRemaining */
  updateTimer(secondsRemaining) {
    const m = Math.floor(secondsRemaining / 60);
    const s = secondsRemaining % 60;
    this.timerText.setText(`${m}:${String(s).padStart(2, '0')}`);
    this.timerText.setColor(secondsRemaining <= 30 ? '#ff4466' : '#33ff66');
  }

  /** @param {string} text @param {boolean} done */
  updateObjective(text, done = false) {
    this.objectiveText.setText(text);
    this.checkboxText.setText(done ? '☑' : '☐');
    this.checkboxText.setColor(done ? '#33ff66' : '#e8e4d8');
  }

  /**
   * Limpia (`clear()`) y vuelve a dibujar (`fillRect()`) el relleno de la
   * barra de progreso, con coordenadas calculadas respecto a `window_frame`.
   * @param {number} percentage 0-100
   */
  updateProgress(percentage) {
    const clamped = Phaser.Math.Clamp(percentage, 0, 100);
    this._progressPct = clamped;

    const L = LAYOUT.progressBar;
    const x = this.frameX + L.x0 * this.frameW;
    const y = this.frameY + L.y0 * this.frameH;
    const maxWidth = (L.x1 - L.x0) * this.frameW;
    const height = (L.y1 - L.y0) * this.frameH;

    this.progressBar.clear();
    this.progressBar.fillStyle(0x5c6b3a, 1); // verde oliva, a tono con el marco industrial
    this.progressBar.fillRect(x, y, Math.max(1, maxWidth * (clamped / 100)), height);

    this.progressPercentText.setText(`${Math.round(clamped)}%`);
  }

  /** Parpadeo breve como refuerzo visual de penalización de paranoia. */
  flashParanoiaWarning() {
    this.scene.tweens.add({
      targets: [this.paranoiaBar, this.paranoiaPercentText],
      alpha: { from: 1, to: 0.3 },
      duration: 90,
      yoyo: true,
      repeat: 2
    });
  }

  // ---------------------------------------------------------------
  // CICLO DE VIDA DEL PLUGIN
  // ---------------------------------------------------------------

  /** Llamado por Phaser en el shutdown de la escena (p. ej. al cambiar de escena). */
  shutdown() {
    if (!this._built) return;

    this.scene.scale.off(Phaser.Scale.Events.RESIZE, this._onResize);
    this.frameImg?.destroy();
    this.paranoiaBar?.destroy();
    this.paranoiaPercentText?.destroy();
    this.timerText?.destroy();
    this.objectiveText?.destroy();
    this.checkboxText?.destroy();
    this.progressBar?.destroy();
    this.progressPercentText?.destroy();

    this._built = false;
  }

  /** Llamado por Phaser al destruir la escena por completo. */
  destroy() {
    this.shutdown();
    super.destroy();
  }
}
