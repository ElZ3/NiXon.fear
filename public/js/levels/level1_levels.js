/**
 * public/js/levels/level1.js
 * NiXon.fear — Nivel 1: "LA OFICINA DESIERTA"
 */

import UIPlugin from '../core/UIPlugin.js';

const MAP_KEY = 'map_level1';
const MAP_JSON_PATH = '../assets/maps/room_level1.json';
const TILESET_NAME = 'level1_utilerias';
const TILESET_KEY = 'tiles_utilerias';
const TILESET_IMG_PATH = '../assets/maps/level1_utilerias.png';

const HUD_FRAME_KEY = 'window_frame';
const HUD_FRAME_PATH = '../assets/images/window_frame.png';

const PLAYER_WALK_KEY = 'player';
const PLAYER_WALK_PATH = '../assets/images/player_detective.png';
const PLAYER_DEAD_KEY = 'player_dead';
const PLAYER_DEAD_PATH = '../assets/images/dead_player.png';
const PLAYER_FRAME_WIDTH = 84;
const PLAYER_FRAME_HEIGHT = 124;

const PROGRESS_ORDER = ['Documento1', 'Archivero', 'Documento 2', 'Computadora', 'Cajafuerte'];

const CLUE_TEXT = {
  'Documento1': 'Una fotografía de un edificio: "4G — donde empezó todo." Junto a ella, una nota: "No usar nombres completos."',
  'Archivero': 'Hoja de inventario: EMPLEADO ELIAS VALE / EXTENSIÓN 411 / TERMINAL G4-11. Bajo el teclado, otra nota: "Formato antiguo: inicial + fecha + año."',
  'Documento 2': 'Registro de procesos: MONITOR.EXE no corresponde a un proceso habitual. "Si el teclado parece adelantarse a usted, no es el teclado."',
  'Computadora': 'C:> whoami — EVALE. Una ventana de texto desaparece sola. "Alguien ya estuvo buscando antes que yo."',
  'Cajafuerte': 'INCIDENT 4G-031. STATUS: DECEASED. CAUSE: ██████████. "El sistema no está roto. Está haciendo exactamente lo que le ordenaron."'
};

const OBJECTIVE_LABELS = {
  'Documento1': 'REVISAR EL DOCUMENTO SOBRE EL ESCRITORIO',
  'Archivero': 'REGISTRAR EL ARCHIVERO',
  'Documento 2': 'LEER EL SEGUNDO DOCUMENTO',
  'Computadora': 'ACCEDER A LA COMPUTADORA',
  'Cajafuerte': 'ABRIR LA CAJA FUERTE (COMBINACIÓN)'
};

const WRONG_ORDER_MESSAGES = ['Falta información.', 'Aún no tiene sentido.'];

const TIME_LIMIT_SECONDS = 5 * 60;
const PARANOIA_MAX = 100;
const PARANOIA_PASSIVE_PER_TICK = 0.12;
const PARANOIA_WRONG_CLICK_SPIKE = 15;
const WRONG_CLICK_STREAK_WINDOW_MS = 4000;

const PLAYER_SPEED = 170;

const API_ENDPOINT = (window.NIXON && window.NIXON.apiEndpoint) || '../api/validate_step.php';
const CSRF_TOKEN = (window.NIXON && window.NIXON.csrfToken) || '';

class Level1Scene extends Phaser.Scene {
  constructor() {
    super({ key: 'Level1Scene' });
  }

  preload() {
    this.load.tilemapTiledJSON(MAP_KEY, MAP_JSON_PATH);
    this.load.image(TILESET_KEY, TILESET_IMG_PATH);
    this.load.image(HUD_FRAME_KEY, HUD_FRAME_PATH);

    this.load.spritesheet(PLAYER_WALK_KEY, PLAYER_WALK_PATH, {
      frameWidth: PLAYER_FRAME_WIDTH,
      frameHeight: PLAYER_FRAME_HEIGHT
    });
    this.load.spritesheet(PLAYER_DEAD_KEY, PLAYER_DEAD_PATH, {
      frameWidth: PLAYER_FRAME_WIDTH,
      frameHeight: PLAYER_FRAME_HEIGHT
    });

    this.load.on('loaderror', (file) => {
      console.error(`[Level1Scene] Error cargando asset: ${file.key} (${file.url})`);
    });
  }

  create() {
    this.progresoNivel = 0;
    this.paranoia = 0;
    this.secondsRemaining = TIME_LIMIT_SECONDS;
    this.controlsLocked = false;
    this.modalOpen = false;
    this.gameOverTriggered = false;
    this.gameOverFinished = false;
    this.awaitingServer = false;
    this.wrongClickTimestamps = [];

    this.buildMap();
    this.buildPlayer();
    this.buildAtmosphere();
    this.buildInteractiveZones();
    this.bindInput();

    this.ui.createHUD({ frameKey: HUD_FRAME_KEY, timeLimitSeconds: TIME_LIMIT_SECONDS });

    this.buildCluePopup();
    this.startTimers();

    this.ui.updateTimer(this.secondsRemaining);
    this.ui.updateParanoia(this.paranoia);
    this.ui.updateObjective(OBJECTIVE_LABELS[PROGRESS_ORDER[0]], false);
    this.ui.updateProgress(0);
  }

  // ------------------ MAPA / TILED ------------------

  buildMap() {
    const map = this.make.tilemap({ key: MAP_KEY });
    const tileset = map.addTilesetImage(TILESET_NAME, TILESET_KEY);

    if (!tileset) {
      this.showFatal(`ERROR: no se pudo enlazar el tileset "${TILESET_NAME}".`);
      return;
    }

    this.map = map;
    this.layerFloor = this.safeCreateLayer(map, 'Suelo', tileset, 0);
    this.layerDecor = this.safeCreateLayer(map, 'DecoracionesEntorno', tileset, 1);
    this.layerItems = this.safeCreateLayer(map, 'Items', tileset, 2);
    this.layerWalls = this.safeCreateLayer(map, 'Paredes', tileset, 3);

    // Habilita colisiones en todos los tiles no vacíos de la capa Paredes
    if (this.layerWalls) {
      this.layerWalls.setCollisionByExclusion([0, -1]);
    }

    this.physics.world.setBounds(0, 0, map.widthInPixels, map.heightInPixels);
    this.cameras.main.setBounds(0, 0, map.widthInPixels, map.heightInPixels);
  }

  safeCreateLayer(map, name, tileset, depth) {
    const layer = map.createLayer(name, tileset, 0, 0);
    if (!layer) {
      console.warn(`[Level1Scene] Capa no encontrada en Tiled: "${name}"`);
      return null;
    }
    layer.setDepth(depth);
    return layer;
  }

  /**
   * Recopila todos los objetos pertenecientes a cualquier capa de tipo "objectgroup"
   */
  getAllMapObjects() {
    if (!this.map || !this.map.objects) return [];
    let allObjects = [];
    for (const group of this.map.objects) {
      if (group.objects && Array.isArray(group.objects)) {
        allObjects = allObjects.concat(group.objects);
      }
    }
    return allObjects;
  }

  // ------------------ JUGADOR ------------------

  buildPlayer() {
    if (!this.physics || !this.physics.add) {
      this.showFatal('ERROR: Arcade Physics no está habilitado.');
      return;
    }

    const spawn = this.getSpawnPoint();
    this.player = this.physics.add.sprite(spawn.x, spawn.y, PLAYER_WALK_KEY, 0);
    this.player.setDepth(5);

    const body = this.player.body;
    if (!body) {
      this.showFatal('ERROR: no se pudo crear el body físico del jugador.');
      return;
    }

    body.setCollideWorldBounds(true);
    body.setDrag(0);
    body.setMaxVelocity(PLAYER_SPEED, PLAYER_SPEED);

    if (this.layerWalls) {
      this.physics.add.collider(this.player, this.layerWalls);
    }

    this.cameras.main.startFollow(this.player, true, 0.08, 0.08);
    this.createPlayerAnimations();
    this.currentWalkAnim = null;
  }

  createPlayerAnimations() {
    const anims = this.anims;
    const walkTex = this.textures.get(PLAYER_WALK_KEY);
    const walkFrameTotal = walkTex ? walkTex.frameTotal - 1 : 0;

    if (walkFrameTotal < 24) {
      console.warn(
        `[Level1Scene] "${PLAYER_WALK_KEY}" tiene ${walkFrameTotal} frames (${PLAYER_FRAME_WIDTH}x${PLAYER_FRAME_HEIGHT}); se esperaban 24.`
      );
    }

    if (!anims.exists('walk-down')) {
      anims.create({ key: 'walk-down', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 0, end: 3 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('walk-right')) {
      anims.create({ key: 'walk-right', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 4, end: 7 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('walk-left')) {
      anims.create({ key: 'walk-left', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 8, end: 15 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('walk-up')) {
      anims.create({ key: 'walk-up', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 16, end: 23 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('player-collapse')) {
      anims.create({ key: 'player-collapse', frames: anims.generateFrameNumbers(PLAYER_DEAD_KEY, { start: 0, end: 23 }), frameRate: 10, repeat: 0 });
    }
  }

  getSpawnPoint() {
    const allObjects = this.getAllMapObjects();
    const spawnObj = allObjects.find((o) => o.name === 'Spawn');
    // Si no hay punto de Spawn en el JSON, aparece cerca de la puerta de entrada
    return spawnObj ? { x: spawnObj.x, y: spawnObj.y } : { x: 380, y: 120 };
  }

  // ------------------ ATMÓSFERA ------------------

  buildAtmosphere() {
    this.buildFlickeringLights();
    this.buildStaticNoise();
  }

  buildFlickeringLights() {
    const W = this.map ? this.map.widthInPixels : this.scale.width;
    const H = this.map ? this.map.heightInPixels : this.scale.height;
    this.flickerLights = [];

    for (let i = 0; i < 4; i++) {
      const rect = this.add.rectangle(
        Phaser.Math.Between(0, W), Phaser.Math.Between(0, H),
        Phaser.Math.Between(180, 380), Phaser.Math.Between(180, 380),
        0x000000, 0.55
      );
      rect.setBlendMode(Phaser.BlendModes.MULTIPLY);
      rect.setDepth(20);
      this.flickerLights.push(rect);
      this.scheduleFlicker(rect);
    }
  }

  scheduleFlicker(rect) {
    const delay = Phaser.Math.Between(1200, 4200);
    this.time.delayedCall(delay, () => {
      if (!rect.active) return;
      this.tweens.add({
        targets: rect,
        alpha: { from: rect.alpha, to: Phaser.Math.FloatBetween(0.1, 0.75) },
        duration: Phaser.Math.Between(60, 220),
        yoyo: true,
        repeat: Phaser.Math.Between(0, 3),
        onComplete: () => this.scheduleFlicker(rect)
      });
    });
  }

  buildStaticNoise() {
    this.staticGfx = this.add.graphics().setScrollFactor(0).setDepth(999).setAlpha(0.06);
    this.staticAccum = 0;
    this.staticInterval = 90;
    this.redrawStaticNoise(this.scale.width, this.scale.height);
  }

  redrawStaticNoise(W, H) {
    const g = this.staticGfx;
    g.clear();
    for (let i = 0; i < 140; i++) {
      const x = Phaser.Math.Between(0, W);
      const y = Phaser.Math.Between(0, H);
      const s = Phaser.Math.Between(1, 2);
      g.fillStyle(0xffffff, Phaser.Math.FloatBetween(0.05, 0.18));
      g.fillRect(x, y, s, s);
    }
  }

  // ------------------ ZONAS INTERACTIVAS ------------------

  buildInteractiveZones() {
    const allObjects = this.getAllMapObjects();
    this.zones = [];

    for (const group of this.map.objects) {
      const objName = group.name; // Obtiene el nombre desde la capa del objeto
      if (!PROGRESS_ORDER.includes(objName)) continue;

      const obj = group.objects && group.objects[0];
      if (!obj) continue;

      const w = obj.width || 32;
      const h = obj.height || 32;

      // Corrige el origen Y para objetos de Tiled con 'gid'
      const cx = obj.x + w / 2;
      const cy = obj.gid ? obj.y - h / 2 : obj.y + h / 2;

      const zone = this.add.zone(cx, cy, w, h);
      zone.setRectangleDropZone(w, h);
      zone.setInteractive({ useHandCursor: true });
      zone.name = objName;

      const outline = this.add.rectangle(cx, cy, w, h)
        .setStrokeStyle(1, 0x33ff66, 0.25)
        .setDepth(4);

      zone.on('pointerdown', () => this.onZoneClicked(objName));
      this.zones.push({ name: objName, zone, outline });
    }
  }

  onZoneClicked(name) {
    if (this.controlsLocked || this.modalOpen || this.gameOverTriggered || this.awaitingServer) return;
    this.validateStepWithServer(name);
  }

  async validateStepWithServer(stepName) {
    this.awaitingServer = true;

    try {
      const res = await fetch(API_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ step: stepName, csrf_token: CSRF_TOKEN })
      });

      const data = await res.json();
      this.handleServerResponse(stepName, data);
    } catch (err) {
      console.error('[Level1Scene] Fallo al validar paso con el servidor:', err);
      this.showFloatingHint('ERROR DE CONEXIÓN. Reintente.');
    } finally {
      this.awaitingServer = false;
    }
  }

  handleServerResponse(stepName, data) {
    if (!data || typeof data.status !== 'string') {
      this.showFloatingHint('RESPUESTA INVÁLIDA DEL SERVIDOR.');
      return;
    }

    if (data.status !== 'success') {
      this.handleWrongOrderClick();
      return;
    }

    const percent = typeof data.progress === 'number' ? data.progress : 0;
    this.progresoNivel = Math.round((percent / 100) * PROGRESS_ORDER.length);
    this.ui.updateProgress(percent);

    const item = this.zones.find((z) => z.name === stepName);
    if (item) item.outline.setStrokeStyle(1, 0x33ff66, 0.08);

    const completed = percent >= 100;
    const nextStep = PROGRESS_ORDER[this.progresoNivel];
    this.ui.updateObjective(
      completed ? 'INVESTIGACIÓN COMPLETA' : OBJECTIVE_LABELS[nextStep],
      completed
    );

    this.openCluePopup(stepName, completed);
  }

  handleWrongOrderClick() {
    const msg = Phaser.Utils.Array.GetRandom(WRONG_ORDER_MESSAGES);
    this.showFloatingHint(msg);

    const now = Date.now();
    this.wrongClickTimestamps = this.wrongClickTimestamps.filter((t) => now - t < WRONG_CLICK_STREAK_WINDOW_MS);
    this.wrongClickTimestamps.push(now);

    if (this.wrongClickTimestamps.length >= 2) {
      this.increaseParanoia(PARANOIA_WRONG_CLICK_SPIKE);
      this.ui.flashParanoiaWarning();
    }
  }

  showFloatingHint(text) {
    if (this.hintText) this.hintText.destroy();

    this.hintText = this.add.text(this.scale.width / 2, this.scale.height - 90, text, {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#ff4466'
    }).setOrigin(0.5).setScrollFactor(0).setDepth(1500);

    this.tweens.add({
      targets: this.hintText,
      alpha: { from: 1, to: 0 },
      delay: 900,
      duration: 500,
      onComplete: () => { this.hintText?.destroy(); this.hintText = null; }
    });
  }

  // ------------------ VENTANA EMERGENTE DE PISTA ------------------

  buildCluePopup() {
    const W = this.scale.width;
    const H = this.scale.height;

    this.cluePopup = this.add.container(W / 2, H / 2).setScrollFactor(0).setDepth(1800).setVisible(false);

    const bg = this.add.rectangle(0, 0, Math.min(W - 80, 620), 280, 0x0a0a0a, 0.95)
      .setStrokeStyle(2, 0x33ff66, 1);

    this.clueText = this.add.text(0, -60, '', {
      fontFamily: 'VT323, monospace', fontSize: '19px', color: '#e8e4d8',
      align: 'center', wordWrap: { width: Math.min(W - 130, 560) }
    }).setOrigin(0.5);

    const closeBtn = this.add.text(0, 110, '[ CERRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#33ff66'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });

    closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
    closeBtn.on('pointerout', () => closeBtn.setColor('#33ff66'));
    closeBtn.on('pointerdown', () => this.closeCluePopup());

    this.cluePopup.add([bg, this.clueText, closeBtn]);

    this.input.keyboard?.on('keydown-ESC', () => {
      if (this.modalOpen) this.closeCluePopup();
    });
  }

  openCluePopup(stepName, levelCompleted) {
    this.modalOpen = true;
    this.pauseGameplay();
    this._levelCompletedPending = levelCompleted;

    this.clueText.setText(CLUE_TEXT[stepName] || '');
    this.cluePopup.setVisible(true);
    this.cluePopup.setPosition(this.scale.width / 2, this.scale.height / 2);
  }

  closeCluePopup() {
    if (!this.modalOpen) return;

    this.cluePopup.setVisible(false);
    this.modalOpen = false;

    if (this._levelCompletedPending) {
      this.onLevelComplete();
    } else {
      this.resumeGameplay();
    }
  }

  onLevelComplete() {
    this.controlsLocked = true;
    if (this.player?.body) this.player.body.setVelocity(0, 0);

    this.cameras.main.fadeOut(800, 0, 0, 0);
    this.cameras.main.once('camerafadeoutcomplete', () => {
      window.location.href = 'level1_complete.php';
    });
  }

  pauseGameplay() {
    this.controlsLocked = true;
    if (this.player?.body) this.player.body.setVelocity(0, 0);
    this.stopWalkAnim();
  }

  resumeGameplay() {
    if (!this.gameOverTriggered) this.controlsLocked = false;
  }

  // ------------------ INPUT ------------------

  bindInput() {
    const kb = this.input.keyboard;
    if (!kb) return;
    this.cursors = kb.createCursorKeys();
    this.wasd = kb.addKeys({ up: 'W', left: 'A', down: 'S', right: 'D' });
  }

  // ------------------ TEMPORIZADOR Y PARANOIA ------------------

  startTimers() {
    this.time.addEvent({ delay: 1000, loop: true, callback: this.onSecondTick, callbackScope: this });
  }

  onSecondTick() {
    if (this.gameOverTriggered) return;
    if (this.controlsLocked && !this.modalOpen) return;

    if (!this.modalOpen) {
      this.secondsRemaining = Math.max(0, this.secondsRemaining - 1);
      this.increaseParanoia(PARANOIA_PASSIVE_PER_TICK);
    }

    this.ui.updateTimer(this.secondsRemaining);

    if (this.secondsRemaining <= 0) {
      this.triggerGameOver('TIME');
    }
  }

  increaseParanoia(amount) {
    if (this.gameOverTriggered) return;

    this.paranoia = Phaser.Math.Clamp(this.paranoia + amount, 0, PARANOIA_MAX);
    this.ui.updateParanoia(this.paranoia);

    if (this.paranoia >= PARANOIA_MAX) {
      this.triggerGameOver('PARANOIA');
    }
  }

  // ------------------ GAME OVER ------------------

  triggerGameOver(reason) {
    if (this.gameOverTriggered) return;
    this.gameOverTriggered = true;
    this.controlsLocked = true;

    this.cluePopup.setVisible(false);
    this.modalOpen = false;

    if (this.player?.body) this.player.body.setVelocity(0, 0);

    console.warn(`[Level1Scene] Game Over disparado por: ${reason}`);
    this.playCollapseSequence(reason);
  }

  playCollapseSequence(reason) {
    const cam = this.cameras.main;
    const player = this.player;

    cam.shake(1200, 0.015);
    this.startRedLightPulse();

    if (!player) {
      this.time.delayedCall(1200, () => this.finishCollapse(reason));
      return;
    }

    this.stopWalkAnim();
    player.setTexture(PLAYER_DEAD_KEY, 0);
    player.play('player-collapse');

    player.once('animationcomplete-player-collapse', () => this.finishCollapse(reason));

    const collapseDurationMs = (24 / 10) * 1000;
    this.time.delayedCall(collapseDurationMs + 600, () => {
      if (!this.gameOverFinished) this.finishCollapse(reason);
    });
  }

  startRedLightPulse() {
    const W = this.scale.width;
    const H = this.scale.height;

    this.collapseRedOverlay = this.add.rectangle(W / 2, H / 2, W, H, 0xff0000, 0.2)
      .setScrollFactor(0).setDepth(1900).setBlendMode(Phaser.BlendModes.ADD);

    this.collapseRedTween = this.tweens.add({
      targets: this.collapseRedOverlay,
      alpha: { from: 0.08, to: 0.5 },
      duration: 140,
      yoyo: true,
      repeat: -1
    });
  }

  finishCollapse(reason) {
    if (this.gameOverFinished) return;
    this.gameOverFinished = true;

    if (this.collapseRedTween) this.collapseRedTween.stop();
    if (this.collapseRedOverlay) this.collapseRedOverlay.destroy();

    const cam = this.cameras.main;
    cam.fadeOut(1100, 0, 0, 0);
    cam.once('camerafadeoutcomplete', () => {
      const reasonParam = reason === 'TIME' ? 'time' : 'paranoia';
      window.location.href = `gameover.php?reason=${reasonParam}`;
    });
  }

  // ------------------ UPDATE ------------------

  update(time, delta) {
    if (!this.gameOverTriggered) {
      this.handleMovement();
    }

    this.staticAccum += delta;
    if (this.staticAccum >= this.staticInterval) {
      this.staticAccum = 0;
      this.redrawStaticNoise(this.scale.width, this.scale.height);
    }
  }

  handleMovement() {
    if (!this.player || !this.player.body || this.controlsLocked) {
      if (this.player?.body) this.player.body.setVelocity(0, 0);
      return;
    }

    const body = this.player.body;
    const c = this.cursors;
    const w = this.wasd;

    const left  = (c?.left.isDown)  || (w?.left.isDown)  ? 1 : 0;
    const right = (c?.right.isDown) || (w?.right.isDown) ? 1 : 0;
    const up    = (c?.up.isDown)    || (w?.up.isDown)    ? 1 : 0;
    const down  = (c?.down.isDown)  || (w?.down.isDown)  ? 1 : 0;

    const vx = right - left;
    const vy = down - up;

    if (vx === 0 && vy === 0) {
      body.setVelocity(0, 0);
      this.stopWalkAnim();
      return;
    }

    const len = Math.hypot(vx, vy);
    body.setVelocity((vx / len) * PLAYER_SPEED, (vy / len) * PLAYER_SPEED);
    this.playWalkAnim(vx, vy);
  }

  playWalkAnim(vx, vy) {
    let anim;
    if (Math.abs(vx) > Math.abs(vy)) {
      anim = vx > 0 ? 'walk-right' : 'walk-left';
    } else {
      anim = vy > 0 ? 'walk-down' : 'walk-up';
    }
    if (this.currentWalkAnim !== anim) {
      this.currentWalkAnim = anim;
      this.player.play(anim, true);
    }
  }

  stopWalkAnim() {
    if (this.currentWalkAnim) {
      this.currentWalkAnim = null;
      this.player?.anims.stop();
    }
  }

  showFatal(msg) {
    console.error(`[Level1Scene] ${msg}`);
    this.add.text(20, 20, msg, {
      fontFamily: 'monospace', fontSize: '14px', color: '#ff4466',
      wordWrap: { width: this.scale.width - 40 }
    }).setDepth(9999).setScrollFactor(0);
  }
}

// ---------------------------------------------------------------
// BOOTSTRAP DEL JUEGO
// ---------------------------------------------------------------

new Phaser.Game({
  type: Phaser.AUTO,
  parent: 'game-container',
  backgroundColor: '#050805',
  scale: {
    mode: Phaser.Scale.RESIZE,
    parent: 'game-container',
    width: '100%',
    height: '100%'
  },
  physics: {
    default: 'arcade',
    arcade: { gravity: { y: 0 }, debug: false }
  },
  plugins: {
    scene: [
      { key: 'UIPlugin', plugin: UIPlugin, mapping: 'ui' }
    ]
  },
  scene: [Level1Scene]
});