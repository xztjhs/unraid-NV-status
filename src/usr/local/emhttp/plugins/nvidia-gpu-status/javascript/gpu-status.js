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
    lastFetch: 0,
    root: null
  };

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
  function t(k, dflt) {
    var v = state.L && state.L[k];
    return (v === undefined || v === null || v === '') ? (dflt === undefined ? k : dflt) : v;
  }

  /* ---------------------------------------------------------- 条形指标 */

  function setBar(idPct, idFill, text, pct, warn, bad) {
    var pe = q(idPct), fe = q(idFill);
    if (pe) {
      pe.textContent = text;
      pe.className = 'nvgpu-pct' + (bad ? ' is-bad' : (warn ? ' is-warn' : ''));
    }
    if (fe) {
      fe.style.width = (pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, pct))) + '%';
      fe.className = 'nvgpu-fill' + (bad ? ' is-bad' : (warn ? ' is-warn' : ''));
    }
  }

  /* ---------------------------------------------------------- 折线图 */

  function drawChart() {
    var c = q('nvgpu-chart');
    if (!c || !c.getContext) return;
    var dpr = window.devicePixelRatio || 1;
    var w = c.clientWidth || 360;
    var h = 96;
    if (c.width !== Math.round(w * dpr) || c.height !== Math.round(h * dpr)) {
      c.width = Math.round(w * dpr);
      c.height = Math.round(h * dpr);
    }
    var ctx = c.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    // 绘制顺序：温度 → 显存 → GPU（GPU 最后画，压在底层之上）
    var series = [
      { data: state.hist.temp, color: '#e6805a', scale: 'temp' },
      { data: state.hist.mem,  color: '#8f7ee6' },
      { data: state.hist.gpu,  color: '#5aa9e6' }
    ];

    var pad = 3;
    var tw = (state.last && state.last.cfg && state.last.cfg.temp_warn) || 85;

    // 温度标尺动态跟随实际区间：空闲时温度远低于告警线，固定 [tw-50, tw+5] 会把线压到底部看不出变化
    var temps = (state.hist.temp || []).filter(function (v) { return v !== null && v !== undefined && !isNaN(v); });
    var tempMin = 20, tempMax = tw + 5;
    if (temps.length) {
      var tlo = Math.min.apply(null, temps), thi = Math.max.apply(null, temps);
      tempMin = Math.max(0, Math.min(tlo - 5, tw - 30));
      tempMax = Math.max(tw + 5, thi + 5);
      if (tempMax - tempMin < 20) tempMax = tempMin + 20;   // 避免量程过窄导致噪声放大
    }

    // 网格线（0/50/100%）
    ctx.strokeStyle = 'rgba(128,128,128,.14)';
    ctx.lineWidth = 1;
    [0, 0.5, 1].forEach(function (f) {
      var y = Math.round(h - pad - f * (h - pad * 2)) + 0.5;
      ctx.beginPath();
      ctx.moveTo(0, y);
      ctx.lineTo(w, y);
      ctx.stroke();
    });

    series.forEach(function (s) {
      var pts = (s.data || []).filter(function (v) { return v !== null && v !== undefined && !isNaN(v); });
      if (pts.length < 2) return;

      var lo, hi;
      if (s.scale === 'temp') { lo = tempMin; hi = tempMax; }
      else { lo = 0; hi = 100; }

      var n = pts.length;
      var xs = function (i) { return (i / (n - 1)) * (w - 2) + 1; };
      var ys = function (v) {
        var r = (v - lo) / (hi - lo);
        r = Math.max(0, Math.min(1, r));
        return h - pad - r * (h - pad * 2);
      };

      ctx.beginPath();
      ctx.moveTo(xs(0), ys(pts[0]));
      for (var i = 1; i < n; i++) ctx.lineTo(xs(i), ys(pts[i]));
      ctx.strokeStyle = s.color;
      ctx.lineWidth = 1.5;
      ctx.lineJoin = 'round';
      ctx.stroke();

      // 末端点
      ctx.beginPath();
      ctx.arc(xs(n - 1), ys(pts[n - 1]), 2.2, 0, Math.PI * 2);
      ctx.fillStyle = s.color;
      ctx.fill();
    });
  }

  /* ---------------------------------------------------------- 进程表 */

  function renderProcesses(g) {
    var box = q('nvgpu-procs');
    if (!box) return;
    var show = !state.last || !state.last.cfg || state.last.cfg.show_proc !== false;
    if (!show) {
      box.innerHTML = '<span class="nvgpu-dim">' + esc(t('proc_off')) + '</span>';
      return;
    }
    var procs = (g.processes || []).slice();
    if (!procs.length) {
      box.innerHTML = '<span class="nvgpu-dim">' + esc(t('no_proc')) + '</span>';
      return;
    }
    // 按显存降序，序号即排序位次
    procs.sort(function (a, b) { return (b.mem || 0) - (a.mem || 0); });

    box.innerHTML = procs.map(function (p, i) {
      var kind = p.ownerKind || 'host';
      var ownerCls = 'nvgpu-own-tag nvgpu-own-' + (kind === 'container' ? 'container' : kind === 'vm' ? 'vm' : 'host');
      var ownerTxt = p.owner ? p.owner : t('host');
      var mem = (p.mem === null || p.mem === undefined)
        ? '–'
        : (p.mem >= 1024 ? num(p.mem / 1024, 1) + ' GB' : num(p.mem) + ' MB');
      var cmd = p.path || p.name || ('pid ' + p.pid);
      return '<div class="nvgpu-proc-row" title="PID ' + esc(p.pid) + (p.type ? ' · ' + esc(p.type) : '') + '">'
        + '<span class="nvgpu-pc-idx">' + (i + 1) + '</span>'
        + '<span class="nvgpu-pc-owner"><span class="' + ownerCls + '">' + esc(kind === 'container' ? 'CT' : kind === 'vm' ? 'VM' : '') + '</span>' + esc(ownerTxt) + '</span>'
        + '<span class="nvgpu-pc-mem">' + esc(mem) + '</span>'
        + '<span class="nvgpu-pc-cmd">' + esc(cmd) + '</span>'
        + '</div>';
    }).join('');
  }

  /* ---------------------------------------------------------- 渲染 */

  function render(snap) {
    if (!snap || !snap.gpus || !snap.gpus.length) {
      var r0 = q('nvgpu-mode');
      if (r0) { r0.textContent = t('mode_dead'); r0.className = 'nvgpu-mode nvgpu-push-off'; }
      return;
    }
    state.last = snap;
    var g = snap.gpus[0];

    var nameEl = q('nvgpu-name');
    if (nameEl) nameEl.textContent = g.name || 'GPU';

    var sub = q('nvgpu-sub');
    if (sub) {
      var parts = [];
      if (g.driver) parts.push(g.driver);
      if (g.pstate) parts.push(g.pstate);
      if (g.vbios) parts.push('VBIOS ' + g.vbios);
      sub.textContent = parts.join(' · ');
    }

    /* GPU 负载 */
    var gu = (g.gpu_pct === null || g.gpu_pct === undefined) ? null : g.gpu_pct;
    setBar('nvgpu-gpu', 'nvgpu-fill-gpu',
      gu === null ? '–' : num(gu) + '%',
      gu, gu !== null && gu >= 90, false);

    /* 温度 */
    var tw = (snap.cfg && snap.cfg.temp_warn) || 85;
    var tp = g.temp;
    var tpct = tp === null || tp === undefined ? null : Math.max(0, Math.min(100, (tp / (tw + 15)) * 100));
    setBar('nvgpu-temp', 'nvgpu-fill-temp',
      tp === null || tp === undefined ? '–' : num(tp) + '°C',
      tpct, tp !== null && tp >= tw - 10, tp !== null && tp >= tw);

    /* 显存 */
    var memPct = (g.mem_total && g.mem_used !== null) ? (g.mem_used / g.mem_total * 100) : null;
    var memText = memPct === null ? '–'
      : (g.mem_used >= 1024
          ? num(g.mem_used / 1024, 1) + '/' + num(g.mem_total / 1024, 0) + 'G'
          : num(g.mem_used) + '/' + num(g.mem_total) + 'M');
    setBar('nvgpu-mem', 'nvgpu-fill-mem', memText, memPct, memPct !== null && memPct >= 85, memPct !== null && memPct >= 97);

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
      'ENC ' + (g.enc_pct === null ? '–' : num(g.enc_pct) + '%')
      + ' / DEC ' + (g.dec_pct === null ? '–' : num(g.dec_pct) + '%')
      + (g.enc_sessions !== null && g.enc_sessions !== undefined ? ' · ' + t('sessions') + ' ' + num(g.enc_sessions) : ''));

    /* ECC */
    var eccEl = q('nvgpu-ecc');
    if (eccEl) {
      if (!g.ecc_enabled) {
        eccEl.innerHTML = '<span class="nvgpu-dim">' + esc(t('ecc_off')) + '</span>'
          + ' · ' + esc(t('remapped')) + ' '
          + (g.remap_uncorrectable === null || g.remap_uncorrectable === undefined
              ? '–'
              : num(g.remap_correctable) + '/' + num(g.remap_uncorrectable));
      } else {
        var cu = g.ecc_uncorr_vol, cc = g.ecc_corr_vol;
        eccEl.innerHTML = esc(g.ecc_mode || 'On')
          + ' · ' + esc(t('corrected')) + ' ' + (cc === null || cc === undefined ? '–' : '<b class="' + (cc > 1000 ? 'nvgpu-warn-t' : '') + '">' + num(cc) + '</b>')
          + ' · ' + esc(t('uncorrected')) + ' ' + (cu === null || cu === undefined ? '–' : '<b class="' + (cu > 0 ? 'nvgpu-bad-t' : '') + '">' + num(cu) + '</b>')
          + (g.remap_uncorrectable ? ' · ' + esc(t('remapped')) + ' ' + num(g.remap_correctable) + '/' + num(g.remap_uncorrectable) : '');
      }
    }

    renderProcesses(g);

    /* 告警 */
    var flags = g.flags || [];
    var fmtFlag = function (f) {
      var key = 'flag_' + String(f[1] || '').replace(/^throttle_/, 'thr_');
      var tpl = t(key, '');
      if (!tpl) return String(f[1] || '');
      var args = f[2] || [], i = 0;
      return tpl.replace(/%s/g, function () { return args[i++]; });
    };
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

    drawChart();
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
      if (!document.hidden) fetchOnce(true);
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
            var merged = { ts: d.ts, gpus: d.gpus, cfg: (state.last && state.last.cfg) || state.boot.cfg, ok: true };
            if (state.last && state.last.gpus && state.last.gpus[0]) {
              var prev = state.last.gpus[0], cur = merged.gpus[0];
              ['processes', 'ecc_enabled', 'ecc_mode', 'ecc_corr_vol', 'ecc_uncorr_vol',
               'ecc_corr_agg', 'ecc_uncorr_agg', 'remap_correctable', 'remap_uncorrectable',
               'remap_pending', 'remap_failure', 'replay', 'enc_sessions', 'throttle',
               'driver', 'vbios'].forEach(function (k) {
                if (cur[k] === undefined && prev[k] !== undefined) cur[k] = prev[k];
              });
              if (!cur.flags) cur.flags = prev.flags;
            }
            render(merged);
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

  function bindControls() {
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
        pause.className = 'fa fa-fw control hand ' + (state.paused ? 'fa-play' : 'fa-pause');
        pause.title = state.paused ? t('resume') : t('pause');
        if (!state.paused) fetchOnce(true);
      });
    }

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        clearTimeout(state.pollTimer);
      } else {
        fetchOnce(true);
        schedulePoll();
      }
    });

    var rt = null;
    window.addEventListener('resize', function () {
      clearTimeout(rt);
      rt = setTimeout(drawChart, 150);
    });
  }

  function boot(anchor) {
    var root = state.root || q('nvgpu-name') || anchor;
    if (!root || state.boot) return;
    var tile = root.closest ? root.closest('tbody') : null;
    if (!tile) return;
    var bootEl = tile.querySelector('[data-boot]');
    // data-boot 挂在 tbody 上（模板里放在 body 上更稳），这里兼容两种
    var conf = {};
    try {
      conf = JSON.parse((bootEl && bootEl.getAttribute('data-boot')) || tile.getAttribute('data-boot') || '{}');
    } catch (e) {}

    state.root = tile;
    state.L = conf.lang || {};
    state.boot = {
      api: conf.api || '/plugins/nvidia-gpu-status/api.php',
      channel: conf.channel || 'nvgpu',
      cfg: { temp_warn: 85, show_proc: true }
    };
    if (conf.refreshMs) state.refreshMs = conf.refreshMs;
    if (conf.windowMin) state.windowMin = conf.windowMin;

    bindControls();
    fetchOnce(true);

    if (conf.push !== false) {
      if (!startPush()) state.mode = 'poll';
    } else {
      state.mode = 'poll';
    }
    schedulePoll();
  }

  window.NVGPU = { boot: boot, state: state };
})();
