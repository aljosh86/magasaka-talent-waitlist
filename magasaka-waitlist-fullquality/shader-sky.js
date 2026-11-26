/* ShaderSky, vanilla port of the shadcn "shader-sky" component (React wrapper removed; the WebGL engine is unchanged
   apart from a configurable pixel cap). Volumetric clouds drifting across a sky, paused off screen and in hidden tabs,
   a still frame under reduced motion, and recovery from a lost WebGL context.
   window.createShaderSky(canvas, options) -> {setOptions, destroy} | null */
(() => {
  'use strict';
  const LIGHT_COLORS = ['#2478C8', '#8ECBF2', '#F7FBFF', '#C5D8EC'];

  const VERT = `
attribute vec2 a_position;
void main() {
  gl_Position = vec4(a_position, 0.0, 1.0);
}
`;

  const FRAG = `
precision highp float;

uniform vec2 u_resolution;
uniform float u_time;
uniform float u_speed;
uniform float u_coverage;
uniform float u_intensity;
uniform float u_amount;
uniform float u_scale;
uniform float u_variation;
uniform float u_glass;
uniform float u_glassSize;
uniform vec3 u_c1;
uniform vec3 u_c2;
uniform vec3 u_c3;
uniform vec3 u_c4;
uniform vec2 u_mouse;
uniform float u_flash;
uniform vec2 u_flashPos;
uniform sampler2D u_noise;

const float BASE = 1.0;
const float TOP = 1.9;
const float FAR = 34.0;
const int STEPS = 36;
const int LIGHT_STEPS = 3;
const float TILT = 0.46;

float hash(vec2 p) {
  vec3 p3 = fract(vec3(p.xyx) * 0.1031);
  p3 += dot(p3, p3.yzx + 33.33);
  return fract((p3.x + p3.y) * p3.z);
}

float noise2(vec2 p) {
  vec2 i = floor(p);
  vec2 f = fract(p);
  f = f * f * (3.0 - 2.0 * f);
  return texture2D(u_noise, (i + f + 0.5) / 256.0).x;
}

float noise3(vec3 p) {
  vec3 i = floor(p);
  vec3 f = fract(p);
  f = f * f * (3.0 - 2.0 * f);
  vec2 uv = i.xy + vec2(37.0, 239.0) * i.z + f.xy;
  vec2 rg = texture2D(u_noise, (uv + 0.5) / 256.0).yx;
  return mix(rg.x, rg.y, f.z);
}

float luma(vec3 c) {
  return dot(c, vec3(0.2126, 0.7152, 0.0722));
}

float phaseHG(float mu, float g) {
  float g2 = g * g;
  return (1.0 - g2) / pow(1.0 + g2 - 2.0 * g * mu, 1.5);
}

float coverageAt(vec2 xz) {
  float weather = noise2(xz * mix(0.14, 0.32, u_amount) + 13.7);
  float cov = mix(0.22, 0.72, u_coverage);
  return clamp(cov + (weather - 0.5) * mix(1.0, 0.55, u_amount), 0.0, 1.0);
}

float density(vec3 q, float hf, float cov, float t, float fine) {
  float shape = smoothstep(0.0, 0.14, hf) * (1.0 - smoothstep(0.35, 1.0, hf));
  float n = noise3(q) * 0.52;
  n += noise3(q * 2.03 + 3.1) * 0.27;
  n += noise3(q * 4.07 + 7.7) * 0.14;
  n += fine * noise3(q * 8.3 + 1.9) * 0.1;
  n /= 0.93 + fine * 0.1;
  float d = n * shape;
  d = clamp((d - (1.0 - cov)) / max(cov, 0.05), 0.0, 1.0);
  if (fine > 0.5 && d > 0.0) {
    float e = noise3(q * 6.1 + vec3(0.0, -t * 0.6, 0.0));
    d = clamp(d - e * mix(0.08, 0.26, u_variation) * (1.0 - d), 0.0, 1.0);
  }
  return d;
}

void main() {
  float aspect = u_resolution.x / max(u_resolution.y, 1.0);
  float t = u_time * u_speed;
  float cell = max(u_glassSize, 2.0);

  vec2 uv = gl_FragCoord.xy / u_resolution.xy;
  vec2 glassLocal = fract(gl_FragCoord.xy / cell) - 0.5;
  if (u_glass > 0.5) {
    uv += glassLocal * 0.0032;
  }

  vec2 s = (uv - 0.5) * vec2(aspect, 1.0);
  s += (u_mouse - 0.5) * vec2(0.06, 0.03);
  vec3 dir = normalize(vec3(s.x, s.y + TILT, 1.0));
  vec3 sunDir = normalize(vec3(0.85, 0.95, 1.0));
  float mu = dot(dir, sunDir);

  float storm = smoothstep(0.02, -0.12, luma(u_c3) - luma(u_c2));
  float sunAmt = 1.0 - storm * 0.75;
  vec3 sunTint = mix(vec3(1.0, 0.96, 0.88), u_c3, 0.3);

  float up = clamp(dir.y, 0.0, 1.0);
  vec3 sky = mix(u_c2, u_c1, pow(smoothstep(0.0, 0.85, up), 0.6));
  vec3 haze = mix(u_c2, vec3(1.0), 0.3 * luma(u_c2));
  sky = mix(sky, haze, exp(-up * 9.0) * 0.6);
  float glow = pow(max(mu, 0.0), 8.0) * 0.14 + pow(max(mu, 0.0), 120.0) * 0.3;
  sky += sunTint * glow * sunAmt;

  float freq = mix(2.2, 0.9, u_scale);
  vec3 drift = vec3(t * 0.5, 0.0, t * 0.2);
  float sigma = mix(10.0, 26.0, u_intensity);
  vec3 sunLight = u_c3 * mix(1.12, 1.0, storm) * mix(vec3(1.0), sunTint, sunAmt * 0.5);
  float phase = mix(phaseHG(mu, -0.2), phaseHG(mu, 0.7), 0.12);

  vec3 L = vec3(0.0);
  float T = 1.0;
  float hitAt = 0.0;
  if (dir.y > 0.02) {
    float t0 = BASE / dir.y;
    if (t0 < FAR) {
      float t1 = min(TOP / dir.y, t0 + 5.0);
      float dt = (t1 - t0) / float(STEPS);
      float tt = t0 + dt * hash(gl_FragCoord.xy);
      for (int i = 0; i < STEPS; i++) {
        vec3 p = dir * tt;
        vec3 q = p * freq + drift;
        float hf = (p.y - BASE) / (TOP - BASE);
        float cov = coverageAt(q.xz);
        float d = cov > 0.02 ? density(q, hf, cov, t, 1.0) : 0.0;
        if (d > 0.003) {
          if (hitAt == 0.0) hitAt = tt;
          float od = 0.0;
          for (int j = 0; j < LIGHT_STEPS; j++) {
            vec3 lp = p + sunDir * (0.05 + float(j) * 0.12);
            float lh = (lp.y - BASE) / (TOP - BASE);
            od += density(lp * freq + drift, lh, cov, t, 0.0);
          }
          od *= 0.12 * sigma * 0.5;
          float beer = max(exp(-od), exp(-od * 0.25) * 0.6);
          float powder = 1.0 - exp(-d * 5.0);
          vec3 ambient = mix(u_c4 * mix(0.82, 0.62, storm), mix(u_c1, u_c3, 0.6), hf);
          vec3 lum = sunLight * beer * phase * mix(1.0, powder, 0.35) + ambient * 0.72;
          float dT = exp(-d * sigma * dt);
          L += T * (1.0 - dT) * lum;
          T *= dT;
          if (T < 0.02) break;
        }
        tt += dt;
      }
      float fog = 1.0 - exp(-max(hitAt, t0) * 0.05);
      L = mix(L, haze * (1.0 - T), fog * 0.85);
    }
  }

  vec3 col = sky * T + L;

  if (u_flash > 0.0) {
    vec2 fp = vec2(u_flashPos.x * aspect, u_flashPos.y - 0.5);
    float bloom = exp(-dot(s - fp, s - fp) * 3.2);
    vec3 bolt = vec3(0.86, 0.9, 1.0);
    col += bolt * u_flash * (bloom * (1.0 - T) * 0.95 + 0.05);
  }

  if (u_glass > 0.5) {
    float pane = smoothstep(0.50, 0.22, length(glassLocal));
    col *= mix(0.93, 1.0, pane);
    vec2 hl = glassLocal - vec2(-0.16, 0.18);
    col += exp(-dot(hl, hl) * 55.0) * 0.035;
    col = mix(col, vec3(luma(col)), 0.04);
  }
  col += (hash(gl_FragCoord.xy + fract(u_time) * 91.0) - 0.5) * 0.008;

  gl_FragColor = vec4(clamp(col, 0.0, 1.0), 1.0);
}
`;

  const NOISE_SIZE = 256;

  function hexToRgb(hex) {
    const h = String(hex).replace('#', '').trim();
    const full = h.length === 3 ? h.split('').map(c => c + c).join('') : h.padEnd(6, '0').slice(0, 6);
    const n = Number.parseInt(full, 16);
    if (Number.isNaN(n)) return [0.14, 0.47, 0.78];
    return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
  }

  function noiseTexels() {
    const size = NOISE_SIZE, data = new Uint8Array(size * size * 4);
    let seed = 0x23d;
    const rand = () => {
      seed = (seed + 0x6d2b79f5) | 0;
      let r = Math.imul(seed ^ (seed >>> 15), 1 | seed);
      r = (r + Math.imul(r ^ (r >>> 7), 61 | r)) ^ r;
      return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
    };
    for (let i = 0; i < size * size; i++) data[i * 4] = Math.floor(rand() * 256);
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
      const from = ((y - 239) & (size - 1)) * size + ((x - 37) & (size - 1));
      const i = (y * size + x) * 4;
      data[i + 1] = data[from * 4]; data[i + 2] = 0; data[i + 3] = 255;
    }
    return data;
  }

  function compile(gl, type, source) {
    const shader = gl.createShader(type);
    if (!shader) return null;
    gl.shaderSource(shader, source); gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) { gl.deleteShader(shader); return null; }
    return shader;
  }

  function createShaderSky(canvas, initial) {
    let options = Object.assign({speed: 0.1, coverage: 0.5, intensity: 0.9, amount: 0.5, scale: 0.4, variation: 0.7,
      interactive: false, lightning: false, glass: false, glassSize: 7, maxPixels: 720000, colors: LIGHT_COLORS}, initial || {});
    const mouse = {x: .5, y: .5}, targetMouse = {x: .5, y: .5};
    let reduce = false;

    const gl = canvas.getContext('webgl', {alpha: false, antialias: false, depth: false, stencil: false, powerPreference: 'high-performance'});
    if (!gl) return null;

    let vs = null, fs = null, program = null, buf = null, noiseTex = null, U = {};
    const initGl = () => {
      vs = compile(gl, gl.VERTEX_SHADER, VERT); fs = compile(gl, gl.FRAGMENT_SHADER, FRAG);
      if (!vs || !fs) { if (vs) gl.deleteShader(vs); if (fs) gl.deleteShader(fs); return false; }
      program = gl.createProgram();
      if (!program) return false;
      gl.attachShader(program, vs); gl.attachShader(program, fs); gl.linkProgram(program);
      if (!gl.getProgramParameter(program, gl.LINK_STATUS)) { gl.deleteProgram(program); gl.deleteShader(vs); gl.deleteShader(fs); return false; }
      gl.useProgram(program);
      buf = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, buf);
      gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]), gl.STATIC_DRAW);
      const loc = gl.getAttribLocation(program, 'a_position');
      gl.enableVertexAttribArray(loc); gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
      noiseTex = gl.createTexture(); gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, noiseTex);
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, NOISE_SIZE, NOISE_SIZE, 0, gl.RGBA, gl.UNSIGNED_BYTE, noiseTexels());
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.REPEAT);
      gl.uniform1i(gl.getUniformLocation(program, 'u_noise'), 0);
      ['u_resolution', 'u_time', 'u_speed', 'u_coverage', 'u_intensity', 'u_amount', 'u_scale', 'u_variation', 'u_glass', 'u_glassSize',
       'u_c1', 'u_c2', 'u_c3', 'u_c4', 'u_mouse', 'u_flash', 'u_flashPos'].forEach(n => { U[n] = gl.getUniformLocation(program, n); });
      return true;
    };
    if (!initGl()) return null;

    let raf = 0, destroyed = false, lost = false, onScreen = true, last = 0, clock = 0;
    const strike = {at: 0, next: 0, x: 0, y: 0};
    const pulse = s => (s < 0 ? 0 : Math.exp(-s * 14));
    const flashAt = now => {
      if (!options.lightning || reduce) { strike.next = 0; return 0; }
      if (strike.next === 0) strike.next = now + 1500 + Math.random() * 2500;
      if (now >= strike.next) { strike.at = now; strike.next = now + 4000 + Math.random() * 6000; strike.x = (Math.random() - .5) * .9; strike.y = .55 + Math.random() * .35; }
      if (strike.at === 0) return 0;
      const s = (now - strike.at) / 1000;
      return Math.min(1, pulse(s) + .65 * pulse(s - .16));
    };
    const shown = () => onScreen && document.visibilityState !== 'hidden';
    const moving = () => shown() && !reduce;
    function wake() { if (raf || destroyed || lost) return; last = performance.now(); raf = requestAnimationFrame(tick); }

    let pixelScale = 1;
    const resize = () => {
      const parent = canvas.parentElement;
      if (!parent) return false;
      const w = parent.clientWidth, h = parent.clientHeight;
      if (w <= 0 || h <= 0) return false;
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      pixelScale = Math.min(dpr, Math.sqrt(options.maxPixels / (w * h)));
      const width = Math.max(1, Math.floor(w * pixelScale)), height = Math.max(1, Math.floor(h * pixelScale));
      canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
      wake();
      if (width === canvas.width && height === canvas.height) return false;
      canvas.width = width; canvas.height = height;
      if (!lost) gl.viewport(0, 0, canvas.width, canvas.height);
      return true;
    };

    const mqReduce = matchMedia('(prefers-reduced-motion: reduce)');
    const onReduce = () => { reduce = mqReduce.matches; wake(); };
    mqReduce.addEventListener('change', onReduce);

    const onMove = e => {
      if (!options.interactive) return;
      const parent = canvas.parentElement; if (!parent) return;
      const rect = parent.getBoundingClientRect(); if (rect.width <= 0 || rect.height <= 0) return;
      targetMouse.x = (e.clientX - rect.left) / rect.width; targetMouse.y = 1 - (e.clientY - rect.top) / rect.height;
      if (shown()) wake();
    };
    addEventListener('pointermove', onMove, {passive: true});

    function tick(now) {
      raf = 0;
      if (destroyed || lost) return;
      const dt = Math.min(.25, Math.max(0, (now - last) / 1000)); last = now;
      const live = moving(); if (live) clock += dt;
      const src = options.colors || LIGHT_COLORS;
      const pal = [0, 1, 2, 3].map(i => hexToRgb(src[i % src.length] || LIGHT_COLORS[i]));
      mouse.x += (targetMouse.x - mouse.x) * .03; mouse.y += (targetMouse.y - mouse.y) * .03;
      const easing = options.interactive && Math.abs(targetMouse.x - mouse.x) + Math.abs(targetMouse.y - mouse.y) > .001;
      const clamp01 = v => Math.min(1, Math.max(0, v));
      gl.uniform2f(U.u_resolution, canvas.width, canvas.height);
      gl.uniform1f(U.u_time, clock);
      gl.uniform1f(U.u_speed, reduce ? 0 : options.speed);
      gl.uniform1f(U.u_coverage, clamp01(options.coverage)); gl.uniform1f(U.u_intensity, clamp01(options.intensity));
      gl.uniform1f(U.u_amount, clamp01(options.amount)); gl.uniform1f(U.u_scale, clamp01(options.scale));
      gl.uniform1f(U.u_variation, clamp01(options.variation));
      gl.uniform1f(U.u_glass, options.glass ? 1 : 0); gl.uniform1f(U.u_glassSize, Math.max(2, options.glassSize) * pixelScale);
      gl.uniform3f(U.u_c1, ...pal[0]); gl.uniform3f(U.u_c2, ...pal[1]); gl.uniform3f(U.u_c3, ...pal[2]); gl.uniform3f(U.u_c4, ...pal[3]);
      gl.uniform2f(U.u_mouse, options.interactive ? mouse.x : .5, options.interactive ? mouse.y : .5);
      gl.uniform1f(U.u_flash, flashAt(clock * 1000)); gl.uniform2f(U.u_flashPos, strike.x, strike.y);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      if (live || (easing && shown())) raf = requestAnimationFrame(tick);
    }
    const redraw = () => { if (destroyed || lost) return; cancelAnimationFrame(raf); raf = 0; last = performance.now(); tick(last); };

    resize();
    const ro = new ResizeObserver(() => { if (resize()) redraw(); });
    if (canvas.parentElement) ro.observe(canvas.parentElement);
    onReduce();
    const io = new IntersectionObserver(([entry]) => { onScreen = entry ? entry.isIntersecting : true; wake(); });
    io.observe(canvas);
    const onVisibility = () => wake();
    document.addEventListener('visibilitychange', onVisibility);
    const onLost = e => { e.preventDefault(); lost = true; cancelAnimationFrame(raf); raf = 0; };
    const onRestored = () => { if (destroyed || !initGl()) return; lost = false; gl.viewport(0, 0, canvas.width, canvas.height); wake(); };
    canvas.addEventListener('webglcontextlost', onLost);
    canvas.addEventListener('webglcontextrestored', onRestored);
    wake();

    return {
      setOptions(next) { options = Object.assign({}, options, next); resize(); wake(); },
      destroy() {
        destroyed = true; cancelAnimationFrame(raf); raf = 0;
        ro.disconnect(); io.disconnect();
        document.removeEventListener('visibilitychange', onVisibility);
        mqReduce.removeEventListener('change', onReduce);
        removeEventListener('pointermove', onMove);
        canvas.removeEventListener('webglcontextlost', onLost); canvas.removeEventListener('webglcontextrestored', onRestored);
        gl.deleteProgram(program); gl.deleteShader(vs); gl.deleteShader(fs); gl.deleteBuffer(buf); gl.deleteTexture(noiseTex);
      }
    };
  }
  window.createShaderSky = createShaderSky;
})();
