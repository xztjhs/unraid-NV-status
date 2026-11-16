/* nvidia-gpu-status :: 前端渲染
 * 双通道：
 *   - nchan 推送 /sub/nvgpu（默认，<1s 延迟，无轮询开销）
 *   - HTTP 轮询 api.php（推送不可用时自动降级；也是历史曲线来源）
 * 刷新间隔 0.5–10s、显示时长 1–60min，改动存 localStorage（每浏览器独立）。
 */
(function () {
  'use strict';

  var state = {
    boot: null,
    L: {},
    refreshMs: 2000,
    windowMin: 5,
    paused: false,
    sub: null,
    pollTimer: null,
    last: null,
    hist: { gpu: [], mem: [], temp: [] },
    mode: 'init',
    lastFetch: 0
  };

  function t(k, dflt) {
    var v = state.L && state.L[k];
    return (v === undefined || v === null || v === '') ? (dflt === undefined ? k : dflt) : v;
  }

  function q(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(v, digits) {
    if (v === null || v === undefined || v === '' || isNaN(v)) return '–';
    return Number(v).toFixed(digits === undefined ? 0 : digits);
  }
  function pct(v) { return (v === null || v === undefined) ? '–' : num(v) + '%'; }

  /* ---------------------------------------------------------- 折线图 */

  function drawSpark(canvas, series, opts) {
    if (!canvas || !canvas.getContext) return;
    var dpr = window.devicePixelRatio || 1;
    var w = canvas.clientWidth || 220;
    var h = canvas.clientHeight || 34;
    if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) {
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
    }
    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    var pts = series.filter(function (v) { return v !== null && v !== undefined && !isNaN(v); });
    if (pts.length < 2) return;

    var min = opts && opts.min !== undefined ? opts.min : Math.min.apply(null, pts);
    var max = opts && opts.max !== undefined ? opts.max : Math.max.apply(null, pts);
    if (max === min) { max = min + 1; }
    var pad = 2;
    var n = pts.length;
    var xs = function (i) { return (i / (n - 1)) * (w - 2) + 1; };
    var ys = function (v) { return h - pad - ((v - min) / (max - min)) * (h - pad * 2); };

    var color = (opts && opts.color) || '#5aa9e6';
    var grad = ctx.createLinearGradient(0, 0, 0, h);
    grad.addColorStop(0, color + '66');
    grad.addColorStop(1, color + '05');

    ctx.beginPath();
    ctx.moveTo(xs(0), ys(pts[0]));
    for (var i = 1; i < n; i++) ctx.lineTo(xs(i), ys(pts[i]));
    ctx.lineTo(xs(n - 1), h);
    ctx.lineTo(xs(0), h);
    ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(xs(0), ys(pts[0]));
    for (var j = 1; j < n; j++) ctx.lineTo(xs(j), ys(pts[j]));
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.4;
    ctx.lineJoin = 'round';
    ctx.stroke();
  }

  /* ---------------------------------------------------------- 渲染 */

  function render(snap) {
    if (!snap || !snap.gpus || !snap.gpus.length) {
      var r0 = q('nvgpu-health');
      if (r0) { r0.textContent = 'no data'; r0.className = 'nvgpu-badge nvgpu-bad'; }
      return;
    }
    state.last = snap;
    var g = snap.gpus[0];

    var nameEl = q('nvgpu-name');
    if (nameEl) nameEl.textContent = g.name || 'GPU';

    var pst = q('nvgpu-pstate');
    if (pst) pst.textContent = g.pstate || '–';

    /* 健康状态 */
    var flags = g.flags || [];
    var bad = flags.filter(function (f) { return f[0] === 'error'; });
    var warn = flags.filter(function (f) { return f[0] === 'warn'; });
    var fmtFlag = function (f) {
      var key = 'flag_' + String(f[1] || '').replace(/^throttle_/, 'thr_');
      var tpl = t(key, '');
      if (!tpl) return String(f[1] || '');
      var args = f[2] || [];
      var i = 0;
      return tpl.replace(/%s/g, function () { return args[i++]; });
    };
    var h = q('nvgpu-health');
    if (h) {
      if (bad.length) { h.textContent = t('error'); h.className = 'nvgpu-badge nvgpu-bad'; }
      else if (warn.length) { h.textContent = t('warn'); h.className = 'nvgpu-badge nvgpu-warn'; }
      else { h.textContent = t('ok'); h.className = 'nvgpu-badge nvgpu-ok'; }
      h.title = flags.length ? flags.map(fmtFlag).join('\n') : t('ok');
    }

    /* 大指标 */
    var gu = q('nvgpu-gpu');
    if (gu) gu.textContent = pct(g.gpu_pct);

    var tp = q('nvgpu-temp');
    if (tp) {
      tp.textContent = (g.temp === null || g.temp === undefined) ? '–' : num(g.temp) + '°C';
      var tw = (snap.cfg && snap.cfg.temp_warn) || 85;
      tp.className = 'nvgpu-v' + (g.temp >= tw ? ' nvgpu-bad-t' : (g.temp >= tw - 10 ? ' nvgpu-warn-t' : ''));
    }

    var mem = q('nvgpu-mem');
    var memPct = (g.mem_total && g.mem_used !== null) ? (g.mem_used / g.mem_total * 100) : null;
    if (mem) {
      mem.textContent = (memPct === null) ? '–'
        : (g.mem_used >= 1024 ? num(g.mem_used / 1024, 1) + ' / ' + num(g.mem_total / 1024, 0) + ' GB' : num(g.mem_used) + ' / ' + num(g.mem_total) + ' MB');
    }
    var bar = q('nvgpu-membar');
    if (bar) {
      bar.style.width = (memPct === null ? 0 : Math.max(0, Math.min(100, memPct))) + '%';
      bar.className = memPct >= 97 ? 'nvgpu-fill-bad' : (memPct >= 85 ? 'nvgpu-fill-warn' : '');
    }

    /* 明细行 */
    var set = function (id, txt) { var e = q(id); if (e) e.textContent = txt; };

    set('nvgpu-fan', (g.fan === null || g.fan === undefined) ? '– (' + t('nodev') + ')' : num(g.fan) + ' %');
    set('nvgpu-power',
      (g.power === null || g.power === undefined)
        ? '–'
        : num(g.power) + ' W / ' + (g.power_limit ? num(g.power_limit) + ' W' : '–')
          + (g.power_instant !== null && g.power_instant !== undefined
              ? '（' + t('instant') + ' ' + num(g.power_instant) + ' W）' : ''));
    set('nvgpu-clocks',
      'SM ' + (g.clk_sm === null ? '–' : num(g.clk_sm)) + ' MHz · MEM ' + (g.clk_mem === null ? '–' : num(g.clk_mem))
      + ' MHz' + (g.clk_sm_max ? '（' + t('limit') + ' ' + num(g.clk_sm_max) + '）' : ''));
    set('nvgpu-pcie',
      'Gen' + (g.pcie_gen === null ? '–' : num(g.pcie_gen))
      + ' x' + (g.pcie_width === null ? '–' : num(g.pcie_width))
      + '（' + t('limit') + ' Gen' + (g.pcie_gen_max === null ? '–' : num(g.pcie_gen_max))
      + ' x' + (g.pcie_width_max === null ? '–' : num(g.pcie_width_max)) + '）'
      + (g.replay !== null && g.replay !== undefined ? ' · replay ' + num(g.replay) : ''));
    set('nvgpu-codec',
      'ENC ' + pct(g.enc_pct) + ' / DEC ' + pct(g.dec_pct)
      + (g.enc_sessions !== null && g.enc_sessions !== undefined ? ' · ' + t('sessions') + ' ' + num(g.enc_sessions) : ''));

    /* ECC */
    var eccEl = q('nvgpu-ecc');
    if (eccEl) {
      if (!g.ecc_enabled) {
        eccEl.innerHTML = '<span class="nvgpu-dim">' + esc(t('ecc_off')) + '</span>'
          + ' · ' + esc(t('corrected')) + ' <span class="nvgpu-dim">–</span>'
          + ' · ' + esc(t('uncorrected')) + ' <span class="nvgpu-dim">–</span>';
      } else {
        var cu = g.ecc_uncorr_vol, cc = g.ecc_corr_vol;
        eccEl.innerHTML = esc(g.ecc_mode || 'On')
          + ' · ' + esc(t('corrected')) + ' ' + (cc === null || cc === undefined ? '–' : '<b class="' + (cc > 1000 ? 'nvgpu-warn-t' : '') + '">' + num(cc) + '</b>')
          + ' · ' + esc(t('uncorrected')) + ' ' + (cu === null || cu === undefined ? '–' : '<b class="' + (cu > 0 ? 'nvgpu-bad-t' : '') + '">' + num(cu) + '</b>')
          + (g.remap_uncorrectable ? ' · ' + esc(t('remapped')) + ' ' + num(g.remap_correctable) + '/' + num(g.remap_uncorrectable) : '');
      }
    }

    /* 进程 */
    var procsEl = q('nvgpu-procs');
    var showProc = !snap.cfg || snap.cfg.show_proc !== false;
    if (procsEl) {
      if (!showProc) { procsEl.textContent = '（' + t('proc_off') + '）'; }
      else if (!g.processes || !g.processes.length) { procsEl.innerHTML = '<span class="nvgpu-dim">' + esc(t('no_proc')) + '</span>'; }
      else {
        procsEl.innerHTML = g.processes.slice(0, 4).map(function (p) {
          var m = (p.mem === null || p.mem === undefined) ? '' : ' <span class="nvgpu-dim">' + num(p.mem) + 'M</span>';
          return '<span class="nvgpu-chip" title="PID ' + esc(p.pid) + ' · ' + esc(p.path || '') + '">'
            + esc(p.name || ('pid ' + p.pid)) + m + '</span>';
        }).join('') + (g.processes.length > 4 ? '<span class="nvgpu-dim"> +' + (g.processes.length - 4) + '</span>' : '');
      }
    }

    /* 告警条 */
    var fl = q('nvgpu-flags');
    if (fl) {
      if (!flags.length) { fl.innerHTML = ''; fl.style.display = 'none'; }
      else {
        fl.style.display = '';
        fl.innerHTML = flags.map(function (f) {
          return '<div class="nvgpu-flag nvgpu-flag-' + esc(f[0]) + '">' + esc(fmtFlag(f)) + '</div>';
        }).join('');
      }
    }

    /* 页脚 */
    var age = q('nvgpu-age');
    if (age) age.textContent = t('updated') + ' ' + new Date().toLocaleTimeString();
    var modeEl = q('nvgpu-mode');
    if (modeEl) {
      modeEl.textContent = state.mode === 'push' ? '● ' + t('mode_push') : '○ ' + t('mode_poll');
      modeEl.className = 'nvgpu-mode ' + (state.mode === 'push' ? 'nvgpu-push-on' : 'nvgpu-push-off');
    }

    /* 折线用最新窗口数据 */
    drawSeries();
  }

  function drawSeries() {
    var hg = state.hist.gpu, hm = state.hist.mem, ht = state.hist.temp;
    drawSpark(q('nvgpu-spark-gpu'), hg, { min: 0, max: 100, color: '#5aa9e6' });
    drawSpark(q('nvgpu-spark-mem'), hm, { min: 0, max: 100, color: '#8f7ee6' });
    var tw = (state.last && state.last.cfg && state.last.cfg.temp_warn) || 85;
    drawSpark(q('nvgpu-spark-temp'), ht, { min: Math.max(20, (tw - 50)), max: tw + 5, color: '#e6805a' });
  }

  /* ---------------------------------------------------------- 数据获取 */

  function applyHist(hist) {
    state.hist = { gpu: [], mem: [], temp: [] };
    if (!hist || !hist.length) return;
    hist.forEach(function (r) {
      state.hist.gpu.push(r.g);
      state.hist.mem.push(r.m);
      state.hist.temp.push(r.T);
    });
  }

  function fetchOnce(withHist) {
    if (state.paused) return;
    var url = state.boot.api + (withHist ? '?window=' + (state.windowMin * 60) : '?window=0');
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (d) {
        state.lastFetch = Date.now();
        if (d && d.hist) applyHist(d.hist);
        render(d);
      })
      .catch(function () {
        var m = q('nvgpu-mode');
        if (m) { m.textContent = '○ ' + t('mode_dead'); m.className = 'nvgpu-mode nvgpu-push-off'; }
      });
  }

  function schedulePoll() {
    clearTimeout(state.pollTimer);
    state.pollTimer = setTimeout(function () {
      fetchOnce(true);
      schedulePoll();
    }, state.refreshMs);
  }

  function startPush() {
    if (typeof NchanSubscriber === 'undefined') return false;
    try {
      state.sub = new NchanSubscriber('/sub/' + state.boot.channel, {
        subscriber: 'websocket', reconnectTimeout: 3000
      });
      state.sub.on('message', function (msg) {
        if (state.paused) return;
        try {
          var d = JSON.parse(msg);
          if (d && d.type === 'sample') {
            state.mode = 'push';
            // 推送帧不含慢层/历史，把它并入上一帧保留的慢层数据
            var merged = { ts: d.ts, gpus: d.gpus, cfg: (state.last && state.last.cfg) || state.boot.cfg, ok: true };
            if (state.last && state.last.gpus && state.last.gpus[0]) {
              var prev = state.last.gpus[0], cur = merged.gpus[0];
              ['processes', 'ecc_enabled', 'ecc_mode', 'ecc_corr_vol', 'ecc_uncorr_vol',
               'ecc_corr_agg', 'ecc_uncorr_agg', 'remap_correctable', 'remap_uncorrectable',
               'remap_pending', 'remap_failure', 'replay', 'enc_sessions', 'throttle'].forEach(function (k) {
                if (cur[k] === undefined && prev[k] !== undefined) cur[k] = prev[k];
              });
              if (!cur.flags) cur.flags = prev.flags;
            }
            render(merged);
            // 每 5 秒补一次历史刷新（推送不含历史）
            if (Date.now() - state.lastFetch > 5000) fetchOnce(true);
          }
        } catch (e) { /* 非法帧忽略 */ }
      });
      state.sub.on('connect', function () { state.mode = 'push'; });
      state.sub.on('error', function () { state.mode = 'poll'; });
      state.sub.on('disconnect', function () { state.mode = 'poll'; });
      state.sub.start();
      return true;
    } catch (e) {
      return false;
    }
  }

  function stopPush() {
    if (state.sub) { try { state.sub.stop(); } catch (e) {} state.sub = null; }
  }

  /* ---------------------------------------------------------- 控件 */

  function bindControls(root) {
    var selR = q('nvgpu-refresh');
    var selW = q('nvgpu-window');
    var pause = q('nvgpu-pause');

    var savedR = parseInt(localStorage.getItem('nvgpu.refreshMs') || '0', 10);
    var savedW = parseInt(localStorage.getItem('nvgpu.windowMin') || '0', 10);
    if (savedR >= 500 && savedR <= 10000) state.refreshMs = savedR;
    if (savedW >= 1 && savedW <= 60) state.windowMin = savedW;

    if (selR) {
      selR.value = String(state.refreshMs);
      selR.addEventListener('change', function () {
        state.refreshMs = parseInt(selR.value, 10) || 2000;
        localStorage.setItem('nvgpu.refreshMs', String(state.refreshMs));
        schedulePoll();
      });
    }
    if (selW) {
      selW.value = String(state.windowMin);
      selW.addEventListener('change', function () {
        state.windowMin = parseInt(selW.value, 10) || 5;
        localStorage.setItem('nvgpu.windowMin', String(state.windowMin));
        fetchOnce(true);
      });
    }
    if (pause) {
      pause.addEventListener('click', function () {
        state.paused = !state.paused;
        pause.className = 'fa hand ' + (state.paused ? 'fa-play' : 'fa-pause');
        pause.title = state.paused ? t('resume') : t('pause');
        if (!state.paused) fetchOnce(true);
      });
    }

    /* 标签页隐藏时停轮询，回来立即补 */
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        clearTimeout(state.pollTimer);
      } else {
        fetchOnce(true);
        schedulePoll();
      }
    });

    /* 窗口尺寸变化重画折线 */
    var rt = null;
    window.addEventListener('resize', function () {
      clearTimeout(rt);
      rt = setTimeout(drawSeries, 150);
    });
  }

  function boot(root) {
    if (!root) root = q('nvgpu-root');
    if (!root || root.dataset.nvgpuReady === '1') return;
    root.dataset.nvgpuReady = '1';

    var conf = {};
    try { conf = JSON.parse(root.getAttribute('data-boot') || '{}'); } catch (e) {}
    state.L = conf.lang || {};
    state.boot = {
      api: conf.api || '/plugins/nvidia-gpu-status/api.php',
      channel: conf.channel || 'nvgpu',
      cfg: { temp_warn: 85, show_proc: true }
    };
    if (conf.refreshMs) state.refreshMs = conf.refreshMs;
    if (conf.windowMin) state.windowMin = conf.windowMin;

    bindControls(root);
    fetchOnce(true);          // 首帧立刻出数（不等推送）

    if (conf.push !== false) {
      var ok = startPush();
      if (!ok) state.mode = 'poll';
    } else {
      state.mode = 'poll';
    }
    schedulePoll();           // 轮询始终在跑：兜底 + 拉历史
  }

  window.NVGPU = { boot: boot, state: state };
})();
