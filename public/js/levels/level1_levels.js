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

// Tarea 4A — icono HUD de notas (arte bloqueado: se usa tal cual, sin tint).
const NOTAS_ICON_KEY = 'notas_icon';
const NOTAS_ICON_PATH = '../assets/images/notas_icon.png';

const PLAYER_WALK_KEY = 'player';
const PLAYER_WALK_PATH = '../assets/images/player_detective.png';
const PLAYER_DEAD_KEY = 'player_dead';
const PLAYER_DEAD_PATH = '../assets/images/dead_player.png';
const PLAYER_FRAME_WIDTH = 84;
const PLAYER_FRAME_HEIGHT = 124;
const PLAYER_EXPECTED_FRAMES = 24;
const PLAYER_DEAD_W = 84;
const PLAYER_DEAD_H = 124;
const PLAYER_DEAD_EXPECTED_FRAMES = 24;

const PROGRESS_ORDER = ['Documento1', 'Archivero', 'Documento 2', 'Computadora', 'Cajafuerte'];

const CLUE_TEXT = {
  // Tarea 3 — textos exactos de secuencia_nivel1.txt (tildes/typos incluidos).
  'Documento1': 'La primer clave la encontré en el archivo "Secuencia 1.bat" cuidado con lo que encuentres en la caja',
  'Archivero': 'Ellos no querían que supiéramos que tan cerca estuvimos de sus secretos, cuídate de ellos no quieren que sepas lo que hicieron antes de contratarte, en especial el 7 de Noviembre',
  'Documento 2': 'Si encontraste esto estas cerca de encontrar el primero de muchos secretos que nos quieren ocultar, cuidado con lo que buscas quizás no sea lo que ellos quieren que encuentres, si es que quieren que encuntres algo',
  'Computadora': 'Bienvenido Usuario, gestiona los archivos y mas',
  'Cajafuerte': 'ORGANIZACION NIXON TECH'
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
    // Tarea 4A — icono sticky amarilla para botón HUD de notas.
    this.load.image(NOTAS_ICON_KEY, NOTAS_ICON_PATH);

    this.load.spritesheet(PLAYER_WALK_KEY, PLAYER_WALK_PATH, {
      frameWidth: PLAYER_FRAME_WIDTH,
      frameHeight: PLAYER_FRAME_HEIGHT
    });
    this.load.spritesheet(PLAYER_DEAD_KEY, PLAYER_DEAD_PATH, {
      frameWidth: PLAYER_DEAD_W,
      frameHeight: PLAYER_DEAD_H
    });

    this.load.on('loaderror', (file) => {
      console.error(`[Level1Scene] Error cargando asset: ${file.key} (${file.url})`);
    });
  }

  create() {
    this._booted = true;
    this.progresoNivel = 0;
    this.paranoia = 0;
    this.secondsRemaining = TIME_LIMIT_SECONDS;
    this.controlsLocked = false;
    this.modalOpen = false;
    this.paused = false;
    this._pauseOpen = false;
    this._pauseWin = null;
    this._pauseOverlay = null;
    this._pauseEscHandler = null;
    this.gameOverTriggered = false;
    this.gameOverFinished = false;
    this.awaitingServer = false;
    this.wrongClickTimestamps = [];
    // Tarea 3 — motor de acertijos: un solo uso + proximidad + notas.
    this.consumed = {};
    this.proxHints = [];
    this.nearBy = {};
    this.activePuzzle = null;
    this.safeAttempts = 0;
    this.safeLocked = false;
    this.levelNotes = [];
    if (!window.NIXON_NOTES) window.NIXON_NOTES = [];
    if (!window.NIXON) window.NIXON = {};

    this.buildMap();
    this.buildPlayer();
    this.buildAtmosphere();
    this.buildInteractiveZones();
    this.bindInput();

    this.ui.createHUD({ frameKey: HUD_FRAME_KEY, timeLimitSeconds: TIME_LIMIT_SECONDS });

    this.buildCluePopup();
    this.buildNotesButton();
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
    console.log(`[Level1Scene] tileset OK: name="${tileset.name}" image="${tileset.image?.key || TILESET_KEY}" firstgid=${tileset.firstgid}`);

    this.map = map;
    // Orden de capas según Tiled (room_level1.json): Suelo < Paredes < Items < DecoracionesEntorno.
    this.layerFloor = this.safeCreateLayer(map, 'Suelo', tileset, 0);
    this.layerWalls = this.safeCreateLayer(map, 'Paredes', tileset, 1);
    this.layerItems = this.safeCreateLayer(map, 'Items', tileset, 2);
    this.layerDecor = this.safeCreateLayer(map, 'DecoracionesEntorno', tileset, 3);

    // Habilita colisiones en todos los tiles no vacíos de la capa Paredes
    if (this.layerWalls) {
      this.layerWalls.setCollisionByExclusion([0, -1]);
    }
    // Tarea 1.3 — colisiones estrictas: Items y Decor también bloquean (muebles/props sólidos).
    if (this.layerItems) {
      this.layerItems.setCollisionByExclusion([0, -1]);
    }
    if (this.layerDecor) {
      this.layerDecor.setCollisionByExclusion([0, -1]);
    }

    this.physics.world.setBounds(0, 0, map.widthInPixels, map.heightInPixels);
    this.cameras.main.setBounds(0, 0, map.widthInPixels, map.heightInPixels);
    // Centrado inicial: evita mapa descentrado al cargar; startFollow (buildPlayer) toma el relevo después.
    this.cameras.main.centerOn(map.widthInPixels / 2, map.heightInPixels / 2);
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
    // Anclaje a los pies: 1 solo frame limpio (84x124) por ciclo.
    this.player.setOrigin(0.5, 0.85);

    const body = this.player.body;
    if (!body) {
      this.showFatal('ERROR: no se pudo crear el body físico del jugador.');
      return;
    }

    body.setCollideWorldBounds(true);
    body.setDrag(0);
    body.setMaxVelocity(PLAYER_SPEED, PLAYER_SPEED);
    // Hitbox de pies sobre 1 frame limpio de 84x124.
    body.setSize(40, 30);
    body.setOffset((PLAYER_FRAME_WIDTH - 40) / 2, PLAYER_FRAME_HEIGHT - 30);

    if (this.layerWalls) {
      this.physics.add.collider(this.player, this.layerWalls);
    }
    // Tarea 1.3 — colisiones estrictas: Items/Decor también bloquean al jugador.
    if (this.layerItems) {
      this.physics.add.collider(this.player, this.layerItems);
    }
    if (this.layerDecor) {
      this.physics.add.collider(this.player, this.layerDecor);
    }
    this._strictCollisionsEnabled = true;

    this.cameras.main.startFollow(this.player, true, 0.08, 0.08);
    this.createPlayerAnimations();
    this.currentWalkAnim = null;
  }

  createPlayerAnimations() {
    const anims = this.anims;
    const walkTex = this.textures.get(PLAYER_WALK_KEY);
    const walkFrameTotal = walkTex ? walkTex.frameTotal - 1 : 0;

    if (walkFrameTotal !== PLAYER_EXPECTED_FRAMES) {
      console.warn(
        `[Level1Scene] "${PLAYER_WALK_KEY}" tiene ${walkFrameTotal} frames (${PLAYER_FRAME_WIDTH}x${PLAYER_FRAME_HEIGHT}); se esperaban ${PLAYER_EXPECTED_FRAMES}.`
      );
    }

    const deadTex = this.textures.get(PLAYER_DEAD_KEY);
    const deadFrameTotal = deadTex ? deadTex.frameTotal - 1 : 0;

    if (deadFrameTotal !== PLAYER_DEAD_EXPECTED_FRAMES) {
      console.warn(
        `[Level1Scene] "${PLAYER_DEAD_KEY}" tiene ${deadFrameTotal} frames (${PLAYER_DEAD_W}x${PLAYER_DEAD_H}); se esperaban ${PLAYER_DEAD_EXPECTED_FRAMES}.`
      );
    }

    // Spritesheet 672x372 = 8 cols x 3 filas = 24 frames de 84x124.
    // 3 anims limpias sin solape: down 0-7, side 8-15, up 16-23.
    // left/right comparten los frames 8-15; la direccion se resuelve con flipX.
    if (!anims.exists('walk-down')) {
      anims.create({ key: 'walk-down', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 0, end: 7 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('walk-side')) {
      anims.create({ key: 'walk-side', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 8, end: 15 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('walk-up')) {
      anims.create({ key: 'walk-up', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 16, end: 23 }), frameRate: 10, repeat: -1 });
    }
    // Aliases de compatibilidad: mismo rango lateral, flipX decide el lado.
    if (!anims.exists('walk-right')) {
      anims.create({ key: 'walk-right', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 8, end: 15 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('walk-left')) {
      anims.create({ key: 'walk-left', frames: anims.generateFrameNumbers(PLAYER_WALK_KEY, { start: 8, end: 15 }), frameRate: 10, repeat: -1 });
    }
    if (!anims.exists('player-collapse')) {
      const collapseEnd = Math.max(0, Math.min(23, deadFrameTotal > 0 ? deadFrameTotal - 1 : 23));
      anims.create({ key: 'player-collapse', frames: anims.generateFrameNumbers(PLAYER_DEAD_KEY, { start: 0, end: collapseEnd }), frameRate: 10, repeat: 0 });
    }
  }

  getSpawnPoint() {
    const allObjects = this.getAllMapObjects();
    const spawnObj = allObjects.find((o) => o.name === 'Spawn');
    // Si no hay punto de Spawn en el JSON, aparece cerca de la puerta de entrada
    return spawnObj ? { x: spawnObj.x, y: spawnObj.y } : { x: 380, y: 120 };
  }

  // ------------------ ATMÓSFERA ------------------
  // Tarea 1.4 — limpieza visual: atmósfera desactivada (no-op).
  // Se mantiene el método para no romper create(); los destellos y el
  // ruido previos se eliminaron por rúbrica. Feedback game-over
  // (collapseRedOverlay/startRedLightPulse) y
  // UIPlugin.flashParanoiaWarning se conservan.
  buildAtmosphere() {
  }

  // Tarea 1.3 — garantiza colisiones estrictas player vs capas una vez que
  // player y capas existen (create() llama a buildInteractiveZones después de
  // buildPlayer, así que es seguro). Protegido con flag para no duplicar.
  enableStrictCollisions() {
    if (this._strictCollisionsEnabled) return;
    if (!this.player || !this.physics || !this.physics.add) return;
    if (this.layerWalls) {
      this.physics.add.collider(this.player, this.layerWalls);
    }
    if (this.layerItems) {
      this.physics.add.collider(this.player, this.layerItems);
    }
    if (this.layerDecor) {
      this.physics.add.collider(this.player, this.layerDecor);
    }
    this._strictCollisionsEnabled = true;
  }

  // ------------------ ZONAS INTERACTIVAS ------------------

  buildInteractiveZones() {
    const allObjects = this.getAllMapObjects();
    this.zones = [];
    this.proxHints = [];
    this.nearBy = this.nearBy || {};

    // Tarea 3 — hitbox = zona de proximidad 4x4 tiles (128x128) para los 5
    // objetos. E-only: sin apertura por click en zonas (solo tecla E vía
    // tryOpenByProximity); cuerpos estáticos conservados (bloquean paso).
    // Mouse sigue válido DENTRO de popups (carpetas, pad, botones).
    const PROX_SIZE = {
      'Documento1': { w: 4 * 32, h: 4 * 32 },
      'Archivero': { w: 4 * 32, h: 4 * 32 },
      'Documento 2': { w: 4 * 32, h: 4 * 32 },
      'Computadora': { w: 4 * 32, h: 4 * 32 },
      'Cajafuerte': { w: 4 * 32, h: 4 * 32 }
    };

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
      zone.name = objName;

      // E-only: la zona NO es interactiva (sin setInteractive ni apertura
      // por click/pointerdown). Solo cuerpo estático que bloquea el paso;
      // la apertura es solo con tecla E (tryOpenByProximity). El mouse
      // sigue válido DENTRO de los popups (carpetas, pad, botones).

      // Tarea 1.3 — bloqueo interactuables: cuerpo estático sobre la Zone con
      // tamaño real w/h del objeto Tiled (no reducido a punto). E-only:
      // sin input en la zona (el click ya no abre); el cuerpo solo bloquea.
      this.physics.add.existing(zone, true);
      if (zone.body) {
        zone.body.setSize(w, h);
      }
      if (this.player) {
        this.physics.add.collider(this.player, zone);
      }

      const outline = this.add.rectangle(cx, cy, w, h)
        .setStrokeStyle(1, 0x33ff66, 0.25)
        .setDepth(4);

      // Tarea 3 — hint "[E]" flotante, visible solo en rango de proximidad.
      const prox = PROX_SIZE[objName] || { w: 64, h: 64 };
      const hint = this.add.text(cx, cy - h / 2 - 18, '[E]', {
        fontFamily: 'VT323, monospace', fontSize: '22px', color: '#33ff66',
        backgroundColor: 'rgba(0,0,0,0.65)', padding: { x: 6, y: 2 }
      }).setOrigin(0.5).setDepth(6).setVisible(false);

      // E-only: sin compatibilidad click en zona (móvil/debug por E).
      // La apertura es solo tryOpenByProximity() con tecla E.
      this.zones.push({ name: objName, zone, outline, cx, cy, objW: w, objH: h, proxW: prox.w, proxH: prox.h, hint });
      this.proxHints.push(hint);
    }

    this.enableStrictCollisions();

    // Tarea 3 — proximidad por timer 150ms (no cada frame: evita flicker/costo).
    if (!this._proxTimer) {
      this._proxTimer = this.time.addEvent({ delay: 150, loop: true, callback: this.checkProximity, callbackScope: this });
    }
  }

  // Tarea 3 — distancia al BORDE del objeto (clamp punto al rect): el cuerpo
  // estático impide pisar el centro, así que el rango se mide desde fuera.
  // PROX_SIZE 4x4 se conserva como base visual del hint [E]; el disparo por
  // E usa PROX_RANGE = 110px (~3.5 tiles) al borde (área efectiva ~6x6
  // lógica alrededor del mueble) para los 5 objetos.
  checkProximity() {
    if (!this.player || !this.zones || this.modalOpen || this.gameOverTriggered) {
      if (this.zones) for (const z of this.zones) { z.hint?.setVisible(false); }
      this.nearBy = {};
      return;
    }
    const px = this.player.x;
    const py = this.player.y;
    const PROX_RANGE = 110;
    this.nearBy = {};
    for (const z of this.zones) {
      if (this.consumed[z.name]) { z.hint.setVisible(false); continue; }
      const hw = (z.objW || z.proxW || 64) / 2;
      const hh = (z.objH || z.proxH || 64) / 2;
      const dx = Math.max(Math.abs(px - z.cx) - hw, 0);
      const dy = Math.max(Math.abs(py - z.cy) - hh, 0);
      const distToEdge = Math.hypot(dx, dy);
      const inRange = distToEdge <= PROX_RANGE;
      this.nearBy[z.name] = inRange;
      z.hint.setVisible(inRange);
    }
  }

  // Tarea 3 — E abre solo si en rango de proximidad. El orden lo valida el
  // servidor (validate_step.php responde ORDEN INCORRECTO si toca).
  tryOpenByProximity() {
    if (this.controlsLocked || this.modalOpen || this.gameOverTriggered || this.awaitingServer) return;
    for (const step of PROGRESS_ORDER) {
      if (this.nearBy[step] && !this.consumed[step]) {
        this.onZoneClicked(step);
        return;
      }
    }
  }

  onZoneClicked(name) {
    if (this.controlsLocked || this.modalOpen || this.gameOverTriggered || this.awaitingServer) return;
    if (this.consumed[name]) return;
    // Tarea 3 — Cajafuerte: E/click abre el panel local; la verificación real
    // ocurre tras 3 dígitos (si cliente == "712" → validate_stepWithServer).
    if (name === 'Cajafuerte') {
      this.openSafePopup(false);
      return;
    }
    this.validateStepWithServer(name);
  }

  async validateStepWithServer(stepName) {
    this.awaitingServer = true;

    try {
      const res = await fetch(API_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ step: stepName, csrf_token: CSRF_TOKEN })
      });

      const httpStatus = res.status;
      const data = await res.json();
      data._http = httpStatus;
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
      console.warn(`[Level1Scene] validate_step respuesta inválida http=${data && data._http ? data._http : '?'} step=${stepName}`);
      this.showFloatingHint('RESPUESTA INVÁLIDA DEL SERVIDOR.');
      return;
    }

    if (data.status !== 'success') {
      const msg = (typeof data.message === 'string' && data.message.length > 0)
        ? data.message
        : 'ORDEN INCORRECTO.';
      const http = (typeof data._http === 'number') ? data._http : 0;
      console.warn(`[Level1Scene] validate_step error http=${http} step=${stepName} msg=${msg}`);
      const isAuthError = http === 401 || http === 403;
      const isGenuineWrongOrder = /ORDEN INCORRECTO/i.test(msg);
      // Tarea 3 — si la verificación del panel de caja fuerte falla por orden,
      // se informa dentro del propio panel (reintentable) en vez de cerrarlo.
      // Se muestra el message real del servidor. Sin paranoia en 401/403.
      if (stepName === 'Cajafuerte' && this._safePendingBase) {
        this._safePendingBase = null;
        if (this._safeMsg) {
          this._safeMsg.setText(msg).setColor('#ff4466');
        }
        // Tarea 4A — refleja el error también en el display visible del split.
        if (this._safeState && this._safeState.splitMsg) {
          this._safeState.splitMsg.setText(msg).setColor('#ff4466');
        }
        if (isAuthError) {
          this.showFloatingHint(msg);
          return;
        }
        if (isGenuineWrongOrder) {
          this.handleWrongOrderClick(msg);
          return;
        }
        this.showFloatingHint(msg);
        return;
      }
      if (isAuthError) {
        this.showFloatingHint(msg);
        return;
      }
      this.handleWrongOrderClick(msg);
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

    // Tarea 3 — Cajafuerte verificada desde el panel: muestra TOPSECRET.
    if (stepName === 'Cajafuerte' && this._safePendingBase) {
      this.consumePuzzle('Cajafuerte');
      this._levelCompletedPending = completed;
      this.showTopSecretDoc();
      return;
    }

    this.openPuzzlePopup(stepName, completed);
  }

  // Alias legacy (Tarea 3): validate_stepWithServer('Documento1') etc.
  validate_stepWithServer(stepName) {
    return this.validateStepWithServer(stepName);
  }

  // Tarea 3 — despacha al popup del acertijo correspondiente (autoridad de
  // orden/progreso sigue siendo validate_step.php; aquí solo presentación).
  openPuzzlePopup(stepName, levelCompleted) {
    this._levelCompletedPending = levelCompleted;
    if (stepName === 'Documento1') this.openDoc1Popup();
    else if (stepName === 'Archivero') this.openArchiveroPopup();
    else if (stepName === 'Documento 2') this.openDoc2Popup();
    else if (stepName === 'Computadora') this.openTerminalPopup();
    else if (stepName === 'Cajafuerte') this.openSafePopup(true);
    else this.openCluePopup(stepName, levelCompleted);
  }

  // Tarea 3 — un solo uso A1/A2/A3/A4: flag consumed + zone no interactiva +
  // outline destruido. No reabre hasta reload del nivel.
  consumePuzzle(stepName) {
    this.consumed[stepName] = true;
    const item = this.zones.find((z) => z.name === stepName);
    if (item) {
      try { item.zone.disableInteractive(); } catch (e) {}
      try { item.outline.destroy(); } catch (e) {}
      try { item.hint.setVisible(false); } catch (e) {}
    }
    if (this.nearBy) this.nearBy[stepName] = false;
  }

  // Base genérica de popup de acertijo con createFramedWindow (Tarea 2).
  // Devuelve { win, content, close } ; close() cierra + consume + resume.
  openPuzzleBase(stepName, w, h) {
    this.modalOpen = true;
    this.activePuzzle = stepName;
    this.pauseGameplay();
    const win = this.createFramedWindow(w, h);
    win.window.setVisible(true);
    const self = this;
    const close = (consume) => self.closePuzzlePopup(win.window, stepName, consume);
    this._activeClose = close;
    return { win, content: win.content, close };
  }

  closePuzzlePopup(container, stepName, consume) {
    if (this._termKeyHandler) {
      try { this.input.keyboard.off('keydown', this._termKeyHandler); } catch (e) {}
      this._termKeyHandler = null;
    }
    // Punto 2 — limpieza scroll terminal (rueda/arrastre + máscara).
    if (this._termWheelHandler) {
      try { this.input.off('wheel', this._termWheelHandler); } catch (e) {}
      this._termWheelHandler = null;
    }
    if (this._termDragMove) {
      try { this.input.off('pointermove', this._termDragMove); } catch (e) {}
      this._termDragMove = null;
    }
    if (this._termDragUp) {
      try { this.input.off('pointerup', this._termDragUp); } catch (e) {}
      this._termDragUp = null;
    }
    if (this._termMaskG) {
      try { this._termMaskG.destroy(); } catch (e) {}
      this._termMaskG = null;
    }
    if (this._safeEnterHandler) {
      try { this.input.keyboard.off('keydown-ENTER', this._safeEnterHandler); } catch (e) {}
      this._safeEnterHandler = null;
    }
    if (this._safeEscHandler) {
      try { this.input.keyboard.off('keydown-ESC', this._safeEscHandler); } catch (e) {}
      this._safeEscHandler = null;
    }
    if (this._termBlink) { try { this._termBlink.remove(); } catch (e) {} this._termBlink = null; }
    try { container.destroy(); } catch (e) { try { container.setVisible(false); } catch (e2) {} }
    this.activePuzzle = null;
    this._activeClose = null;
    if (this.activePuzzleContainer === container) this.activePuzzleContainer = null;
    this._safeMsg = null;
    if (consume !== false) this.consumePuzzle(stepName);
    this.modalOpen = false;
    if (this._levelCompletedPending) {
      this.onLevelComplete();
    } else {
      this.resumeGameplay();
    }
  }

  handleWrongOrderClick(customMsg) {
    const msg = (typeof customMsg === 'string' && customMsg.length > 0)
      ? customMsg
      : Phaser.Utils.Array.GetRandom(WRONG_ORDER_MESSAGES);
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

  // ------------------ TAREA 3: ACERTIJOS (textos exactos secuencia_nivel1.txt) ------------------

  // Punto 2 — helpers visuales papel: salpicadura sangre + arrugado.
  // Reutilizan Graphics/containers existentes; sin tocar lógica/HUD/APIs.
  _bloodSplatter(content, cx, cy, spread, count) {
    const g = this.add.graphics();
    g.fillStyle(0x5a0d0d, 1);
    for (let i = 0; i < count; i++) {
      const ox = Phaser.Math.Between(-spread, spread);
      const oy = Phaser.Math.Between(-Math.round(spread * 0.7), Math.round(spread * 0.7));
      const r = Phaser.Math.Between(2, 9);
      g.fillCircle(cx + ox, cy + oy, r, false);
      // Gotas satélite.
      g.fillCircle(cx + ox + Phaser.Math.Between(-14, 14), cy + oy + Phaser.Math.Between(-12, 12), 1.5);
    }
    // Brillo central oscuro (coágulo).
    g.fillStyle(0x3d0808, 1);
    g.fillCircle(cx, cy, 5);
    content.add(g);
    return g;
  }

  _paperCrumple(content, cx, cy, pw, ph, alpha) {
    const g = this.add.graphics();
    // Líneas de pliegue simples (diagonales/verticales tenues).
    g.lineStyle(1, 0xb8ac86, alpha || 0.9);
    g.lineBetween(cx - pw / 2 + 12, cy - ph / 2 + 30, cx + pw / 2 - 20, cy - ph / 2 + 70);
    g.lineBetween(cx - pw / 2 + 20, cy + 10, cx + pw / 2 - 14, cy - 30);
    g.lineBetween(cx - 30, cy - ph / 2 + 8, cx - 10, cy + ph / 2 - 12);
    // Borde irregular: trazo quebrado sobre el rectángulo del papel.
    g.lineStyle(2, 0xa89c72, alpha || 0.9);
    const hw = pw / 2;
    const hh = ph / 2;
    g.beginPath();
    g.moveTo(cx - hw + 6, cy - hh);
    g.lineTo(cx + hw - 10, cy - hh + 5);
    g.lineTo(cx + hw, cy + hh - 8);
    g.lineTo(cx + hw - 6, cy + hh);
    g.lineTo(cx - hw + 8, cy + hh - 4);
    g.lineTo(cx - hw, cy - hh + 10);
    g.closePath();
    g.strokePath();
    content.add(g);
    return g;
  }

  // A1 Documento1 hitbox 4x4: página manchada + pie. Un solo uso.
  openDoc1Popup() {
    const W = Math.min(this.scale.width - 60, 560);
    const H = 380;
    const base = this.openPuzzleBase('Documento1', W, H);
    const c = base.content;
    const title = this.add.text(0, -H / 2 + 34, 'DOCUMENTO 1 — PÁGINA MANCHADA', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#33ff66'
    }).setOrigin(0.5);
    // Fondo página manchada (dentro del marco, sin tocar arte bloqueado).
    const page = this.add.rectangle(0, 10, W - 70, H - 130, 0xd8cfae).setOrigin(0.5);
    const stain1 = this.add.circle(-W / 4, -30, 16, 0x8a1414, 0.85);
    const stain2 = this.add.circle(W / 5, 60, 10, 0x8a1414, 0.8);
    const stain3 = this.add.circle(10, -70, 7, 0x8a1414, 0.75);
    const body = this.add.text(0, 0, 'La primer clave la encontré en el archivo "Secuencia 1.bat" cuidado con lo que encuentres en la caja', {
      fontFamily: 'VT323, monospace', fontSize: '19px', color: '#2a1a12',
      align: 'center', wordWrap: { width: W - 120 }
    }).setOrigin(0.5);
    const foot = this.add.text(0, 92, 'PSDT: Utiliza "cap" para revisarlo', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#5a1a1a', fontStyle: 'italic',
      wordWrap: { width: W - 120 }, align: 'center'
    }).setOrigin(0.5);
    const closeBtn = this.add.text(0, H / 2 - 30, '[ CERRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#33ff66'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
    closeBtn.on('pointerout', () => closeBtn.setColor('#33ff66'));
    closeBtn.on('pointerdown', () => base.close(true));
    c.add([title, page, stain1, stain2, stain3, body, foot, closeBtn]);
    // Punto 2 — Doc1 sutil: arrugado leve + sangre extra (sin tocar texto/layout).
    this._paperCrumple(c, 0, 10, W - 70, H - 130, 0.55);
    this._bloodSplatter(c, -W / 4 + 26, 52, 22, 4);
    this._bloodSplatter(c, W / 5 - 14, -52, 16, 3);
  }

  // A3 Documento 2: mismas reglas que A1.
  openDoc2Popup() {
    const W = Math.min(this.scale.width - 60, 560);
    const H = 400;
    const base = this.openPuzzleBase('Documento 2', W, H);
    const c = base.content;
    const title = this.add.text(0, -H / 2 + 34, 'DOCUMENTO 2', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#33ff66'
    }).setOrigin(0.5);
    const page = this.add.rectangle(0, 12, W - 70, H - 130, 0xd8cfae).setOrigin(0.5);
    const stain = this.add.circle(-W / 4, 40, 12, 0x8a1414, 0.8);
    const body = this.add.text(0, -10, 'Si encontraste esto estas cerca de encontrar el primero de muchos secretos que nos quieren ocultar, cuidado con lo que buscas quizás no sea lo que ellos quieren que encuentres, si es que quieren que encuntres algo', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#2a1a12',
      align: 'center', wordWrap: { width: W - 120 }
    }).setOrigin(0.5);
    const foot = this.add.text(0, 108, 'Ya tienes la "Primera" prueba de que estas cerca', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#5a1a1a', fontStyle: 'italic',
      wordWrap: { width: W - 120 }, align: 'center'
    }).setOrigin(0.5);
    const closeBtn = this.add.text(0, H / 2 - 30, '[ CERRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#33ff66'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
    closeBtn.on('pointerout', () => closeBtn.setColor('#33ff66'));
    closeBtn.on('pointerdown', () => base.close(true));
    c.add([title, page, stain, body, foot, closeBtn]);
    // Punto 2 — Doc2: más sangre + arrugado simple (borde irregular / pliegues).
    this._paperCrumple(c, 0, 12, W - 70, H - 130, 1);
    this._bloodSplatter(c, W / 4, -50, 30, 7);
    this._bloodSplatter(c, -W / 4 + 10, 78, 26, 6);
    this._bloodSplatter(c, 40, -90, 18, 4);
  }

  // A2 Archivero hitbox 1x2: 3 carpetas físicas (Graphics rectangles), hover
  // scale 1.1 + highlight; 2 vacías → "VACÍA" y se destruyen; la buena → nota exacta.
  openArchiveroPopup() {
    const W = Math.min(this.scale.width - 60, 600);
    // Punto 2: ventana más alta para que la nota larga sea legible
    // (la nota baja desde y=60 ~110px y ya no pisa hint/cerrar).
    const H = 520;
    const base = this.openPuzzleBase('Archivero', W, H);
    const c = base.content;
    const title = this.add.text(0, -H / 2 + 34, 'ARCHIVERO — 3 CARPETAS', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#33ff66'
    }).setOrigin(0.5);
    c.add(title);
    const goodIdx = Phaser.Math.Between(0, 2);
    const slots = [-150, 0, 150];
    const folders = [];
    const note = this.add.text(0, 60, '', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#e8e4d8',
      align: 'center', wordWrap: { width: W - 120 }
    }).setOrigin(0.5, 0);
    const hint = this.add.text(0, H / 2 - 58, 'Pasa el mouse y haz clic en una carpeta', {
      fontFamily: 'VT323, monospace', fontSize: '16px', color: '#9a9a9a'
    }).setOrigin(0.5);
    const closeBtn = this.add.text(0, H / 2 - 30, '[ CERRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#555555'
    }).setOrigin(0.5);
    c.add([note, hint, closeBtn]);
    let remaining = 3;
    const self = this;

    const enableClose = () => {
      closeBtn.setColor('#33ff66').setInteractive({ useHandCursor: true });
      closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
      closeBtn.on('pointerout', () => closeBtn.setColor('#33ff66'));
      closeBtn.on('pointerdown', () => base.close(true));
    };

    slots.forEach((sx, i) => {
      const isGood = i === goodIdx;
      const cont = this.add.container(sx, -30);
      // Carpeta física: solapa trasera + cuerpo + pestaña (rectángulos, no iconos OS).
      const back = this.add.rectangle(0, -8, 110, 70, 0x8a6d2b).setOrigin(0.5);
      const tab = this.add.rectangle(-28, -46, 44, 16, 0x8a6d2b).setOrigin(0.5);
      const front = this.add.rectangle(0, 4, 110, 62, 0xc9a227).setOrigin(0.5);
      const label = this.add.text(0, 4, isGood ? '???' : 'EXP.', {
        fontFamily: 'VT323, monospace', fontSize: '18px', color: '#3a2c00'
      }).setOrigin(0.5);
      cont.add([back, tab, front, label]);
      cont.setSize(110, 90).setInteractive({ useHandCursor: true });
      cont.on('pointerover', () => { cont.setScale(1.1); front.setFillStyle(0xe3bc3a); });
      cont.on('pointerout', () => { cont.setScale(1.0); front.setFillStyle(0xc9a227); });
      cont.on('pointerdown', () => {
        if (cont._done) return;
        cont._done = true;
        if (!isGood) {
          const empty = self.add.text(sx, 40, 'VACÍA', {
            fontFamily: 'VT323, monospace', fontSize: '20px', color: '#ff4466'
          }).setOrigin(0.5);
          c.add(empty);
          self.tweens.add({ targets: cont, alpha: 0, scale: 0.2, duration: 300,
            onComplete: () => { cont.destroy(); empty.destroy(); } });
          remaining--;
        } else {
          note.setText('Ellos no querían que supiéramos que tan cerca estuvimos de sus secretos, cuídate de ellos no quieren que sepas lo que hicieron antes de contratarte, en especial el 7 de Noviembre\n\nEl "7" parece ser importante para algunos');
          hint.setText('Nota encontrada. Ciérrala para continuar.');
          for (const f of folders) {
            if (f !== cont && !f._done) { f._done = true; f.disableInteractive(); f.setAlpha(0.35); }
          }
          cont.disableInteractive();
          enableClose();
        }
      });
      folders.push(cont);
      c.add(cont);
    });
  }

  // A4 Computadora hitbox 2x2: terminal Win95 funcional + cursor parpadeante.
  openTerminalPopup() {
    const W = Math.min(this.scale.width - 60, 620);
    const H = 420;
    const base = this.openPuzzleBase('Computadora', W, H);
    const c = base.content;
    const titleBar = this.add.rectangle(0, -H / 2 + 50, W - 50, 30, 0x0a246a).setOrigin(0.5);
    const title = this.add.text(-W / 2 + 45, -H / 2 + 50, 'Terminal — Windows 95', {
      fontFamily: 'VT323, monospace', fontSize: '17px', color: '#ffffff'
    }).setOrigin(0, 0.5);
    const screen = this.add.rectangle(0, 30, W - 50, H - 150, 0x000000).setOrigin(0.5)
      .setStrokeStyle(2, 0xc0c0c0);
    const out = this.add.text(-W / 2 + 45, -70, '', {
      fontFamily: '"Courier New", monospace', fontSize: '15px', color: '#33ff66',
      wordWrap: { width: W - 100 }, lineSpacing: 4
    }).setOrigin(0, 0);
    const notif = this.add.text(W / 2 - 40, -20, 'ls te muestra lo que debes o no revisar, cuidado', {
      fontFamily: 'VT323, monospace', fontSize: '14px', color: '#000000',
      backgroundColor: '#ffff99', padding: { x: 8, y: 6 }, wordWrap: { width: 200 }
    }).setOrigin(1, 0);
    const inputLine = this.add.text(-W / 2 + 45, H / 2 - 78, '', {
      fontFamily: '"Courier New", monospace', fontSize: '16px', color: '#ffffff'
    }).setOrigin(0, 0.5);
    const termExit = this.add.text(W / 2 - 40, H / 2 - 52, '[ SALIR ]', {
      fontFamily: 'VT323, monospace', fontSize: '16px', color: '#9a9a9a'
    }).setOrigin(1, 0.5).setInteractive({ useHandCursor: true });
    termExit.on('pointerover', () => termExit.setColor('#ffffff'));
    termExit.on('pointerout', () => termExit.setColor('#9a9a9a'));
    termExit.on('pointerdown', () => base.close(false));
    c.add([titleBar, title, screen, out, notif, inputLine, termExit]);

    this.tweens.add({ targets: notif, y: '-=8', duration: 900, yoyo: true, repeat: -1, ease: 'Sine.easeInOut' });
    // Punto 2 — el aviso de `ls` se auto-oculta a los 3s (fade/destroy).
    this.time.delayedCall(3000, () => {
      if (!notif || !notif.active) return;
      this.tweens.add({ targets: notif, alpha: 0, duration: 400,
        onComplete: () => { try { notif.destroy(); } catch (e) {} } });
    });

    // Punto 2 — área de comandos scroleable: máscara de recorte sobre la
    // pantalla + auto-scroll que mantiene visible la línea de ingreso.
    const scrW = W - 50 - 12;
    const scrH = H - 150 - 12;
    const scrCX = this.scale.width / 2;
    const scrCY = this.scale.height / 2 + 30;
    const maskG = this.make.graphics({ x: 0, y: 0, add: true });
    maskG.fillStyle(0xffffff);
    maskG.fillRect(scrCX - scrW / 2, scrCY - scrH / 2, scrW, scrH);
    maskG.setVisible(false);
    const outMask = maskG.createGeometryMask();
    out.setMask(outMask);
    this._termMaskG = maskG;
    const baseOutY = -70;
    let userScroll = 0;
    const layoutOut = () => {
      const followY = (H / 2 - 78) - 14 - out.height;
      if (out.height <= scrH - 40) { out.y = baseOutY; return; }
      let target = Math.min(baseOutY, followY) + userScroll;
      target = Math.min(target, baseOutY);
      target = Math.max(target, followY - 10);
      out.y = target;
    };
    layoutOut();
    // Rueda: revisa historial (el historial viejo se oculta arriba por la máscara).
    this._termWheelHandler = (pointer, objs, dx, dy) => {
      if (!out || !out.active) return;
      if (this.activePuzzle !== 'Computadora') return;
      userScroll -= (dy || 0) * 0.4;
      const followY = (H / 2 - 78) - 14 - out.height;
      userScroll = Math.min(0, Math.max(followY - 10 - Math.min(baseOutY, followY), userScroll));
      layoutOut();
      // Vuelve abajo con doble-clic/rueda fuerte hacia abajo: se reengancha al seguir.
      if (userScroll >= -4) userScroll = 0;
    };
    this.input.on('wheel', this._termWheelHandler);
    // Arrastre vertical sobre la pantalla para scroll táctil/mouse.
    screen.setInteractive({ useHandCursor: false });
    let dragY = null;
    screen.on('pointerdown', (p) => { dragY = p.y; });
    this._termDragMove = (p) => {
      if (dragY === null || !p.isDown || !out || !out.active) return;
      if (this.activePuzzle !== 'Computadora') return;
      userScroll += (p.y - dragY) * 0.6;
      dragY = p.y;
      const followY = (H / 2 - 78) - 14 - out.height;
      userScroll = Math.min(0, Math.max(followY - 10 - Math.min(baseOutY, followY), userScroll));
      layoutOut();
    };
    this.input.on('pointermove', this._termDragMove);
    this._termDragUp = () => { dragY = null; };
    this.input.on('pointerup', this._termDragUp);

    const lines = ['Bienvenido Usuario, gestiona los archivos y mas', 'C:\\> _'];
    out.setText(lines.join('\n'));
    let buffer = '';
    let seqShown = false;
    let dead = false;
    const self = this;
    const renderInput = (cursorOn) => {
      inputLine.setText('C:\\> ' + buffer + (cursorOn ? '_' : ' '));
    };
    renderInput(true);
    this._termBlink = this.time.addEvent({ delay: 500, loop: true,
      callback: () => renderInput(inputLine.text.endsWith('_') ? false : true) });

    const print = (t) => {
      lines.push(t);
      while (lines.length > 40) lines.shift();
      out.setText(lines.join('\n'));
      userScroll = 0; // auto-scroll: el historial viejo se oculta arriba
      layoutOut();
    };

    const runCommand = (raw) => {
      const cmd = raw.trim();
      if (!cmd) return;
      print('C:\\> ' + cmd);
      if (dead) return;
      if (seqShown) {
        // Siguiente Enter tras Secuencia 1.bat → error + chispas + cierre.
        print('99991: Acceso restringido');
        self.sparksBurst(0, 30);
        self.time.delayedCall(600, () => base.close(true));
        dead = true;
        return;
      }
      if (cmd === 'ls') {
        print('Evidencias.bat   Secreto1.bat   Secuencia 1.bat');
      } else if (cmd === 'cat "Evidencias.vat"' || cmd === 'cat Evidencias.vat' || cmd === 'cat "Evidencias.bat"' || cmd === 'cat Evidencias.bat') {
        print('¿Que estas buscando?');
      } else if (cmd === 'cat Secreto1.bat' || cmd === 'cat "Secreto1.bat"') {
        print('@ECHO: "CUIDADO CON DONDE ENTRAS"');
      } else if (cmd === 'cat "Secuencia 1.bat"' || cmd === 'cat Secuencia 1.bat') {
        print('Lograste encontrar la ultima pieza la cual es el siguiente digito "010" esta en binario para evitar que ellos sepan que logramos encontrar la convinacion de la caja fuerte');
        seqShown = true;
      } else {
        print('Comando no reconocido.');
      }
    };

    this._termKeyHandler = (ev) => {
      if (dead && ev.key !== 'Escape') return;
      if (ev.key === 'Enter') { const b = buffer; buffer = ''; renderInput(true); runCommand(b); }
      else if (ev.key === 'Backspace') { buffer = buffer.slice(0, -1); renderInput(true); }
      else if (ev.key.length === 1 && buffer.length < 60) { buffer += ev.key; renderInput(true); }
      else if (ev.key === 'Escape') { base.close(false); }
    };
    this.input.keyboard.on('keydown', this._termKeyHandler);
  }

  // Chispas naranjas 600ms (partículas/tweens) para compu arruinada.
  sparksBurst(x, y) {
    const host = this.activePuzzleContainer || null;
    for (let i = 0; i < 16; i++) {
      const p = this.add.circle(x, y, Phaser.Math.Between(2, 5), 0xff8800).setDepth(1850).setScrollFactor(0);
      const cx = this.scale.width / 2 + x;
      const cy = this.scale.height / 2 + y;
      p.setPosition(cx, cy);
      this.tweens.add({
        targets: p,
        x: cx + Phaser.Math.Between(-130, 130),
        y: cy + Phaser.Math.Between(-110, 110),
        alpha: 0, duration: 600, ease: 'Cubic.easeOut',
        onComplete: () => p.destroy()
      });
    }
  }

  // A5 Cajafuerte: pantalla + display + panel 0-9 mouse. Auto-envío tras 3 dígitos.
  openSafePopup(fromServer) {
    if (this.safeLocked) { this.showFloatingHint('PANEL BLOQUEADO.'); return; }
    if (this.consumed['Cajafuerte']) return;
    const W = Math.min(this.scale.width - 60, 520);
    const H = 480;
    const base = this.openPuzzleBase('Cajafuerte', W, H);
    const c = base.content;
    this.activePuzzleContainer = base.win.window;
    // Tarea 4A — ref para el modo split (oculta/restaura este panel).
    this._safeBase = base;
    const title = this.add.text(0, -H / 2 + 34, 'CAJA FUERTE', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#33ff66'
    }).setOrigin(0.5);
    const screen = this.add.rectangle(0, -H / 2 + 100, W - 90, 64, 0x0a0f0a)
      .setStrokeStyle(2, 0x33ff66).setOrigin(0.5);
    const display = this.add.text(0, -H / 2 + 100, '_ _ _', {
      fontFamily: '"Courier New", monospace', fontSize: '34px', color: '#33ff66'
    }).setOrigin(0.5);
    const msg = this.add.text(0, -H / 2 + 148, 'Introduce 3 dígitos (Enter = notas)', {
      fontFamily: 'VT323, monospace', fontSize: '15px', color: '#9a9a9a'
    }).setOrigin(0.5);
    c.add([title, screen, display, msg]);
    // Tarea 4A — estado compartido safe/split: el panel dividido a la
    // izquierda reutiliza estos mismos dígitos/submit (no duplica lógica).
    if (!this._safeState) this._safeState = { digits: '' };
    this._safeState.digits = '';
    this._safeState.render = null;
    this._safeState.submit = null;
    const self = this;
    const render = () => {
      const d = self._safeState.digits;
      const chars = (d + '___').slice(0, 3).split('').join(' ');
      display.setText(chars);
      if (self._safeState.splitDisplay) self._safeState.splitDisplay.setText(chars);
    };
    this._safeState.render = render;
    render();

    const submit = () => {
      const digits = self._safeState.digits;
      if (digits.length < 3 || this.safeLocked) return;
      // Cliente == "712" → verificación real contra validate_step.php.
      if (digits !== '712') {
        this.safeAttempts++;
        const left = 5 - this.safeAttempts;
        msg.setText(`CÓDIGO ERRÓNEO. Intentos restantes: ${left}`).setColor('#ff4466');
        if (this._safeState.splitMsg) this._safeState.splitMsg.setText(`CÓDIGO ERRÓNEO. Intentos restantes: ${left}`).setColor('#ff4466');
        this.increaseParanoia(10);
        this._safeState.digits = '';
        render();
        if (this.safeAttempts >= 5) {
          this.safeLocked = true;
          msg.setText('PANEL BLOQUEADO.');
          this.time.delayedCall(700, () => {
            try { base.win.window.destroy(); } catch (e) {}
            this._safeBase = null;
            if (this._safeEscHandler) {
              try { this.input.keyboard.off('keydown-ESC', this._safeEscHandler); } catch (e2) {}
              this._safeEscHandler = null;
            }
            if (this._safeState) { this._safeState.splitDisplay = null; this._safeState.splitMsg = null; }
            this.activePuzzle = null;
            this.modalOpen = false;
            this.paranoia = PARANOIA_MAX;
            this.ui.updateParanoia(this.paranoia);
            this.triggerGameOver('PARANOIA');
          });
        }
        return;
      }
      // Dígitos correctos en cliente → autoridad del servidor.
      msg.setText('Verificando...').setColor('#33ff66');
      if (this._safeState.splitMsg) this._safeState.splitMsg.setText('Verificando...').setColor('#33ff66');
      this._safeMsg = msg;
      this.validateStepWithServer('Cajafuerte');
      this._safePendingBase = base;
      this._safePendingDisplay = { W, H };
    };
    this._safeState.submit = submit;

    // Panel numérico 0-9 clicable con mouse.
    const gridTop = -H / 2 + 210;
    for (let n = 0; n <= 9; n++) {
      const row = Math.floor(n / 3);
      const col = n % 3;
      const bx = (col - 1) * 90;
      const by = gridTop + row * 62;
      const btn = this.add.rectangle(bx, by, 72, 48, 0x1a241a)
        .setStrokeStyle(2, 0x33ff66).setInteractive({ useHandCursor: true });
      const lbl = this.add.text(bx, by, String(n), {
        fontFamily: 'VT323, monospace', fontSize: '26px', color: '#33ff66'
      }).setOrigin(0.5);
      btn.on('pointerover', () => btn.setFillStyle(0x2a3a2a));
      btn.on('pointerout', () => btn.setFillStyle(0x1a241a));
      btn.on('pointerdown', () => {
        if (this.safeLocked || self._safeState.digits.length >= 3) return;
        self._safeState.digits += String(n);
        render();
        if (self._safeState.digits.length === 3) this.time.delayedCall(250, submit);
      });
      c.add([btn, lbl]);
    }
    const clearBtn = this.add.text(-90, H / 2 - 30, '[ BORRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#33ff66'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    clearBtn.on('pointerdown', () => {
      self._safeState.digits = '';
      render();
    });
    // Salir sin consumir (reintentable); el bloqueo por 5 fallos sí cierra.
    const safeExit = this.add.text(90, H / 2 - 30, '[ SALIR ]', {      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#9a9a9a'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    safeExit.on('pointerover', () => safeExit.setColor('#ffffff'));
    safeExit.on('pointerout', () => safeExit.setColor('#9a9a9a'));
    safeExit.on('pointerdown', () => this._closeSafeSimple());
    // Tarea 4A — botón NOTAS dentro del safe: abre split sin validar código.
    const safeNotesBtn = this.add.text(0, H / 2 - 62, '[ NOTAS ]', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#e8c832'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    safeNotesBtn.on('pointerover', () => safeNotesBtn.setColor('#ffffff'));
    safeNotesBtn.on('pointerout', () => safeNotesBtn.setColor('#e8c832'));
    safeNotesBtn.on('pointerdown', () => this.openNotesSplit(true));
    c.add([clearBtn, safeExit, safeNotesBtn]);

    // Enter dentro del canvas de caja fuerte → vista notas dividida en 2
    // (Tarea 4A). NO valida el código. Guard _notesOpen: Enter mientras la
    // libreta está abierta guarda la línea, no reabre el split.
    this._safeEnterHandler = (ev) => {
      if (ev.key === 'Enter' || ev.code === 'Enter' || ev.keyCode === 13) {
        if (this._notesOpen) return;
        this.openNotesSplit(true);
      }
    };
    this.input.keyboard.on('keydown-ENTER', this._safeEnterHandler);
    // ESC/cerrar: cierra sin consumir (reintentable) salvo bloqueo.
    // Handler nombrado (no once): con el split abierto ESC solo cierra las
    // notas; el guard de _notesClosedAt evita que el mismo ESC cierre el safe
    // tras restaurarlo. Sigue armado para futuros ESC en el safe simple.
    if (this._safeEscHandler) {
      try { this.input.keyboard.off('keydown-ESC', this._safeEscHandler); } catch (e2) {}
      this._safeEscHandler = null;
    }
    this._safeEscHandler = () => {
      if (this._notesOpen) return;
      if (this.time && this._notesClosedAt && this.time.now - this._notesClosedAt < 200) return;
      if (this.activePuzzle === 'Cajafuerte' && !this.safeLocked && this._safeBase) {
        this._closeSafeSimple();
      }
    };
    this.input.keyboard.on('keydown-ESC', this._safeEscHandler);
  }

  // Cierre del safe simple sin consumir (reintentable): SALIR y ESC.
  _closeSafeSimple() {
    const sb = this._safeBase;
    try { sb.win.window.destroy(); } catch (e) {}
    this._safeBase = null;
    if (this._safeState) { this._safeState.splitDisplay = null; this._safeState.splitMsg = null; }
    this.activePuzzle = null;
    this.activePuzzleContainer = null;
    this._activeClose = null;
    if (this._safeEnterHandler) {
      try { this.input.keyboard.off('keydown-ENTER', this._safeEnterHandler); } catch (e2) {}
      this._safeEnterHandler = null;
    }
    if (this._safeEscHandler) {
      try { this.input.keyboard.off('keydown-ESC', this._safeEscHandler); } catch (e2) {}
      this._safeEscHandler = null;
    }
    this._safeMsg = null;
    this.modalOpen = false;
    this.resumeGameplay();
  }

  // A5 éxito (llamado desde handleServerResponse cuando el servidor confirma
  // 'Cajafuerte'): anim de apertura + documento TOPSECRET + auto-guardado notas.
  showTopSecretDoc() {
    // Tarea 4A — si el split estaba abierto al verificar, ciérralo sin
    // restaurar el safe (se destruye abajo); TOPSECRET toma el modal.
    if (this._notesOpen) {
      this._notesReturnToSafe = false;
      this._closeNotes();
    }
    const pending = this._safePendingBase;
    this._safePendingBase = null;
    if (pending) {
      try { pending.win.window.destroy(); } catch (e) {}
      this._safeBase = null;
      if (this._safeState) { this._safeState.splitDisplay = null; this._safeState.splitMsg = null; }
      if (this._safeEnterHandler) {
        try { this.input.keyboard.off('keydown-ENTER', this._safeEnterHandler); } catch (e2) {}
        this._safeEnterHandler = null;
      }
      if (this._safeEscHandler) {
        try { this.input.keyboard.off('keydown-ESC', this._safeEscHandler); } catch (e2) {}
        this._safeEscHandler = null;
      }
    }
    const W = Math.min(this.scale.width - 60, 620);
    const H = 460;
    const base = this.openPuzzleBase('Cajafuerte', W, H);
    const c = base.content;
    this.activePuzzleContainer = base.win.window;
    const doc = this.add.rectangle(0, 10, W - 70, H - 120, 0xe8e4d8).setOrigin(0.5);
    // Anim apertura: escala/flash.
    doc.setScale(0.2).setAlpha(0.3);
    this.tweens.add({ targets: doc, scale: 1, alpha: 1, duration: 500, ease: 'Back.easeOut' });
    this.cameras.main.flash(250, 200, 255, 200);
    const wm = this.add.text(0, 10, 'TOPSECRET', {
      fontFamily: 'VT323, monospace', fontSize: '64px', color: '#ff0000'
    }).setOrigin(0.5).setAlpha(0.22).setAngle(-18);
    const head = this.add.text(0, -H / 2 + 60, 'ORGANIZACION NIXON TECH', {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#8a1414', fontStyle: 'bold'
    }).setOrigin(0.5);
    const para = this.add.text(0, 30, 'Nos percatamos de las vulnerabilidades del sistema llamado "UTF-13" y al tratar de corregirlo creamos un virus potente capaz de darnos acceso a la información de los usuarios que esten usando el sistema. Aseguramos que esto no salga a la luz, para ellos se aplicaron medidas de seguridad extremas para quines descubrieron este virus. Fecha 7/10/2030', {
      fontFamily: 'VT323, monospace', fontSize: '17px', color: '#1a1a1a',
      align: 'center', wordWrap: { width: W - 130 }
    }).setOrigin(0.5);
    const closeBtn = this.add.text(0, H / 2 - 30, '[ CERRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#33ff66'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
    closeBtn.on('pointerout', () => closeBtn.setColor('#33ff66'));
    closeBtn.on('pointerdown', () => base.close(true));
    c.add([doc, wm, head, para, closeBtn]);
    // Auto-guarda "UTF-13" y "7/10/2030" en notas del nivel (expuesto p/ Tarea 4).
    this.saveLevelNote('UTF-13');
    this.saveLevelNote('7/10/2030');
  }

  saveLevelNote(text) {
    if (!this.levelNotes.includes(text)) this.levelNotes.push(text);
    if (!window.NIXON_NOTES.includes(text)) window.NIXON_NOTES.push(text);
  }

  // ------------------ TAREA 4A: NOTAS IN-GAME (solo Canvas/Phaser) ------------------
  // Arte bloqueado: NOTAS_ICON_KEY ('notas_icon.png', sticky amarilla) se usa
  // tal cual: sin tint, alpha 1, sin blend. El marco PNG del HUD lo usa SOLO
  // UIPlugin (createHUD); los marcos de esta escena son JS dibujado.
  // Todo texto en VT323, sin DOM.

  // Botón HUD icono notas 36x36, depth 2001 sobre el HUD, esquina
  // INFERIOR DERECHA fija a cámara (W-60, H-70) + etiqueta "NOTAS [Enter]".
  // H-70 (no H-60 exacto): deja ~10px de margen sobre progressBar
  // (y0 0.948 fullscreen) en cualquier altura. Click → openNotesSplit().
  buildNotesButton() {
    if (this._notesBtn) return;
    const place = () => {
      const W = this.scale.width;
      const H = this.scale.height;
      // Icono centrado en (W-60, H-70); etiqueta encima para no pisar la barra.
      this._notesBtn.setPosition(W - 60, H - 70);
      this._notesBtnLabel.setPosition(W - 60, H - 106);
    };
    this._notesBtn = this.add.image(0, 0, NOTAS_ICON_KEY)
      .setDisplaySize(36, 36)
      .setScrollFactor(0)
      .setDepth(2001)
      .setAlpha(1)
      .setInteractive({ useHandCursor: true });
    this._notesBtnLabel = this.add.text(0, 0, 'NOTAS [Enter]', {
      fontFamily: 'VT323, monospace', fontSize: '14px', color: '#e8c832',
      backgroundColor: 'rgba(0,0,0,0.65)', padding: { x: 4, y: 1 }
    }).setOrigin(0.5, 0).setScrollFactor(0).setDepth(2001);
    place();
    this._notesBtn.on('pointerover', () => this.showFloatingHint('NOTAS [Enter]'));
    this._notesBtn.on('pointerdown', () => {
      if (this.gameOverTriggered || this._notesOpen) return;
      this.openNotesSplit(this.activePuzzle === 'Cajafuerte');
    });
    if (!this._notesBtnResizeBound) {
      this._notesBtnResizeBound = true;
      this.scale.on(Phaser.Scale.Events.RESIZE, () => { if (this._notesBtn) place(); });
    }
  }

  // Libreta: modo simple (Enter fuera de objetos, 520x300) o modo split
  // (desde caja fuerte, doble ancho con safe a la izquierda y libreta a la
  // derecha). Input por keyboard events: A-Z0-9+espacio, BACKSPACE borra,
  // ENTER guarda si hay texto (max 50) y limpia, ESC cierra. Pausa gameplay
  // al abrir (como clue popup) y resume al cerrar. Si hay un puzzle activo
  // distinto del safe, Enter no abre nada (solo hint) para no solapar.
  openNotesSplit(safeContext = false) {
    if (this._notesOpen || this.gameOverTriggered) return;
    const inSafe = safeContext && this.activePuzzle === 'Cajafuerte' && this._safeBase && this._safeState;
    if (!inSafe) {
      // Modo simple: no abrir sobre otro modal/puzzle ni con controles bloqueados.
      if (this.modalOpen || this.controlsLocked) {
        if (this.activePuzzle && this.activePuzzle !== 'Cajafuerte') {
          this.showFloatingHint('Termina primero el acertijo actual.');
        }
        return;
      }
      this.modalOpen = true;
      this.pauseGameplay();
      this._notesReturnToSafe = false;
      const W = 520;
      const H = 300;
      const win = this.createFramedWindow(W, H);
      this._notesWin = win.window;
      this._notesWin.setVisible(true);
      this._buildNotebookPanel(win.content, 0, W - 70);
      this._bindNotesKeyboard();
      return;
    }
    // Modo split: oculta el panel safe simple y muestra doble ancho con el
    // safe reutilizado a la izquierda (x=-220, mismos dígitos/submit) y la
    // libreta a la derecha (x=+220). Al cerrar vuelve al safe simple.
    try { this._safeBase.win.window.setVisible(false); } catch (e) { /* sigue simple */ }
    this._notesReturnToSafe = true;
    const W = Math.min(this.scale.width - 40, 900);
    const H = 460;
    // -220/+220 según spec; se comprime solo si el canvas no da el ancho.
    const LEFT_OX = -Math.min(220, W / 2 - 160);
    const RIGHT_OX = -LEFT_OX;
    const win = this.createFramedWindow(W, H);
    this._notesWin = win.window;
    this._notesWin.setVisible(true);
    const c = win.content;
    const sep = this.add.rectangle(0, 0, 2, H - 60, 0x33ff66).setAlpha(0.35).setOrigin(0.5);
    c.add(sep);
    this._buildSafeMiniPanel(c, LEFT_OX);
    this._buildNotebookPanel(c, RIGHT_OX, W / 2 - 50);
    this._bindNotesKeyboard();
  }

  // Panel izquierda del split: pantalla + display + pad 0-9 conectados al
  // mismo _safeState (dígitos/render/submit compartidos con el safe simple).
  _buildSafeMiniPanel(content, ox) {
    const st = this._safeState;
    const title = this.add.text(ox, -196, 'CAJA FUERTE', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#33ff66'
    }).setOrigin(0.5);
    const screen = this.add.rectangle(ox, -130, 330, 64, 0x0a0f0a)
      .setStrokeStyle(2, 0x33ff66).setOrigin(0.5);
    const d = st.digits || '';
    const display = this.add.text(ox, -130, (d + '___').slice(0, 3).split('').join(' '), {
      fontFamily: '"Courier New", monospace', fontSize: '34px', color: '#33ff66'
    }).setOrigin(0.5);
    const msg = this.add.text(ox, -82, 'Introduce 3 dígitos', {
      fontFamily: 'VT323, monospace', fontSize: '15px', color: '#9a9a9a'
    }).setOrigin(0.5);
    st.splitDisplay = display;
    st.splitMsg = msg;
    content.add([title, screen, display, msg]);
    const gridTop = -20;
    for (let n = 0; n <= 9; n++) {
      const row = Math.floor(n / 3);
      const col = n % 3;
      const bx = ox + (col - 1) * 90;
      const by = gridTop + row * 62;
      const btn = this.add.rectangle(bx, by, 72, 48, 0x1a241a)
        .setStrokeStyle(2, 0x33ff66).setInteractive({ useHandCursor: true });
      const lbl = this.add.text(bx, by, String(n), {
        fontFamily: 'VT323, monospace', fontSize: '26px', color: '#33ff66'
      }).setOrigin(0.5);
      btn.on('pointerover', () => btn.setFillStyle(0x2a3a2a));
      btn.on('pointerout', () => btn.setFillStyle(0x1a241a));
      btn.on('pointerdown', () => {
        if (this.safeLocked || st.digits.length >= 3) return;
        st.digits += String(n);
        if (st.render) st.render();
        if (st.digits.length === 3) this.time.delayedCall(250, () => { if (st.submit) st.submit(); });
      });
      content.add([btn, lbl]);
    }
    // Punto 2 — [VOLVER AL SAFE] en la misma fila Y que [BORRAR] (solo posición).
    const clearBtn = this.add.text(ox - 85, 200, '[ BORRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#33ff66'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    clearBtn.on('pointerdown', () => { st.digits = ''; if (st.render) st.render(); });
    const closeBtn = this.add.text(ox + 95, 200, '[ VOLVER AL SAFE ]', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#9a9a9a'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
    closeBtn.on('pointerout', () => closeBtn.setColor('#9a9a9a'));
    closeBtn.on('pointerdown', () => this._closeNotes());
    content.add([clearBtn, closeBtn]);
  }

  // Panel libreta reutilizado en ambos modos: título LIBRETA + lista de
  // levelNotes + línea de input + contador n/50 + footer de ayuda.
  // Estilo libreta opaca: papel #e8dcb0 alpha 1, líneas cada 22px, margen
  // rojo vertical, chincheta/notas_icon arriba, texto #1a1a1a. Sin blend.
  _buildNotebookPanel(content, ox, panelW) {
    this._notesBuffer = '';
    this._notesPanelOX = ox;
    this._notesPanelW = panelW;
    const isSplit = this._notesReturnToSafe === true;
    // Simple (marco 520x300): lista compacta (últimas 4); split: últimas 6.
    this._notesMaxShown = isSplit ? 6 : 4;
    const topY = isSplit ? -196 : -116;
    const listY = isSplit ? -50 : -38;
    const inputY = isSplit ? 99 : 18;
    const counterY = isSplit ? 121 : 42;
    const footY = isSplit ? 168 : 88;
    const panelH = isSplit ? 400 : 260;
    const centerY = isSplit ? -14 : 1;
    // Fondo papel opaco #e8dcb0 (alpha 1, sin blend).
    const paper = this.add.rectangle(ox, centerY, panelW + 40, panelH, 0xe8dcb0, 1)
      .setOrigin(0.5).setAlpha(1);
    // Líneas horizontales cada 22px (0x8a7a5a, opacas).
    const ruled = this.add.graphics().setAlpha(1);
    ruled.lineStyle(1, 0x8a7a5a, 1);
    const y0 = centerY - panelH / 2 + 34;
    const y1 = centerY + panelH / 2 - 10;
    for (let ly = y0; ly <= y1; ly += 22) {
      ruled.lineBetween(ox - panelW / 2 - 14, ly, ox + panelW / 2 + 14, ly);
    }
    // Margen rojo vertical.
    const margin = this.add.rectangle(ox - panelW / 2 + 6, centerY, 3, panelH - 20, 0xc0392b, 1)
      .setOrigin(0.5).setAlpha(1);
    // Chincheta/notas_icon arriba (arte tal cual, alpha 1, sin tint).
    const pin = this.add.image(ox, topY - 26, NOTAS_ICON_KEY)
      .setDisplaySize(28, 28).setAlpha(1);
    const title = this.add.text(ox, topY, 'LIBRETA', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#1a1a1a'
    }).setOrigin(0.5).setAlpha(1);
    this._notesList = this.add.text(ox, listY, '', {
      fontFamily: 'VT323, monospace', fontSize: '17px', color: '#1a1a1a',
      align: 'left', wordWrap: { width: panelW }
    }).setOrigin(0.5, 0.5).setAlpha(1);
    this._notesInput = this.add.text(ox, inputY, '', {
      fontFamily: 'VT323, monospace', fontSize: '18px', color: '#1a1a1a',
      wordWrap: { width: panelW }
    }).setOrigin(0.5, 0.5).setAlpha(1);
    this._notesCounter = this.add.text(ox + panelW / 2, counterY, '0/50', {
      fontFamily: 'VT323, monospace', fontSize: '15px', color: '#1a1a1a'
    }).setOrigin(1, 0.5).setAlpha(1);
    const foot = this.add.text(ox, footY, '[Enter] guardar · [Esc] cerrar', {
      fontFamily: 'VT323, monospace', fontSize: '15px', color: '#1a1a1a'
    }).setOrigin(0.5).setAlpha(1);
    content.add([paper, ruled, margin, pin, title, this._notesList, this._notesInput, this._notesCounter, foot]);
    if (!isSplit) {
      const closeBtn = this.add.text(ox, 118, '[ CERRAR ]', {
        fontFamily: 'VT323, monospace', fontSize: '20px', color: '#33ff66'
      }).setOrigin(0.5).setInteractive({ useHandCursor: true });
      closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
      closeBtn.on('pointerout', () => closeBtn.setColor('#33ff66'));
      closeBtn.on('pointerdown', () => this._closeNotes());
      content.add(closeBtn);
      this._notesCloseBtn = closeBtn;
    } else {
      this._notesCloseBtn = null;
    }
    this._refreshNotesList();
  }

  // Captura de teclado de la libreta: A-Z0-9+espacio (max 50), BACKSPACE
  // borra, ENTER guarda y limpia, ESC cierra. Sin DOM, solo Canvas/Phaser.
  _bindNotesKeyboard() {
    if (this._notesKeyHandler) {
      try { this.input.keyboard.off('keydown', this._notesKeyHandler); } catch (e) {}
      this._notesKeyHandler = null;
    }
    this._notesKeyHandler = (ev) => {
      if (!this._notesOpen && !this._notesWin) return;
      if (ev.key === 'Escape') { ev.preventDefault?.(); this._closeNotes(); }
      else if (ev.key === 'Enter') {
        ev.preventDefault?.();
        const t = (this._notesBuffer || '').trim();
        if (t.length > 0) {
          this.saveLevelNote(t.slice(0, 50));
          this._notesBuffer = '';
          this._refreshNotesList();
        }
      }
      else if (ev.key === 'Backspace') {
        ev.preventDefault?.();
        this._notesBuffer = (this._notesBuffer || '').slice(0, -1);
        this._refreshNotesList();
      }
      else if (ev.key && ev.key.length === 1 && (this._notesBuffer || '').length < 50
        && /^[\p{L}\p{N} .,;:!?¡¿\-_\/]$/u.test(ev.key)) {
        this._notesBuffer += ev.key;
        this._refreshNotesList();
      }
    };
    // Marca abierta antes de escuchar para que el guard del handler valga.
    this._notesOpen = true;
    this.input.keyboard.on('keydown', this._notesKeyHandler);
  }

  // Refresca lista + línea de input + contador n/50.
  _refreshNotesList() {
    const notes = this.levelNotes || [];
    const shown = notes.slice(-(this._notesMaxShown || 6));
    const body = shown.length ? shown.map((n) => '· ' + n).join('\n') : '(sin notas)';
    if (this._notesList) this._notesList.setText(body);
    const buf = this._notesBuffer || '';
    if (this._notesInput) this._notesInput.setText('> ' + buf + '_');
    if (this._notesCounter) {
      this._notesCounter.setText(`${buf.length}/50`);
      this._notesCounter.setColor(buf.length >= 50 ? '#8a1414' : '#1a1a1a');
      this._notesCounter.setAlpha(1);
    }
  }

  // Cierra la libreta: en split restaura el safe simple (sigue pausado y
  // modal); en simple libera modalOpen y resume gameplay.
  _closeNotes() {
    if (this._notesKeyHandler) {
      try { this.input.keyboard.off('keydown', this._notesKeyHandler); } catch (e) {}
      this._notesKeyHandler = null;
    }
    if (this._notesWin) {
      try { this._notesWin.destroy(); } catch (e) { try { this._notesWin.setVisible(false); } catch (e2) {} }
      this._notesWin = null;
    }
    this._notesList = null;
    this._notesInput = null;
    this._notesCounter = null;
    this._notesCloseBtn = null;
    this._notesBuffer = '';
    this._notesOpen = false;
    if (this.time) this._notesClosedAt = this.time.now;
    if (this._safeState) { this._safeState.splitDisplay = null; this._safeState.splitMsg = null; }
    const sb = this._safeBase;
    const safeAlive = sb && sb.win && sb.win.window && sb.win.window.active !== false;
    if (this._notesReturnToSafe && safeAlive) {
      // Vuelve al safe simple: visible, modal y pausa intactos.
      try { sb.win.window.setVisible(true); } catch (e) {}
      this._notesReturnToSafe = false;
      return;
    }
    this._notesReturnToSafe = false;
    this.modalOpen = false;
    this.resumeGameplay();
  }

  // ------------------ VENTANA EMERGENTE DE PISTA ------------------
  // Tarea 2 — Estandarización de Interfaz: builder reutilizable con marco JS
  // dibujado por código (sin PNG). Fondo opaco + borde doble + remaches.
  // El PNG del HUD lo usa SOLO UIPlugin (createHUD); aquí no se carga ni se
  // referencia ninguna imagen de marco.
  //
  // Uso para Tarea 3 (acertijos) y Tarea 4 (notas dividida en 2 + terminal):
  //   const win = this.createFramedWindow(600, 320); // { window, content, frame, width, height }
  //   const title = this.add.text(0, -100, '...', {...}).setOrigin(0.5);
  //   win.content.add([title, ...]);                 // contenido SIEMPRE en content (encima del marco)
  //   win.window.setVisible(true);                   // mostrar / ocultar vía container padre
  //   // responsive: reposicionar win.window al centro en open + escuchar RESIZE (ver layoutCluePopup).
  //   // this.createFramedWindow es el punto de reutilización (misma escena); no requiere import.
  createFramedWindow(width, height) {
    const cx = this.scale.width / 2;
    const cy = this.scale.height / 2;

    // Contenedor padre: profundidad 1800+ (bajo HUD 2000 de UIPlugin), fijo a cámara.
    const popup = this.add.container(cx, cy).setScrollFactor(0).setDepth(1800);

    // Sombra: rectángulo negro alpha 0.6 con offset (+8,+10).
    const shadow = this.add.rectangle(8, 10, width, height, 0x000000, 0.6).setOrigin(0.5);
    shadow.setAlpha(0.6);

    // Fondo opaco + borde doble + remaches, todo por Graphics (alpha 1).
    const bg = this.add.graphics();
    const drawFrame = (ww, hh) => {
      bg.clear();
      // Fondo opaco.
      bg.fillStyle(0x0a0f0a, 1);
      bg.fillRect(-ww / 2, -hh / 2, ww, hh);
      // Borde doble: exterior 3px + interior 1px con margen de 8px.
      bg.lineStyle(3, 0x33ff66, 1);
      bg.strokeRect(-ww / 2, -hh / 2, ww, hh);
      bg.lineStyle(1, 0x1a5c2a, 1);
      bg.strokeRect(-ww / 2 + 8, -hh / 2 + 8, ww - 16, hh - 16);
      // Esquinas remachadas: 4 squares.
      bg.fillStyle(0x33ff66, 1);
      const s = 6;
      const rx = ww / 2 - 14;
      const ry = hh / 2 - 14;
      bg.fillRect(-rx - s / 2, -ry - s / 2, s, s);
      bg.fillRect(rx - s / 2, -ry - s / 2, s, s);
      bg.fillRect(-rx - s / 2, ry - s / 2, s, s);
      bg.fillRect(rx - s / 2, ry - s / 2, s, s);
    };
    drawFrame(width, height);

    // Capa de contenido encima del marco (orden de render: marco primero, contenido después).
    const content = this.add.container(0, 0);

    popup.add([shadow, bg, content]);
    popup.setScrollFactor(0);
    popup._redrawFrame = (nw, nh) => {
      drawFrame(nw, nh);
      shadow.setSize(nw, nh);
      shadow.setDisplaySize(nw, nh);
    };

    return { window: popup, content, frame: bg, width, height };
  }

  // Re-centra y re-dibuja el popup de pista al tamaño actual del canvas.
  // No toca UIPlugin.layout (ese sigue su propio listener RESIZE).
  layoutCluePopup() {
    if (!this.cluePopup) return;
    const W = this.scale.width;
    const H = this.scale.height;
    const w = Math.min(W - 80, 620);
    const h = 280;
    this.cluePopup.setPosition(W / 2, H / 2);
    if (this.cluePopup._redrawFrame) {
      this.cluePopup._redrawFrame(w, h);
    }
    if (this.clueText) {
      this.clueText.setWordWrapWidth(Math.min(W - 130, 560));
    }
    this.cluePopupSize = { width: w, height: h };
  }

  buildCluePopup() {
    const w = Math.min(this.scale.width - 80, 620);
    const h = 280;

    const framed = this.createFramedWindow(w, h);
    this.cluePopup = framed.window;
    this.cluePopupContent = framed.content;
    this.cluePopupFrame = framed.frame;
    this.cluePopupSize = { width: framed.width, height: framed.height };
    this.cluePopup.setVisible(false);

    this.clueText = this.add.text(0, -60, '', {
      fontFamily: 'VT323, monospace', fontSize: '19px', color: '#e8e4d8',
      align: 'center', wordWrap: { width: Math.min(this.scale.width - 130, 560) }
    }).setOrigin(0.5);

    const closeBtn = this.add.text(0, 110, '[ CERRAR ]', {
      fontFamily: 'VT323, monospace', fontSize: '22px', color: '#33ff66'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });

    closeBtn.on('pointerover', () => closeBtn.setColor('#ffffff'));
    closeBtn.on('pointerout', () => closeBtn.setColor('#33ff66'));
    closeBtn.on('pointerdown', () => this.closeCluePopup());

    this.cluePopupContent.add([this.clueText, closeBtn]);

    // Responsive: si hay resize con popup abierto, re-centra y re-escala.
    if (!this._cluePopupResizeBound) {
      this._cluePopupResizeBound = true;
      this.scale.on(Phaser.Scale.Events.RESIZE, () => this.layoutCluePopup());
    }

    this.input.keyboard?.on('keydown-ESC', () => {
      // ESC global prioriza pause sobre clue popup: con pause abierto
      // el handler de bindInput ya reanudó; aquí no se toca la pista.
      if (this.paused || this._pauseOpen) return;
      // Tarea 3 — ESC cierra el acertijo activo (un solo uso: consume).
      // Terminal y caja fuerte tienen su propio handler ESC; aquí van A1/A2/A3 + TOPSECRET.
      // Tarea 4A — con la libreta abierta ESC solo la cierra a ella.
      if (this._notesOpen) return;
      if (this.activePuzzle && this._activeClose && this.activePuzzle !== 'Cajafuerte' && this.activePuzzle !== 'Computadora') {
        const fn = this._activeClose;
        this._activeClose = null;
        fn(true);
        return;
      }
      if (this.modalOpen && !this.activePuzzle) this.closeCluePopup();
    });
  }

  openCluePopup(stepName, levelCompleted) {
    this.modalOpen = true;
    this.pauseGameplay();
    this._levelCompletedPending = levelCompleted;

    this.clueText.setText(CLUE_TEXT[stepName] || '');
    this.layoutCluePopup();
    this.cluePopup.setVisible(true);
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

    // Tarea 4B — persistencia best-effort del archivero + progreso SOLO al ganar.
    // No bloquea el redirect más de 1.5s; game over nunca llama aquí.
    // Gane o pierda se vuelve a levels.php (ver level1_complete.php).
    try {
      const notes = Array.isArray(this.levelNotes) ? this.levelNotes : [];
      const csrf = (window.NIXON && window.NIXON.csrfToken) || CSRF_TOKEN || '';
      const send = (url, payload) => {
        try {
          const ctrl = new AbortController();
          const timer = setTimeout(() => { try { ctrl.abort(); } catch (e) {} }, 1500);
          fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            signal: ctrl.signal,
            keepalive: true
          }).catch(() => {}).finally(() => clearTimeout(timer));
        } catch (e) { /* best-effort */ }
      };
      send('../api/save_notes.php', { notes, csrf_token: csrf });
      send('../api/save_progress.php', { level: 'level1', step: 5, completed: true, csrf_token: csrf });
    } catch (e) { /* best-effort: sigue al redirect */ }

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

  // ------------------ MENÚ DE PAUSA (Space) ------------------
  // Space abre solo si no hay modal (pista/terminal/safe/notas/pause)
  // y no se escribe en terminal/notas. Usa createFramedWindow + overlay
  // opaco fullscreen. Congela vía pauseGameplay + modalOpen=true
  // (handleMovement ya respeta controlsLocked; onSecondTick e
  // increaseParanoia chequean this.paused). Cierre: [X] o Esc (=reanudar).

  openPauseMenu() {
    if (this.paused || this._pauseOpen || this.gameOverTriggered) return;
    if (this.modalOpen || this._notesOpen || this.activePuzzle) return;
    this.paused = true;
    this._pauseOpen = true;
    this.modalOpen = true;
    this.pauseGameplay();
    const W = this.scale.width;
    const H = this.scale.height;
    // Overlay opaco fullscreen que tapa el juego (solo se ven opciones).
    this._pauseOverlay = this.add.rectangle(W / 2, H / 2, W, H, 0x000000, 1)
      .setScrollFactor(0).setDepth(2099);
    const PW = Math.min(W - 60, 380);
    const PH = 360;
    const win = this.createFramedWindow(PW, PH);
    win.window.setDepth(2100);
    win.window.setVisible(true);
    this._pauseWin = win.window;
    const c = win.content;
    const title = this.add.text(0, -PH / 2 + 34, 'PAUSA', {
      fontFamily: 'VT323, monospace', fontSize: '24px', color: '#33ff66'
    }).setOrigin(0.5);
    const closeX = this.add.text(PW / 2 - 28, -PH / 2 + 28, '[X]', {
      fontFamily: 'VT323, monospace', fontSize: '20px', color: '#ff4466'
    }).setOrigin(0.5).setInteractive({ useHandCursor: true });
    closeX.on('pointerover', () => closeX.setColor('#ffffff'));
    closeX.on('pointerout', () => closeX.setColor('#ff4466'));
    closeX.on('pointerdown', () => this.closePauseMenu());
    const mkBtn = (y, label, color, cb) => {
      const b = this.add.text(0, y, label, {
        fontFamily: 'VT323, monospace', fontSize: '22px', color
      }).setOrigin(0.5).setInteractive({ useHandCursor: true });
      b.on('pointerover', () => b.setColor('#ffffff'));
      b.on('pointerout', () => b.setColor(color));
      b.on('pointerdown', cb);
      return b;
    };
    const btnResume = mkBtn(-60, '[ REANUDAR ]', '#33ff66', () => this.closePauseMenu());
    const btnSaveExit = mkBtn(-10, '[ GUARDAR Y SALIR ]', '#33ff66', () => this._pauseSaveAndExit());
    const btnRestart = mkBtn(40, '[ REINICIAR ]', '#33ff66', () => this._pauseRestart());
    const btnExit = mkBtn(90, '[ SALIR ]', '#ff4466', () => this._pauseExit());
    c.add([title, closeX, btnResume, btnSaveExit, btnRestart, btnExit]);
    if (!this._pauseResizeBound) {
      this._pauseResizeBound = true;
      this.scale.on(Phaser.Scale.Events.RESIZE, () => this._layoutPause());
    }
  }

  _layoutPause() {
    if (!this.paused || !this._pauseWin) return;
    const W = this.scale.width;
    const H = this.scale.height;
    try { this._pauseOverlay?.setPosition(W / 2, H / 2).setSize(W, H).setDisplaySize(W, H); } catch (e) {}
    try { this._pauseWin.setPosition(W / 2, H / 2); } catch (e) {}
  }

  closePauseMenu() {
    if (!this.paused && !this._pauseOpen) return;
    if (this._pauseWin) {
      try { this._pauseWin.destroy(); } catch (e) { try { this._pauseWin.setVisible(false); } catch (e2) {} }
      this._pauseWin = null;
    }
    if (this._pauseOverlay) {
      try { this._pauseOverlay.destroy(); } catch (e) {}
      this._pauseOverlay = null;
    }
    this._pauseOpen = false;
    this.paused = false;
    this.modalOpen = false;
    this.resumeGameplay();
  }

  _pauseCsrf() {
    return (window.NIXON && window.NIXON.csrfToken) || CSRF_TOKEN || '';
  }

  _pauseSaveAndExit() {
    try {
      const csrf = this._pauseCsrf();
      const notes = Array.isArray(this.levelNotes) ? this.levelNotes : [];
      const step = Math.max(0, Math.min(5, this.progresoNivel | 0));
      const completed = step >= PROGRESS_ORDER.length;
      const send = (url, payload) => {
        try {
          fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
            keepalive: true
          }).catch(() => {});
        } catch (e) { /* best-effort */ }
      };
      send('../api/save_notes.php', { notes, csrf_token: csrf });
      send('../api/save_progress.php', { level: 'level1', step, completed, csrf_token: csrf });
    } catch (e) { /* best-effort */ }
    window.location.href = '../levels.php';
  }

  _pauseRestart() {
    try {
      const csrf = this._pauseCsrf();
      try {
        fetch('../api/reset_level.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ level: 'level1', csrf_token: csrf }),
          keepalive: true
        }).catch(() => {});
      } catch (e) { /* best-effort */ }
    } catch (e) { /* best-effort */ }
    window.location.href = 'level1.php?reset=1';
  }

  _pauseExit() {
    try {
      const csrf = this._pauseCsrf();
      try {
        fetch('../api/reset_level.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ level: 'level1', csrf_token: csrf }),
          keepalive: true
        }).catch(() => {});
      } catch (e) { /* best-effort */ }
    } catch (e) { /* best-effort */ }
    window.location.href = '../levels.php';
  }

  // ------------------ INPUT ------------------

  bindInput() {
    const kb = this.input.keyboard;
    if (!kb) return;
    this.cursors = kb.createCursorKeys();
    this.wasd = kb.addKeys({ up: 'W', left: 'A', down: 'S', right: 'D' });
    // Tarea 3 — E abre acertijo en rango; Enter abre notas (stub Tarea 4).
    // Sin romper movimiento (cursores+WASD intactos).
    this.keyE = kb.addKey('E');
    this.keyEnter = kb.addKey('ENTER');
    kb.on('keydown-E', () => this.tryOpenByProximity());
    kb.on('keydown-ENTER', () => {
      // Enter dentro de la caja fuerte lo gestiona el handler del panel
      // (openNotesSplit); fuera de interacción abre notas.
      if (this.activePuzzle === 'Cajafuerte') return;
      if (!this.modalOpen && !this.controlsLocked && !this.gameOverTriggered) this.openNotesSplit();
    });
    // Menú de pausa con Space: solo si no hay modal abierto (pista /
    // terminal / safe / notas / pause) y no se está escribiendo en
    // terminal / notas (Canvas: _notesOpen o terminal activa consumen
    // el espacio como texto, aquí no se pausa).
    kb.on('keydown-SPACE', (ev) => {
      if (this.gameOverTriggered) return;
      if (this.paused || this._pauseOpen) return;
      if (this.modalOpen) return;
      if (this._notesOpen) return;
      if (this.activePuzzle) return;
      try { ev.preventDefault(); } catch (e) {}
      this.openPauseMenu();
    });
    // Esc con pause abierto = reanudar. Se registra antes que el handler
    // de clue popup (buildCluePopup); ese handler debe ignorar cuando
    // hay pausa (ver guard allí) para no cerrar la pista por error.
    kb.on('keydown-ESC', () => {
      if (this.paused || this._pauseOpen) this.closePauseMenu();
    });
  }

  // ------------------ TEMPORIZADOR Y PARANOIA ------------------

  startTimers() {
    this.time.addEvent({ delay: 1000, loop: true, callback: this.onSecondTick, callbackScope: this });
  }

  onSecondTick() {
    if (this.gameOverTriggered) return;
    if (this.paused || this._pauseOpen) return;
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
    if (this.paused || this._pauseOpen) return;

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

    try { this.cluePopup?.setVisible(false); } catch (e) {}
    // Pausa: si estaba abierta al perder, se destruye sin reanudar.
    if (this._pauseWin) { try { this._pauseWin.destroy(); } catch (e) {} this._pauseWin = null; }
    if (this._pauseOverlay) { try { this._pauseOverlay.destroy(); } catch (e) {} this._pauseOverlay = null; }
    this._pauseOpen = false;
    this.paused = false;
    // Tarea 3 — cierra cualquier popup de acertijo activo sin consumir.
    if (this._termKeyHandler) {
      try { this.input.keyboard.off('keydown', this._termKeyHandler); } catch (e) {}
      this._termKeyHandler = null;
    }
    if (this._termWheelHandler) {
      try { this.input.off('wheel', this._termWheelHandler); } catch (e) {}
      this._termWheelHandler = null;
    }
    if (this._termDragMove) {
      try { this.input.off('pointermove', this._termDragMove); } catch (e) {}
      this._termDragMove = null;
    }
    if (this._termDragUp) {
      try { this.input.off('pointerup', this._termDragUp); } catch (e) {}
      this._termDragUp = null;
    }
    if (this._termMaskG) {
      try { this._termMaskG.destroy(); } catch (e) {}
      this._termMaskG = null;
    }
    if (this._safeEnterHandler) {
      try { this.input.keyboard.off('keydown-ENTER', this._safeEnterHandler); } catch (e) {}
      this._safeEnterHandler = null;
    }
    if (this._safeEscHandler) {
      try { this.input.keyboard.off('keydown-ESC', this._safeEscHandler); } catch (e) {}
      this._safeEscHandler = null;
    }
    if (this._termBlink) { try { this._termBlink.remove(); } catch (e) {} this._termBlink = null; }
    if (this.activePuzzleContainer) {
      try { this.activePuzzleContainer.destroy(); } catch (e) {}
      this.activePuzzleContainer = null;
    }
    this._safeBase = null;
    // Tarea 4A — cierra la libreta si estaba abierta (game over redirige).
    if (this._notesKeyHandler) {
      try { this.input.keyboard.off('keydown', this._notesKeyHandler); } catch (e) {}
      this._notesKeyHandler = null;
    }
    if (this._notesWin) {
      try { this._notesWin.destroy(); } catch (e) {}
      this._notesWin = null;
    }
    this._notesOpen = false;
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
    player.setFlipX(false);
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
      // Al perder: reinicio best-effort (progreso 0/5 + borra notas del
      // intento vía POST reset_level.php) antes de volver a levels.php
      // pasando por gameover.php. No bloquea el redirect.
      try {
        const csrf = (window.NIXON && window.NIXON.csrfToken) || CSRF_TOKEN || '';
        const ctrl = new AbortController();
        const timer = setTimeout(() => { try { ctrl.abort(); } catch (e) {} }, 1500);
        fetch('../api/reset_level.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ level: 'level1', csrf_token: csrf }),
          signal: ctrl.signal,
          keepalive: true
        }).catch(() => {}).finally(() => clearTimeout(timer));
      } catch (e) { /* best-effort */ }
      const reasonParam = reason === 'TIME' ? 'time' : 'paranoia';
      window.location.href = `gameover.php?reason=${reasonParam}`;
    });
  }

  // ------------------ UPDATE ------------------

  update(time, delta) {
    if (!this.gameOverTriggered) {
      this.handleMovement();
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
    let flipX = false;
    if (Math.abs(vx) > Math.abs(vy)) {
      // Fila lateral compartida 8-15; el lado lo da flipX.
      anim = 'walk-side';
      flipX = vx < 0;
    } else {
      anim = vy > 0 ? 'walk-down' : 'walk-up';
      flipX = false;
    }
    this.player.setFlipX(flipX);
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
// Decisión 1.2: se mantiene scale.mode RESIZE (no CENTER_BOTH) porque UIPlugin
// recalcula el HUD escuchando Phaser.Scale.Events.RESIZE; con CENTER_BOTH ese
// evento no se dispara igual y el HUD quedaría sin re-layout. El centrado del
// mapa se resuelve con cameras.main.centerOn() en buildMap() + startFollow en
// buildPlayer(), sin romper el HUD.
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