/**
 * Chronos space background
 * - Cyberpunk holographic Earth + neon zodiac ring
 * - Decorative zodiac art and wheel zoom Earth
 * - Pulse / day-night theme; persists across soft navigations
 */
(function () {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const STATE_KEY = 'chrono_space_bg_v5_zodiac';
  let pendingTheme = null;
  let pendingPulse = null;

  // Asset names for the decorative constellation artwork.
  const ZODIAC_ART = [
    'capricorn',
    'aquarius',
    'scorpio',
    'pisces',
    'aries',
    'taurus',
    'sagittarius',
    'gemini',
    'cancer',
    'leo',
    'virgo',
    'libra',
  ];

  function assetUrl(path) {
    const base = (window.APP_BASE || '').replace(/\/$/, '');
    return base + '/assets/' + path.replace(/^\//, '');
  }

  function loadState() {
    try {
      return JSON.parse(sessionStorage.getItem(STATE_KEY) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function saveState(state) {
    try {
      sessionStorage.setItem(STATE_KEY, JSON.stringify(state));
    } catch (e) {}
  }

  function makeStarTexture() {
    const size = 128;
    const c = document.createElement('canvas');
    c.width = size;
    c.height = size;
    const ctx = c.getContext('2d');
    const g = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(0.1, 'rgba(255,255,255,0.92)');
    g.addColorStop(0.28, 'rgba(210,230,255,0.45)');
    g.addColorStop(0.55, 'rgba(160,200,255,0.12)');
    g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, size, size);
    const tex = new THREE.CanvasTexture(c);
    tex.needsUpdate = true;
    return tex;
  }

  function placeOnSphere(lon, lat, radius) {
    const phi = ((90 - lat) * Math.PI) / 180;
    const theta = ((lon + 180) * Math.PI) / 180;
    return new THREE.Vector3(
      -radius * Math.sin(phi) * Math.cos(theta),
      radius * Math.cos(phi),
      radius * Math.sin(phi) * Math.sin(theta)
    );
  }

  /** Tint zodiac art into cyan/magenta hologram silhouette */
  function neonizeZodiac(image) {
    const w = image.width || 256;
    const h = image.height || 256;
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const ctx = c.getContext('2d');
    ctx.drawImage(image, 0, 0, w, h);
    const img = ctx.getImageData(0, 0, w, h);
    const d = img.data;
    for (let i = 0; i < d.length; i += 4) {
      const a = d[i + 3];
      if (a < 12) continue;
      const lum = (d[i] * 0.3 + d[i + 1] * 0.5 + d[i + 2] * 0.2) / 255;
      // Magenta core + cyan rim
      const edge = lum > 0.55 ? 1 : lum > 0.25 ? 0.55 : 0.2;
      d[i] = Math.min(255, Math.round(40 + edge * 80 + (1 - edge) * 200));
      d[i + 1] = Math.min(255, Math.round(220 * edge + 40));
      d[i + 2] = Math.min(255, Math.round(255 * edge + (1 - edge) * 180));
      d[i + 3] = Math.min(255, Math.round(a * (0.55 + lum * 0.45)));
    }
    ctx.putImageData(img, 0, 0);

    // Soft scan lines
    ctx.fillStyle = 'rgba(0,255,230,0.08)';
    for (let y = 0; y < h; y += 4) {
      ctx.fillRect(0, y, w, 1);
    }
    const tex = new THREE.CanvasTexture(c);
    tex.needsUpdate = true;
    return tex;
  }

  function makeReticleTexture() {
    const s = 128;
    const c = document.createElement('canvas');
    c.width = s;
    c.height = s;
    const ctx = c.getContext('2d');
    ctx.clearRect(0, 0, s, s);
    ctx.strokeStyle = 'rgba(0,255,230,0.85)';
    ctx.lineWidth = 2;
    const m = 18;
    // Corner brackets
    [
      [m, m, m + 22, m, m, m + 22],
      [s - m, m, s - m - 22, m, s - m, m + 22],
      [m, s - m, m + 22, s - m, m, s - m - 22],
      [s - m, s - m, s - m - 22, s - m, s - m, s - m - 22],
    ].forEach((p) => {
      ctx.beginPath();
      ctx.moveTo(p[0], p[1]);
      ctx.lineTo(p[2], p[3]);
      ctx.moveTo(p[0], p[1]);
      ctx.lineTo(p[4], p[5]);
      ctx.stroke();
    });
    ctx.strokeStyle = 'rgba(255,0,170,0.35)';
    ctx.beginPath();
    ctx.arc(s / 2, s / 2, 28, 0, Math.PI * 2);
    ctx.stroke();
    const tex = new THREE.CanvasTexture(c);
    tex.needsUpdate = true;
    return tex;
  }

  function buildConstellations(loader) {
    const root = new THREE.Group();
    const radius = 92;
    const count = ZODIAC_ART.length;
    const centers = [];
    const nodes = [];
    const reticleTex = makeReticleTexture();

    ZODIAC_ART.forEach((name, index) => {
      const lon = -180 + (360 / count) * (index + 0.5);
      const lat = ((index % 3) - 1) * 16;
      const center = placeOnSphere(lon, lat, radius);
      centers.push(center.clone());
      const outward = center.clone().normalize();
      const baseOp = 0.22;
      const retOp = 0.12;

      const retMat = new THREE.SpriteMaterial({
        map: reticleTex,
        transparent: true,
        opacity: retOp,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
        toneMapped: false,
        color: 0x66fff0,
      });
      const reticle = new THREE.Sprite(retMat);
      reticle.position.copy(center).add(outward.clone().multiplyScalar(1.2));
      const retBase = 18;
      reticle.scale.set(retBase, retBase, 1);
      root.add(reticle);

      const node = {
        reticle: reticle,
        sprite: null,
        baseOpacity: baseOp,
        baseH: 20,
      };
      nodes.push(node);

      loader.load(
        assetUrl('textures/zodiac/' + name + '.png'),
        (tex) => {
          const img = tex.image;
          const neon = img ? neonizeZodiac(img) : tex;
          if (neon.colorSpace !== undefined) neon.colorSpace = THREE.SRGBColorSpace;
          else neon.encoding = THREE.sRGBEncoding;
          neon.anisotropy = 4;

          const mat = new THREE.SpriteMaterial({
            map: neon,
            transparent: true,
            opacity: baseOp,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            toneMapped: false,
            color: 0x88aacc,
          });
          const sprite = new THREE.Sprite(mat);
          sprite.position.copy(center).add(outward.clone().multiplyScalar(2));
          const aspect =
            neon.image && neon.image.width && neon.image.height
              ? neon.image.width / neon.image.height
              : 1.15;
          const h = node.baseH;
          sprite.scale.set(h * aspect, h, 1);
          node.sprite = sprite;
          root.add(sprite);
        }
      );
    });

    const linePos = [];
    for (let i = 0; i < centers.length; i++) {
      const a = centers[i];
      const b = centers[(i + 1) % centers.length];
      linePos.push(a.x, a.y, a.z, b.x, b.y, b.z);
    }
    const lineGeo = new THREE.BufferGeometry();
    lineGeo.setAttribute('position', new THREE.Float32BufferAttribute(linePos, 3));
    const links = new THREE.LineSegments(
      lineGeo,
      new THREE.LineBasicMaterial({
        color: 0x00ffe0,
        transparent: true,
        opacity: 0.14,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    root.add(links);
    root.userData.nodes = nodes;
    root.userData.links = links;

    return root;
  }

  function makeHoloGridTexture() {
    const w = 1024;
    const h = 512;
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const ctx = c.getContext('2d');
    ctx.clearRect(0, 0, w, h);

    const g = ctx.createLinearGradient(0, 0, 0, h);
    g.addColorStop(0, 'rgba(0, 40, 55, 0.55)');
    g.addColorStop(0.5, 'rgba(0, 70, 90, 0.35)');
    g.addColorStop(1, 'rgba(20, 0, 40, 0.5)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);

    ctx.strokeStyle = 'rgba(0, 255, 230, 0.28)';
    ctx.lineWidth = 1;
    for (let i = 1; i < 12; i++) {
      const y = (h / 12) * i;
      ctx.beginPath();
      ctx.moveTo(0, y);
      ctx.lineTo(w, y);
      ctx.stroke();
    }
    for (let i = 1; i < 24; i++) {
      const x = (w / 24) * i;
      ctx.beginPath();
      ctx.moveTo(x, 0);
      ctx.lineTo(x, h);
      ctx.stroke();
    }

    ctx.strokeStyle = 'rgba(255, 0, 170, 0.12)';
    const hexH = 28;
    for (let y = 0; y < h; y += hexH) {
      for (let x = 0; x < w; x += 32) {
        const ox = (Math.floor(y / hexH) % 2) * 16;
        ctx.strokeRect(x + ox, y, 30, hexH - 2);
      }
    }

    ctx.fillStyle = 'rgba(0, 255, 255, 0.06)';
    for (let y = 0; y < h; y += 6) {
      ctx.fillRect(0, y, w, 1);
    }

    const tex = new THREE.CanvasTexture(c);
    tex.needsUpdate = true;
    tex.wrapS = THREE.RepeatWrapping;
    tex.wrapT = THREE.ClampToEdgeWrapping;
    return tex;
  }

  function neonizeEarthMap(image) {
    const w = 1024;
    const h = 512;
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const ctx = c.getContext('2d');
    ctx.drawImage(image, 0, 0, w, h);
    const img = ctx.getImageData(0, 0, w, h);
    const d = img.data;
    const out = ctx.createImageData(w, h);
    const o = out.data;

    for (let i = 0; i < d.length; i += 4) {
      const r = d[i];
      const g = d[i + 1];
      const b = d[i + 2];
      const lum = (r * 0.3 + g * 0.5 + b * 0.2) / 255;
      const isLand = g > b * 0.85 && g > 40 && lum > 0.18 && lum < 0.85;
      const isIce = lum > 0.78;
      const isCoast = !isLand && lum > 0.22 && lum < 0.55;

      if (isLand) {
        o[i] = 40;
        o[i + 1] = 255;
        o[i + 2] = 220;
        o[i + 3] = 200;
      } else if (isIce) {
        o[i] = 180;
        o[i + 1] = 255;
        o[i + 2] = 255;
        o[i + 3] = 160;
      } else if (isCoast) {
        o[i] = 255;
        o[i + 1] = 0;
        o[i + 2] = 200;
        o[i + 3] = 90;
      } else {
        o[i] = 0;
        o[i + 1] = 40;
        o[i + 2] = 60;
        o[i + 3] = 35;
      }
    }
    ctx.putImageData(out, 0, 0);

    ctx.strokeStyle = 'rgba(0,255,230,0.2)';
    ctx.lineWidth = 1;
    for (let i = 1; i < 18; i++) {
      ctx.beginPath();
      ctx.moveTo(0, (h / 18) * i);
      ctx.lineTo(w, (h / 18) * i);
      ctx.stroke();
    }
    for (let i = 1; i < 36; i++) {
      ctx.beginPath();
      ctx.moveTo((w / 36) * i, 0);
      ctx.lineTo((w / 36) * i, h);
      ctx.stroke();
    }

    const tex = new THREE.CanvasTexture(c);
    tex.needsUpdate = true;
    return tex;
  }

  function initSintSpaceBackground() {
    if (window.__CHRONOS_BG_RUNTIME__) return;

    const host = document.getElementById('chronoAmbient');
    if (!host || reduceMotion || typeof THREE === 'undefined') return;

    const saved = loadState();
    const warm = sessionStorage.getItem('chrono_bg_warm') === '1';
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(50, 1, 0.1, 2000);
    camera.position.set(saved.camX || 0, saved.camY != null ? saved.camY : 0.4, 22);

    const renderer = new THREE.WebGLRenderer({
      antialias: true,
      alpha: true,
      powerPreference: 'high-performance',
    });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setClearColor(0x000000, 0);
    if (renderer.outputColorSpace !== undefined) {
      renderer.outputColorSpace = THREE.SRGBColorSpace;
    }
    renderer.domElement.style.opacity = warm ? '1' : '0';
    renderer.domElement.style.transition = warm ? 'none' : 'opacity .35s ease';
    if (!host.querySelector('canvas')) {
      host.appendChild(renderer.domElement);
    }

    const starMap = makeStarTexture();

    function makeStars(count, spread, size, opacity, tint) {
      const positions = new Float32Array(count * 3);
      for (let i = 0; i < count; i++) {
        const r = spread * (0.5 + Math.random() * 0.5);
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.acos(2 * Math.random() - 1);
        positions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
        positions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
        positions[i * 3 + 2] = r * Math.cos(phi);
      }
      const geo = new THREE.BufferGeometry();
      geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
      return new THREE.Points(
        geo,
        new THREE.PointsMaterial({
          color: tint,
          map: starMap,
          alphaMap: starMap,
          size,
          sizeAttenuation: true,
          transparent: true,
          opacity,
          depthWrite: false,
          blending: THREE.AdditiveBlending,
          alphaTest: 0.002,
        })
      );
    }

    const starsFar = makeStars(3600, 230, 1.35, 0.75, 0xe8f0ff);
    const starsMid = makeStars(1000, 140, 1.9, 0.7, 0xd6e4ff);
    scene.add(starsFar, starsMid);

    const loader = new THREE.TextureLoader();
    const constellations = buildConstellations(loader);
    scene.add(constellations);

    scene.add(new THREE.AmbientLight(0x204060, 0.8));
    const sun = new THREE.DirectionalLight(0x66fff0, 0.9);
    sun.position.set(8, 4, 10);
    scene.add(sun);
    const fill = new THREE.DirectionalLight(0xff00aa, 0.35);
    fill.position.set(-6, -2, -4);
    scene.add(fill);

    const earthGroup = new THREE.Group();
    const adminDashboard = document.body.classList.contains('admin-dashboard');
    if (adminDashboard) {
      earthGroup.scale.setScalar(0.68);
    }
    earthGroup.position.set(0, -0.2, 0);
    scene.add(earthGroup);

    const holoCore = new THREE.Mesh(
      new THREE.SphereGeometry(5.05, 64, 64),
      new THREE.MeshBasicMaterial({
        color: 0x031018,
        transparent: true,
        opacity: 0.55,
        depthWrite: false,
      })
    );
    earthGroup.add(holoCore);

    const holoGridTex = makeHoloGridTexture();
    const earthMat = new THREE.MeshBasicMaterial({
      map: holoGridTex,
      transparent: true,
      opacity: 0.85,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      side: THREE.DoubleSide,
    });
    const earth = new THREE.Mesh(new THREE.SphereGeometry(5.2, 96, 96), earthMat);
    earthGroup.add(earth);

    const wire = new THREE.Mesh(
      new THREE.SphereGeometry(5.22, 36, 24),
      new THREE.MeshBasicMaterial({
        color: 0x00ffe0,
        wireframe: true,
        transparent: true,
        opacity: 0.18,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    earthGroup.add(wire);

    const atmos = new THREE.Mesh(
      new THREE.SphereGeometry(5.55, 64, 64),
      new THREE.MeshBasicMaterial({
        color: 0x00e5ff,
        transparent: true,
        opacity: 0.08,
        side: THREE.BackSide,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    earthGroup.add(atmos);

    const atmosMagenta = new THREE.Mesh(
      new THREE.SphereGeometry(5.7, 48, 48),
      new THREE.MeshBasicMaterial({
        color: 0xff00aa,
        transparent: true,
        opacity: 0.04,
        side: THREE.BackSide,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    earthGroup.add(atmosMagenta);

    const dataRing = new THREE.Mesh(
      new THREE.TorusGeometry(5.35, 0.03, 8, 128),
      new THREE.MeshBasicMaterial({
        color: 0x66fff0,
        transparent: true,
        opacity: 0.55,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    dataRing.rotation.x = Math.PI / 2;
    earthGroup.add(dataRing);

    const ring = new THREE.Mesh(
      new THREE.TorusGeometry(7.4, 0.018, 12, 180),
      new THREE.MeshBasicMaterial({
        color: 0x00ffe0,
        transparent: true,
        opacity: 0.35,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    ring.rotation.x = Math.PI / 2.35;
    ring.rotation.y = 0.2;
    earthGroup.add(ring);

    const ringB = new THREE.Mesh(
      new THREE.TorusGeometry(8.1, 0.012, 12, 160),
      new THREE.MeshBasicMaterial({
        color: 0xff00aa,
        transparent: true,
        opacity: 0.22,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    ringB.rotation.x = Math.PI / 2.1;
    ringB.rotation.z = 0.4;
    earthGroup.add(ringB);

    const scan = new THREE.Mesh(
      new THREE.RingGeometry(4.6, 5.35, 64),
      new THREE.MeshBasicMaterial({
        color: 0x66fff0,
        transparent: true,
        opacity: 0.35,
        side: THREE.DoubleSide,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    scan.rotation.x = Math.PI / 2;
    earthGroup.add(scan);

    // Pulse flash shell
    const pulseShell = new THREE.Mesh(
      new THREE.SphereGeometry(5.9, 48, 48),
      new THREE.MeshBasicMaterial({
        color: 0x66fff0,
        transparent: true,
        opacity: 0,
        side: THREE.BackSide,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
      })
    );
    earthGroup.add(pulseShell);

    let revealed = false;
    function revealCanvas() {
      if (revealed) return;
      revealed = true;
      sessionStorage.setItem('chrono_bg_warm', '1');
      requestAnimationFrame(() => {
        applyThemeVisuals(currentTheme);
        renderer.domElement.style.opacity = host.classList.contains('chrono-ambient--day') ? '0.55' : '1';
      });
    }
    setTimeout(revealCanvas, 400);

    loader.load(
      assetUrl('textures/planets/earth_atmos_2048.jpg'),
      (map) => {
        const img = map.image;
        if (!img) {
          revealCanvas();
          return;
        }
        const neon = neonizeEarthMap(img);
        earthMat.map = neon;
        earthMat.needsUpdate = true;
        revealCanvas();
      },
      undefined,
      revealCanvas
    );

    const pointer = {
      x: saved.ptrX || 0,
      y: saved.ptrY || 0,
      tx: saved.ptrX || 0,
      ty: saved.ptrY || 0,
      midHeld: false,
    };
    let earthZoom = saved.earthZoom != null ? saved.earthZoom : 1;
    earthZoom = Math.max(0.55, Math.min(1.75, earthZoom));
    earthGroup.scale.setScalar(earthZoom);

    function isScrollableUi(target) {
      if (!target || !(target instanceof Element)) return false;
      if (target.closest('.app-content, .app-sidebar, .app-topbar, .dropdown-menu, .modal, .toast-container, .form-control, .table-responsive-card, select, textarea, input')) {
        // Allow zoom with middle-button held even over UI
        return !pointer.midHeld;
      }
      return false;
    }

    window.addEventListener(
      'pointermove',
      (e) => {
        pointer.tx = (e.clientX / window.innerWidth) * 2 - 1;
        pointer.ty = (e.clientY / window.innerHeight) * 2 - 1;
        pointer.midHeld = (e.buttons & 4) === 4;
      },
      { passive: true }
    );

    window.addEventListener(
      'pointerdown',
      (e) => {
        if (e.button === 1) {
          pointer.midHeld = true;
          e.preventDefault(); // block autoscroll
        }
      },
      { passive: false }
    );
    window.addEventListener(
      'pointerup',
      (e) => {
        if (e.button === 1) pointer.midHeld = false;
      },
      { passive: true }
    );

    // Middle mouse wheel → zoom holographic Earth
    window.addEventListener(
      'wheel',
      (e) => {
        const mid = pointer.midHeld || e.button === 1;
        // Treat scroll-wheel as "中键滚轮"; zoom when mid held OR not over scrollable UI
        if (!mid && isScrollableUi(e.target)) return;
        const delta = e.deltaY;
        if (!delta) return;
        e.preventDefault();
        const next = earthZoom * (delta > 0 ? 0.94 : 1.06);
        earthZoom = Math.max(0.55, Math.min(1.75, next));
        earthGroup.scale.setScalar(earthZoom);
      },
      { passive: false }
    );

    function resize() {
      const w = window.innerWidth;
      const h = window.innerHeight;
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h, false);
      renderer.domElement.style.width = w + 'px';
      renderer.domElement.style.height = h + 'px';
    }
    resize();
    window.addEventListener('resize', resize);

    let raf = 0;
    let t = saved.t || 0;
    let saveTick = 0;
    const earthBaseY = saved.earthBaseY != null ? saved.earthBaseY : 0.8;
    const skyBaseY = saved.skyBaseY != null ? saved.skyBaseY : 0;
    const skyBaseX = saved.skyBaseX != null ? saved.skyBaseX : 0;

    let pulse = 0;
    let pulseKind = 'in';
    let currentTheme = 'night';

    const SENSE_YAW = 2.15;
    const SENSE_PITCH = 1.05;
    const CAM_SENSE_X = 2.4;
    const CAM_SENSE_Y = 1.6;

    const base = {
      earthOp: adminDashboard ? 0.48 : 0.85,
      wireOp: adminDashboard ? 0.09 : 0.18,
      atmosOp: 0.08,
      magentaOp: 0.04,
      scanOp: 0.35,
      starsFar: 0.75,
      starsMid: 0.7,
      links: adminDashboard ? 0.07 : 0.14,
    };

    function applyThemeVisuals(theme) {
      currentTheme = theme === 'day' ? 'day' : 'night';
      host.classList.toggle('chrono-ambient--day', currentTheme === 'day');
      const day = currentTheme === 'day';
      const mul = day ? 0.72 : 1;
      earthMat.opacity = base.earthOp * (day ? 0.82 : 1);
      wire.material.opacity = base.wireOp * mul;
      atmos.material.opacity = base.atmosOp * (day ? 0.88 : 1);
      atmosMagenta.material.opacity = day ? 0.03 : base.magentaOp;

      // Ensure stars and constellations are clearly visible in day and night modes
      if (day) {
        starsFar.material.color.setHex(0x0284c7);
        starsMid.material.color.setHex(0x0369a1);
        starsFar.material.blending = THREE.NormalBlending;
        starsMid.material.blending = THREE.NormalBlending;
        starsFar.material.opacity = 0.82;
        starsMid.material.opacity = 0.88;
        starsFar.material.size = 2.0;
        starsMid.material.size = 2.6;
      } else {
        starsFar.material.color.setHex(0xe8f0ff);
        starsMid.material.color.setHex(0xd6e4ff);
        starsFar.material.blending = THREE.AdditiveBlending;
        starsMid.material.blending = THREE.AdditiveBlending;
        starsFar.material.opacity = base.starsFar;
        starsMid.material.opacity = base.starsMid;
        starsFar.material.size = 1.35;
        starsMid.material.size = 1.9;
      }
      starsFar.material.needsUpdate = true;
      starsMid.material.needsUpdate = true;

      if (constellations.userData.links) {
        if (day) {
          constellations.userData.links.material.color.setHex(0x0f766e);
          constellations.userData.links.material.blending = THREE.NormalBlending;
          constellations.userData.links.material.opacity = 0.45;
        } else {
          constellations.userData.links.material.color.setHex(0x00ffe0);
          constellations.userData.links.material.blending = THREE.AdditiveBlending;
          constellations.userData.links.material.opacity = base.links;
        }
        constellations.userData.links.material.needsUpdate = true;
      }

      const nodes = constellations.userData.nodes || [];
      nodes.forEach((node) => {
        if (node.sprite) {
          node.sprite.material.blending = day ? THREE.NormalBlending : THREE.AdditiveBlending;
          node.sprite.material.color.setHex(day ? 0x0284c7 : 0x88aacc);
          node.sprite.material.needsUpdate = true;
        }
        if (node.reticle) {
          node.reticle.material.blending = day ? THREE.NormalBlending : THREE.AdditiveBlending;
          node.reticle.material.color.setHex(day ? 0x0f766e : 0x66fff0);
          node.reticle.material.needsUpdate = true;
        }
      });

      if (revealed) {
        renderer.domElement.style.opacity = day ? '0.85' : '1';
      }
    }

    function triggerPulse(kind) {
      pulseKind = kind === 'out' ? 'out' : 'in';
      pulse = 1;
      pulseShell.material.color.setHex(pulseKind === 'out' ? 0xff00aa : 0x66fff0);
      scan.material.color.setHex(pulseKind === 'out' ? 0xff66cc : 0x66fff0);
    }

    function persist() {
      saveState({
        t,
        earthBaseY,
        skyBaseY,
        skyBaseX,
        earthZoom,
        camX: camera.position.x,
        camY: camera.position.y,
        ptrX: pointer.x,
        ptrY: pointer.y,
      });
    }

    function frame() {
      t += 0.0035;
      pointer.x += (pointer.tx - pointer.x) * 0.08;
      pointer.y += (pointer.ty - pointer.y) * 0.08;

      constellations.rotation.y = skyBaseY + t * 0.018;
      constellations.rotation.x = skyBaseX + Math.sin(t * 0.2) * 0.03;
      starsFar.rotation.copy(constellations.rotation);
      starsMid.rotation.copy(constellations.rotation);

      const dayMul = currentTheme === 'day' ? 0.8 : 1;
      const nodes = constellations.userData.nodes || [];
      nodes.forEach((node, i) => {
        const breath = Math.sin(t * 1.2 + i * 0.5) * 0.04;
        const op = (node.baseOpacity + breath) * dayMul;
        if (node.sprite) {
          node.sprite.material.opacity = Math.min(1, Math.max(0.08, op));
        }
        if (node.reticle) {
          node.reticle.material.opacity = (currentTheme === 'day' ? 0.25 : 0.12);
        }
      });

      earth.rotation.y = earthBaseY + t * 0.045 + pointer.x * SENSE_YAW;
      earth.rotation.x = pointer.y * SENSE_PITCH;
      holoCore.rotation.copy(earth.rotation);
      wire.rotation.copy(earth.rotation);
      atmos.rotation.copy(earth.rotation);
      atmosMagenta.rotation.copy(earth.rotation);
      ring.rotation.z = t * 0.05;
      ringB.rotation.z = -t * 0.03;
      scan.position.y = Math.sin(t * 1.4) * 4.2;
      const scanBase = currentTheme === 'day' ? 0.14 : 0.22;
      scan.material.opacity = scanBase + Math.abs(Math.sin(t * 1.4)) * (currentTheme === 'day' ? 0.16 : 0.25) + pulse * 0.45;
      dataRing.rotation.z = t * 0.6;
      dataRing.rotation.y = t * 0.2;

      if (pulse > 0.01) {
        pulse *= 0.9;
        pulseShell.material.opacity = pulse * 0.55;
        pulseShell.scale.setScalar(1 + (1 - pulse) * 0.35);
        wire.material.opacity = (currentTheme === 'day' ? base.wireOp * 0.42 : base.wireOp) + pulse * 0.45;
        atmos.material.opacity = (currentTheme === 'day' ? base.atmosOp * 0.85 : base.atmosOp) + pulse * 0.35;
      } else {
        pulse = 0;
        pulseShell.material.opacity = 0;
        pulseShell.scale.setScalar(1);
      }

      earthGroup.rotation.y = pointer.x * 0.35;
      earthGroup.rotation.x = -pointer.y * 0.22;

      camera.position.x = pointer.x * CAM_SENSE_X;
      camera.position.y = 0.4 - pointer.y * CAM_SENSE_Y;
      camera.lookAt(0, 0, 0);

      renderer.render(scene, camera);

      saveTick += 1;
      if (saveTick % 30 === 0) persist();

      if (isGlobeEnabled) {
        raf = requestAnimationFrame(frame);
      } else {
        raf = null;
      }
    }

    if (isGlobeEnabled) {
      raf = requestAnimationFrame(frame);
    } else {
      raf = null;
      if (host) host.style.display = 'none';
    }

    window.addEventListener('pagehide', persist);
    window.addEventListener('beforeunload', persist);

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        if (raf) cancelAnimationFrame(raf);
        raf = null;
      } else if (isGlobeEnabled && !raf) {
        lastNow = performance.now();
        raf = requestAnimationFrame(frame);
      }
    });

    window.__CHRONOS_BG_RUNTIME__ = {
      persist: persist,
      alive: true,
      pulse: triggerPulse,
      setTheme: applyThemeVisuals,
      setEnabled: function (val) {
        isGlobeEnabled = val !== false;
        if (host) host.style.display = isGlobeEnabled ? '' : 'none';
        if (isGlobeEnabled && !raf) {
          lastNow = performance.now();
          raf = requestAnimationFrame(frame);
        } else if (!isGlobeEnabled && raf) {
          cancelAnimationFrame(raf);
          raf = null;
        }
      },
    };

    // Apply queued theme / pulse
    const bootTheme =
      pendingTheme ||
      document.documentElement.getAttribute('data-theme') ||
      localStorage.getItem('chrono_theme') ||
      'night';
    applyThemeVisuals(bootTheme);
    pendingTheme = null;
    if (pendingPulse) {
      triggerPulse(pendingPulse);
      pendingPulse = null;
    }
  }

  let isGlobeEnabled = true;
  try {
    const saved = localStorage.getItem('chrono_globe');
    if (saved === 'off') {
      isGlobeEnabled = false;
    }
  } catch (e) {}

  function setEnabled(enabled) {
    isGlobeEnabled = enabled !== false;
    try {
      localStorage.setItem('chrono_globe', isGlobeEnabled ? 'on' : 'off');
    } catch (e) {}
    const host = document.getElementById('chronoAmbient');
    if (host) {
      host.style.display = isGlobeEnabled ? '' : 'none';
    }
    if (window.__CHRONOS_BG_RUNTIME__ && typeof window.__CHRONOS_BG_RUNTIME__.setEnabled === 'function') {
      window.__CHRONOS_BG_RUNTIME__.setEnabled(isGlobeEnabled);
    }
  }

  function pulse(kind) {
    if (window.__CHRONOS_BG_RUNTIME__ && typeof window.__CHRONOS_BG_RUNTIME__.pulse === 'function') {
      window.__CHRONOS_BG_RUNTIME__.pulse(kind);
    } else {
      pendingPulse = kind === 'out' ? 'out' : 'in';
    }
  }

  function setTheme(theme) {
    const t = theme === 'day' ? 'day' : 'night';
    if (window.__CHRONOS_BG_RUNTIME__ && typeof window.__CHRONOS_BG_RUNTIME__.setTheme === 'function') {
      window.__CHRONOS_BG_RUNTIME__.setTheme(t);
    } else {
      pendingTheme = t;
      const host = document.getElementById('chronoAmbient');
      if (host) host.classList.toggle('chrono-ambient--day', t === 'day');
    }
  }

  window.ChronosSpaceBg = {
    init: initSintSpaceBackground,
    pulse: pulse,
    setTheme: setTheme,
    setEnabled: setEnabled,
    isEnabled: function () {
      return isGlobeEnabled;
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSintSpaceBackground);
  } else {
    initSintSpaceBackground();
  }
})();
