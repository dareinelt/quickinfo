/**
 * quickinfo – Frontend-Logik (Vanilla JS, ES2020+).
 */
(function () {
  'use strict';

  const REFRESH_MS = 30000;
  const RANGES = ['1h', '3h', '24h', '3d', '14d'];
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const state = {
    csrf: null,
    user: null,
    range: localStorage.getItem('qi.range') || '3h',
    showCores: localStorage.getItem('qi.cores') !== '0',
    overview: null,
    history: null,
    timer: null,
    charts: {},
    availableUnits: null,
    apiKey: null,
    system: null,
    docker: {
      config: null,
      containers: [],
      folders: [],
      selected: null,
      detail: null,
      stats: null,
      dragging: null,
      draggingFolder: null,
    },
  };

  // ---------------------------------------------------------------------
  // API
  // ---------------------------------------------------------------------
  class ApiError extends Error {
    constructor(status, payload) {
      super((payload && payload.error) || ('HTTP ' + status));
      this.status = status;
      this.payload = payload || {};
    }
  }

  async function api(path, { method = 'GET', body } = {}) {
    const headers = { 'Accept': 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET' && state.csrf) headers['X-CSRF-Token'] = state.csrf;
    const res = await fetch('/api/' + path, {
      method, headers, credentials: 'same-origin', cache: 'no-store',
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    let data = null;
    try { data = await res.json(); } catch (_) { /* leer */ }
    if (res.status === 401 && path !== 'login' && path !== 'session') {
      showLogin();
      throw new ApiError(401, data);
    }
    if (!res.ok) throw new ApiError(res.status, data);
    return data;
  }

  // ---------------------------------------------------------------------
  // Formatierung
  // ---------------------------------------------------------------------
  const nf1 = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1, minimumFractionDigits: 0 });
  const nf0 = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 0 });

  function fmtBytes(b) {
    if (b == null) return '–';
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let i = 0, v = b;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return nf1.format(v) + ' ' + units[i];
  }

  function fmtDuration(sec) {
    if (sec == null) return '–';
    const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
    if (d > 0) return `${d} T ${h} h`;
    if (h > 0) return `${h} h ${m} min`;
    return `${m} min`;
  }

  function fmtAgo(ts) {
    if (!ts) return '–';
    const diff = Math.max(0, Math.round(Date.now() / 1000 - ts));
    if (diff < 60) return `vor ${diff} s`;
    if (diff < 3600) return `vor ${Math.floor(diff / 60)} min`;
    return `vor ${Math.floor(diff / 3600)} h`;
  }

  function pctClass(p, warn = 75, danger = 90) {
    if (p == null) return '';
    return p >= danger ? 'danger' : p >= warn ? 'warn' : '';
  }

  function setStat(key, value, pct, meta, thresholds) {
    const card = $(`#stat-${key}`) || document;
    const v = $(`[data-v="${key}"]`, card);
    if (v) v.textContent = value == null ? '–' : nf1.format(value);
    const bar = $(`[data-bar="${key}"]`, card);
    if (bar) {
      bar.style.width = (pct == null ? 0 : Math.min(100, Math.max(0, pct))) + '%';
      bar.className = thresholds ? pctClass(pct, thresholds[0], thresholds[1]) : pctClass(pct);
    }
    const m = $(`[data-v="${key}-meta"]`, card);
    if (m && meta !== undefined) m.textContent = meta;
  }

  function toast(msg, kind = '') {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast ' + kind;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.add('hidden'), 3200);
  }

  // ---------------------------------------------------------------------
  // Views
  // ---------------------------------------------------------------------
  function renderLoginSystem(system) {
    const host = $('#login-hostname');
    const desc = $('#login-desc');
    const inv = $('#login-inventory');
    if (!system) { host.textContent = '–'; desc.hidden = true; inv.hidden = true; return; }
    host.textContent = system.hostname || '–';
    const d = (system.description || '').trim();
    desc.textContent = d; desc.hidden = !d;
    const i = (system.inventory || '').trim();
    inv.textContent = 'Inventarnummer: ' + i;
    inv.hidden = !!system.is_vm || !i;
  }

  function showLogin() {
    stopAutoRefresh();
    $('#view-app').classList.add('hidden');
    $('#view-login').classList.remove('hidden');
    renderLoginSystem(state.system);
    $('#login-pass').value = '';
    setTimeout(() => $('#login-user').focus(), 50);
  }

  function showApp() {
    $('#view-login').classList.add('hidden');
    $('#view-app').classList.remove('hidden');
    initCharts();
    setRange(state.range, false);
    refreshAll();
    loadDockerConfig();
    startAutoRefresh();
  }

  function startAutoRefresh() {
    stopAutoRefresh();
    state.timer = setInterval(() => { if (!document.hidden) refreshAll(); }, REFRESH_MS);
    document.addEventListener('visibilitychange', onVisibility);
  }

  function stopAutoRefresh() {
    if (state.timer) clearInterval(state.timer);
    state.timer = null;
    document.removeEventListener('visibilitychange', onVisibility);
  }

  function onVisibility() {
    if (!document.hidden) refreshAll();
  }

  async function refreshAll() {
    const dot = $('#update-dot');
    dot.classList.add('pulse');
    try {
      const [overview, history] = await Promise.all([api('overview'), api('history?range=' + state.range)]);
      state.overview = overview;
      state.history = history;
      renderOverview();
      renderCharts();
      if (dockerEnabled() && !$('#panel-docker').classList.contains('hidden')) refreshDocker();
    } catch (e) {
      if (e.status !== 401) toast('Aktualisierung fehlgeschlagen: ' + e.message, 'danger');
    } finally {
      dot.classList.remove('pulse');
    }
  }

  // ---------------------------------------------------------------------
  // Übersicht / Kennzahlen
  // ---------------------------------------------------------------------
  function renderOverview() {
    const o = state.overview;
    if (!o) return;
    const s = o.snapshot || {};
    const now = o.now || Math.floor(Date.now() / 1000);

    $('#hostname').textContent = s.hostname || 'Server';
    const metaParts = [];
    if (s.cpu && s.cpu.model) metaParts.push(s.cpu.model);
    if (s.uptime != null) metaParts.push('Uptime ' + fmtDuration(s.uptime));
    if (s.load) metaParts.push('Load ' + s.load.map((l) => nf1.format(l)).join(' / '));
    $('#host-meta').textContent = metaParts.join(' · ') || '–';

    const age = o.snapshot_ts ? now - o.snapshot_ts : null;
    const dot = $('#update-dot');
    dot.className = 'dot ' + (age == null ? '' : age > 300 ? 'danger' : age > 120 ? 'warn' : 'ok');
    $('#update-text').textContent = o.snapshot_ts ? 'Messung ' + fmtAgo(o.snapshot_ts) : 'Keine Messung';
    $('#update-pill').title = o.snapshot_ts ? new Date(o.snapshot_ts * 1000).toLocaleString('de-DE') : '';

    // CPU
    const cpu = s.cpu || {};
    setStat('cpu', cpu.total, cpu.total, cpu.count ? `${cpu.count} Kerne · max. Kern ${nf1.format(Math.max(...(cpu.cores || [0])))} %` : '–');

    // Temperatur
    const temps = s.temps || [];
    const tCard = $('#stat-temp');
    if (s.is_vm) {
      tCard.classList.add('hidden');
    } else {
      tCard.classList.remove('hidden');
      if (temps.length) {
        tCard.classList.remove('unavailable');
        const hottest = temps.reduce((a, b) => (b.value > a.value ? b : a));
        setStat('temp', s.temp_max, s.temp_max, `${temps.length} Sensoren · wärmster: ${hottest.label}`, [70, 85]);
      } else {
        tCard.classList.add('unavailable');
        setStat('temp', null, null, 'Keine Sensoren gefunden');
      }
    }

    // GPU
    const gpus = s.gpus || [];
    const gCard = $('#stat-gpu');
    if (gpus.length) {
      gCard.classList.remove('unavailable');
      const g = gpus[0];
      setStat('gpu', g.util, g.util, gpus.length > 1 ? `${gpus.length} GPUs · ${g.name}` : g.name, [80, 95]);
      $('[data-v="gpu-temp"]', gCard).textContent = g.temp != null ? nf0.format(g.temp) + ' °C' : '';
      const extra = [];
      if (g.mem_used_mb != null && g.mem_total_mb) extra.push(`VRAM ${nf0.format(g.mem_used_mb)} / ${nf0.format(g.mem_total_mb)} MB`);
      if (g.power_w != null) extra.push(nf0.format(g.power_w) + ' W');
      if (extra.length) $('[data-v="gpu-meta"]', gCard).textContent = (gpus.length > 1 ? `${gpus.length} GPUs · ` : '') + extra.join(' · ');
    } else {
      gCard.classList.add('unavailable');
      setStat('gpu', null, null, 'Keine NVIDIA-GPU / nvidia-smi');
      $('[data-v="gpu-temp"]', gCard).textContent = '';
    }

    // RAM
    const mem = s.memory;
    if (mem) setStat('mem', mem.pct, mem.pct, `${fmtBytes(mem.used)} von ${fmtBytes(mem.total)} belegt`, [80, 92]);
    else setStat('mem', null, null, '–');

    // Disk
    const disk = s.disk;
    if (disk) {
      setStat('disk', disk.pct, disk.pct, `${fmtBytes(disk.used)} belegt · ${fmtBytes(disk.available)} frei von ${fmtBytes(disk.total)}`, [80, 92]);
      $('#stat-disk .stat-head span').textContent = 'Speicherplatz ' + (disk.mount || '/');
    } else {
      setStat('disk', null, null, '–');
    }

    // Dienste
    const services = o.services || [];
    const up = services.filter((x) => x.last_active === true).length;
    const down = services.filter((x) => x.last_active === false);
    const card = $('#stat-services');
    $('[data-v="svc"]', card).textContent = services.length ? String(up) : '–';
    $('[data-v="svc-total"]', card).textContent = services.length ? `/ ${services.length} aktiv` : '';
    const svcPct = services.length ? (up / services.length) * 100 : 0;
    const bar = $('[data-bar="svc"]', card);
    bar.style.width = svcPct + '%';
    bar.className = down.length ? 'danger' : '';
    bar.style.background = down.length ? '' : 'var(--ok)';
    $('[data-v="svc-meta"]', card).textContent = services.length
      ? (down.length ? 'Inaktiv: ' + down.map((x) => x.display_name).join(', ') : 'Alle Dienste laufen')
      : 'Keine Dienste konfiguriert';

    renderServices(services);
  }

  function renderServices(services) {
    const wrap = $('#services');
    wrap.textContent = '';
    if (!services.length) {
      const p = document.createElement('p');
      p.className = 'services-empty';
      p.textContent = 'Noch keine Dienste konfiguriert. Über „Verwalten“ lassen sich systemd-Units hinzufügen.';
      wrap.appendChild(p);
      return;
    }
    for (const svc of services) {
      const el = document.createElement('div');
      el.className = 'service' + (svc.last_active === false ? ' down' : '');
      const dot = document.createElement('span');
      dot.className = 'dot ' + (svc.last_active === true ? 'ok' : svc.last_active === false ? 'danger' : '');
      const txt = document.createElement('div');
      const name = document.createElement('div');
      name.className = 'service-name';
      name.textContent = svc.display_name;
      const stateEl = document.createElement('div');
      stateEl.className = 'service-state';
      stateEl.textContent = (svc.name !== svc.display_name ? svc.name + ' · ' : '') + (svc.last_state || 'unbekannt');
      txt.appendChild(name);
      txt.appendChild(stateEl);
      const upt = document.createElement('div');
      upt.className = 'service-uptime';
      upt.title = 'Verfügbarkeit der letzten 24 h';
      upt.textContent = svc.uptime_24h == null ? '' : nf1.format(svc.uptime_24h) + ' %';
      el.appendChild(dot); el.appendChild(txt); el.appendChild(upt);
      el.title = svc.last_check ? 'Geprüft ' + fmtAgo(svc.last_check) : '';
      wrap.appendChild(el);
    }
  }

  // ---------------------------------------------------------------------
  // Charts
  // ---------------------------------------------------------------------
  function initCharts() {
    if (state.charts.cpu) return;
    const mk = (id, opts) => new LineChart($(`#chart-${id}`), Object.assign({
      legendEl: $(`#legend-${id}`),
      tooltipEl: $(`#chart-${id}`).parentElement.querySelector('.chart-tooltip'),
    }, opts));
    state.charts.cpu = mk('cpu', { unit: ' %', min: 0, max: 100 });
    state.charts.temp = mk('temp', { unit: ' °C', min: 0, softMax: 80 });
    state.charts.gpuUtil = mk('gpu-util', { unit: ' %', min: 0, max: 100 });
    state.charts.gpuTemp = mk('gpu-temp', { unit: ' °C', min: 0, softMax: 80 });
    state.charts.disk = mk('disk', { unit: ' %', min: 0, max: 100 });
    state.charts.mem = mk('mem', { unit: '', min: 0, softMax: 100, yFormat: (v) => nf1.format(v) });
  }

  function renderCharts() {
    const h = state.history;
    if (!h) return;
    const S = h.series || {};
    const base = { from: h.from, to: h.to, step: h.step };
    const snap = (state.overview && state.overview.snapshot) || {};

    // CPU
    const coreKeys = Object.keys(S).filter((k) => /^cpu\.core\.\d+$/.test(k)).sort((a, b) => +a.split('.')[2] - +b.split('.')[2]);
    const cpuSeries = [];
    if (state.showCores) {
      coreKeys.forEach((k, i) => cpuSeries.push({
        key: k, label: 'Kern ' + k.split('.')[2], color: LineChart.paletteColor(i, coreKeys.length, 55, 55), points: S[k], width: 1,
      }));
    }
    cpuSeries.push({ key: 'cpu.total', label: 'Gesamt', color: '#4f8cff', points: S['cpu.total'] || [], width: 2.2, fill: !state.showCores || coreKeys.length === 0 });
    state.charts.cpu.setData({ ...base, series: cpuSeries });

    // Temperaturen
    const isVm = !!snap.is_vm;
    const labelMap = new Map((snap.temps || []).map((t) => [t.key, t.label]));
    const tempKeys = Object.keys(S).filter((k) => k.startsWith('temp.') && k !== 'temp.max')
      .sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    const tempSeries = tempKeys.map((k, i) => ({
      key: k, label: labelMap.get(k) || k.replace(/^temp\./, ''), color: LineChart.paletteColor(i, tempKeys.length, 70, 60), points: S[k],
      width: tempKeys.length > 8 ? 1.2 : 1.6,
    }));
    $('#card-temp').classList.toggle('hidden', isVm || (tempSeries.length === 0 && !(snap.temps || []).length));
    state.charts.temp.setData({ ...base, series: isVm ? [] : tempSeries });

    // GPU
    const gpuIdx = Array.from(new Set(Object.keys(S).filter((k) => /^gpu\.\d+\./.test(k)).map((k) => +k.split('.')[1]))).sort();
    const gpuName = (i) => {
      const g = (snap.gpus || []).find((x) => x.index === i);
      return g ? (gpuIdx.length > 1 ? `GPU ${i} · ${g.name}` : g.name) : `GPU ${i}`;
    };
    const hasGpu = gpuIdx.length > 0 || (snap.gpus || []).length > 0;
    $('#card-gpu-util').classList.toggle('hidden', !hasGpu);
    $('#card-gpu-temp').classList.toggle('hidden', !hasGpu);
    if (hasGpu) {
      state.charts.gpuUtil.setData({
        ...base,
        series: gpuIdx.flatMap((i, n) => {
          const out = [{ key: `gpu.${i}.util`, label: gpuName(i) + ' – Auslastung', color: n === 0 ? '#7c5cff' : LineChart.paletteColor(n, gpuIdx.length), points: S[`gpu.${i}.util`] || [], width: 2, fill: gpuIdx.length === 1 }];
          if (S[`gpu.${i}.mem_pct`]) out.push({ key: `gpu.${i}.mem_pct`, label: gpuName(i) + ' – VRAM', color: n === 0 ? '#a78bfa' : LineChart.paletteColor(n + 3, gpuIdx.length + 3), points: S[`gpu.${i}.mem_pct`], width: 1.4, dash: [4, 3] });
          return out;
        }),
      });
      state.charts.gpuTemp.setData({
        ...base,
        series: gpuIdx.map((i, n) => ({ key: `gpu.${i}.temp`, label: gpuName(i), color: n === 0 ? '#fb923c' : LineChart.paletteColor(n, gpuIdx.length, 75, 60), points: S[`gpu.${i}.temp`] || [], width: 2, fill: gpuIdx.length === 1 })),
      });
    }

    // Disk
    state.charts.disk.setData({
      ...base,
      series: [{ key: 'disk.used_pct', label: 'Belegt', color: '#34d399', points: S['disk.used_pct'] || [], width: 2, fill: true }],
    });

    // RAM & Load
    state.charts.mem.setData({
      ...base,
      series: [
        { key: 'mem.used_pct', label: 'RAM belegt (%)', color: '#f472b6', points: S['mem.used_pct'] || [], width: 2, fill: true },
        { key: 'sys.load1', label: 'Load (1 min)', color: '#fbbf24', points: S['sys.load1'] || [], width: 1.6, dash: [5, 4] },
      ],
    });
  }

  function setRange(range, reload = true) {
    if (!RANGES.includes(range)) range = '3h';
    state.range = range;
    localStorage.setItem('qi.range', range);
    $$('.range-picker button').forEach((b) => {
      const active = b.dataset.range === range;
      b.classList.toggle('active', active);
      b.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (reload) {
      api('history?range=' + range).then((h) => { state.history = h; renderCharts(); })
        .catch((e) => { if (e.status !== 401) toast(e.message, 'danger'); });
    }
  }

  // ---------------------------------------------------------------------
  // Einstellungen: Dienste & Passwort
  // ---------------------------------------------------------------------
  function openSettings(tab = 'services') {
    $('#modal-settings').classList.remove('hidden');
    switchTab(tab);
    renderServiceTable();
    loadAvailableUnits();
    setTimeout(() => { const i = tab === 'services' ? $('#svc-name') : tab === 'password' ? $('#pw-current') : null; if (i) i.focus(); }, 60);
  }

  function closeSettings() {
    $('#modal-settings').classList.add('hidden');
    hideApiKeyReveal();
  }

  function switchTab(tab) {
    $$('#modal-settings .tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === tab));
    $$('#modal-settings .tab-panel').forEach((p) => p.classList.toggle('hidden', p.dataset.panel !== tab));
    if (tab === 'api') loadApiKeyInfo();
    if (tab === 'system') renderSystemForm(state.system);
    if (tab === 'docker') { loadDockerConfig().then(renderDockerForm); }
  }

  async function loadAvailableUnits() {
    if (state.availableUnits) return;
    try {
      const { units } = await api('services/available');
      state.availableUnits = units || [];
      const dl = $('#svc-units');
      dl.textContent = '';
      for (const u of state.availableUnits) {
        const opt = document.createElement('option');
        opt.value = u.name;
        opt.label = `${u.active}${u.sub ? ' (' + u.sub + ')' : ''} – ${u.description}`;
        dl.appendChild(opt);
      }
    } catch (_) { /* Autovervollständigung ist optional */ }
  }

  function renderServiceTable() {
    const tbody = $('#svc-table tbody');
    tbody.textContent = '';
    const services = (state.overview && state.overview.services) || [];
    if (!services.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 4; td.className = 'muted'; td.textContent = 'Keine Dienste konfiguriert.';
      tr.appendChild(td); tbody.appendChild(tr);
      return;
    }
    for (const svc of services) {
      const tr = document.createElement('tr');
      const tdName = document.createElement('td');
      const code = document.createElement('code');
      code.textContent = svc.name;
      tdName.appendChild(code);

      const tdDisplay = document.createElement('td');
      const inp = document.createElement('input');
      inp.type = 'text'; inp.value = svc.display_name; inp.maxLength = 128; inp.title = 'Anzeigename – Enter zum Speichern';
      inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
      inp.addEventListener('blur', async () => {
        const val = inp.value.trim();
        if (!val || val === svc.display_name) { inp.value = svc.display_name; return; }
        try {
          const res = await api('services/' + svc.id, { method: 'PUT', body: { display_name: val } });
          applyServices(res.services);
          toast('Anzeigename gespeichert', 'ok');
        } catch (e) { toast(e.message, 'danger'); inp.value = svc.display_name; }
      });
      tdDisplay.appendChild(inp);

      const tdState = document.createElement('td');
      const badge = document.createElement('span');
      badge.className = 'state-badge';
      const dot = document.createElement('span');
      dot.className = 'dot ' + (svc.last_active === true ? 'ok' : svc.last_active === false ? 'danger' : '');
      badge.appendChild(dot);
      badge.appendChild(document.createTextNode(svc.last_state || 'unbekannt'));
      tdState.appendChild(badge);

      const tdAct = document.createElement('td');
      const del = document.createElement('button');
      del.className = 'btn btn-small btn-danger'; del.type = 'button'; del.title = 'Entfernen';
      del.innerHTML = '<svg class="icon"><use href="#i-trash"/></svg>';
      del.addEventListener('click', async () => {
        if (!confirm(`„${svc.display_name}“ (${svc.name}) nicht mehr überwachen?`)) return;
        try {
          const res = await api('services/' + svc.id, { method: 'DELETE' });
          applyServices(res.services);
          toast('Dienst entfernt', 'ok');
        } catch (e) { toast(e.message, 'danger'); }
      });
      tdAct.appendChild(del);

      tr.appendChild(tdName); tr.appendChild(tdDisplay); tr.appendChild(tdState); tr.appendChild(tdAct);
      tbody.appendChild(tr);
    }
  }

  function applyServices(services) {
    if (!state.overview) state.overview = { services: [] };
    state.overview.services = services || [];
    renderOverview();
    renderServiceTable();
  }

  async function onAddService(ev) {
    ev.preventDefault();
    const err = $('#svc-error');
    err.hidden = true;
    const name = $('#svc-name').value.trim();
    const display = $('#svc-display').value.trim();
    if (!name) return;
    const btn = ev.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
      const res = await api('services', { method: 'POST', body: { name, display_name: display } });
      applyServices(res.services);
      $('#svc-name').value = ''; $('#svc-display').value = '';
      toast('Dienst hinzugefügt', 'ok');
      $('#svc-name').focus();
    } catch (e) {
      err.textContent = e.message; err.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  async function onChangePassword(ev) {
    ev.preventDefault();
    const msg = $('#pw-msg');
    msg.hidden = true; msg.classList.remove('ok');
    const current = $('#pw-current').value, nw = $('#pw-new').value, rep = $('#pw-repeat').value;
    if (nw !== rep) { msg.textContent = 'Die Passwörter stimmen nicht überein.'; msg.hidden = false; return; }
    try {
      await api('password', { method: 'POST', body: { current, new: nw } });
      msg.textContent = 'Passwort erfolgreich geändert.'; msg.classList.add('ok'); msg.hidden = false;
      ev.target.reset();
    } catch (e) {
      msg.textContent = e.message; msg.hidden = false;
    }
  }

  function renderSystemForm(system) {
    if (!system) return;
    $('#sys-desc').value = system.description || '';
    $('#sys-inventory').value = system.inventory || '';
    $('#sys-inv-field').hidden = !!system.is_vm;
  }

  async function onSystemSave(ev) {
    ev.preventDefault();
    const msg = $('#sys-msg');
    msg.hidden = true; msg.classList.remove('ok');
    const btn = ev.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
      const res = await api('system', {
        method: 'POST',
        body: { description: $('#sys-desc').value.trim(), inventory: $('#sys-inventory').value.trim() },
      });
      state.system = res.system;
      renderSystemForm(res.system);
      renderLoginSystem(res.system);
      msg.textContent = 'Gespeichert.'; msg.classList.add('ok'); msg.hidden = false;
      toast('System-Informationen gespeichert', 'ok');
    } catch (e) {
      msg.textContent = e.message; msg.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  // ---------------------------------------------------------------------
  // Einstellungen: API-Schlüssel / Management-Board
  // ---------------------------------------------------------------------
  function fmtDateTime(ts) {
    return ts ? new Date(ts * 1000).toLocaleString('de-DE') : '–';
  }

  async function loadApiKeyInfo() {
    try {
      state.apiKey = await api('apikey');
      renderApiKey();
    } catch (e) {
      if (e.status !== 401) showApiMsg(e.message);
    }
  }

  function showApiMsg(text, ok = false) {
    const msg = $('#api-msg');
    msg.textContent = text; msg.classList.toggle('ok', ok); msg.hidden = !text;
  }

  function hideApiKeyReveal() {
    $('#api-key-reveal').hidden = true;
    $('#api-key-value').textContent = '';
  }

  function renderApiKey() {
    const info = state.apiKey;
    if (!info) return;
    const key = info.key;
    const badge = $('#api-key-state');
    const dot = $('.dot', badge), label = $('span:last-child', badge);

    if (key) {
      $('#api-key-title').textContent = 'API-Schlüssel aktiv';
      $('#api-key-sub').textContent = `Bezeichnung „${key.label}“ · erstellt von ${key.created_by || 'unbekannt'}`;
      dot.className = 'dot ok'; label.textContent = 'aktiv';
      $('#api-key-meta').hidden = false;
      $('#api-key-prefix').textContent = key.prefix + '…';
      $('#api-key-created').textContent = fmtDateTime(key.created_at);
      $('#api-key-used').textContent = key.last_used_at
        ? `${fmtDateTime(key.last_used_at)} (${fmtAgo(key.last_used_at)})${key.last_used_ip ? ' von ' + key.last_used_ip : ''}`
        : 'noch nie – Management-Board noch nicht verbunden';
      $('#api-key-count').textContent = nf0.format(key.use_count || 0);
      $('#btn-api-rotate span').textContent = 'Schlüssel neu generieren (rotieren)';
      $('#btn-api-revoke').hidden = false;
    } else {
      $('#api-key-title').textContent = 'Kein API-Schlüssel konfiguriert';
      $('#api-key-sub').textContent = 'Die API /api/v1/… lehnt derzeit alle Anfragen ab (401).';
      dot.className = 'dot'; label.textContent = 'inaktiv';
      $('#api-key-meta').hidden = true;
      $('#btn-api-rotate span').textContent = 'Schlüssel generieren';
      $('#btn-api-revoke').hidden = true;
    }

    $('#api-base-url').textContent = info.base_url || location.origin;
    const eps = $('#api-endpoints');
    eps.textContent = '';
    (info.endpoints || []).forEach((p, i) => {
      if (i) eps.appendChild(document.createTextNode(' · '));
      const c = document.createElement('code'); c.textContent = p; eps.appendChild(c);
    });
    const cors = info.cors_origins || [];
    $('#api-cors').textContent = !cors.length ? 'deaktiviert' : cors.includes('*') ? 'alle Origins (*)' : cors.join(', ');
    $('#api-example').textContent =
      `curl -k -H "Authorization: Bearer <API-Schlüssel>" \\\n     "${info.base_url || location.origin}/api/v1/status"`;
  }

  async function onRotateApiKey() {
    const hasKey = !!(state.apiKey && state.apiKey.key);
    if (hasKey && !confirm('Den API-Schlüssel wirklich neu generieren?\n\nDer bisherige Schlüssel wird sofort ungültig; das Management-Board muss mit dem neuen Schlüssel neu gekoppelt werden.')) return;
    const btn = $('#btn-api-rotate');
    btn.disabled = true;
    showApiMsg('');
    try {
      const res = await api('apikey/rotate', { method: 'POST', body: {} });
      state.apiKey = res;
      renderApiKey();
      $('#api-key-value').textContent = res.api_key || '';
      $('#api-key-reveal').hidden = false;
      toast(hasKey ? 'API-Schlüssel rotiert' : 'API-Schlüssel erzeugt', 'ok');
      $('#api-key-reveal').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } catch (e) {
      showApiMsg(e.message);
    } finally {
      btn.disabled = false;
    }
  }

  async function onRevokeApiKey() {
    if (!confirm('API-Schlüssel widerrufen?\n\nDas Management-Board kann danach keine Daten mehr abrufen, bis ein neuer Schlüssel erzeugt wurde.')) return;
    const btn = $('#btn-api-revoke');
    btn.disabled = true;
    showApiMsg('');
    try {
      state.apiKey = await api('apikey', { method: 'DELETE' });
      hideApiKeyReveal();
      renderApiKey();
      toast('API-Schlüssel widerrufen', 'ok');
    } catch (e) {
      showApiMsg(e.message);
    } finally {
      btn.disabled = false;
    }
  }

  async function onCopyApiKey() {
    const value = $('#api-key-value').textContent;
    if (!value) return;
    let copied = false;
    if (navigator.clipboard && window.isSecureContext) {
      try { await navigator.clipboard.writeText(value); copied = true; } catch (_) { /* Fallback unten */ }
    }
    if (!copied) {
      const range = document.createRange();
      range.selectNodeContents($('#api-key-value'));
      const sel = window.getSelection();
      sel.removeAllRanges(); sel.addRange(range);
      try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
    }
    toast(copied ? 'Schlüssel in die Zwischenablage kopiert' : 'Kopieren nicht möglich – bitte manuell markieren', copied ? 'ok' : 'danger');
  }

  // ---------------------------------------------------------------------
  // Docker
  // ---------------------------------------------------------------------
  function dockerEnabled() {
    return !!(state.docker.config && state.docker.config.enabled);
  }

  async function loadDockerConfig() {
    try {
      state.docker.config = await api('docker/config');
    } catch (e) {
      if (e.status !== 401) state.docker.config = null;
    }
    applyDockerConfig();
  }

  function applyDockerConfig() {
    const cfg = state.docker.config;
    const enabled = !!(cfg && cfg.enabled);
    const tabBtn = $('#tab-btn-docker');
    if (tabBtn) tabBtn.classList.toggle('hidden', !enabled);
    const hostLabel = $('#docker-host-label');
    if (hostLabel) hostLabel.textContent = enabled && cfg.host ? `${cfg.username || 'root'}@${cfg.host}:${cfg.port || 22}` : '';
    if (!enabled) switchMainTab('info', false);
  }

  function switchMainTab(tab, refresh = true) {
    const valid = ['info', 'docker'];
    if (!valid.includes(tab)) tab = 'info';
    $$('.main-tabs .tab').forEach((t) => {
      const active = t.dataset.maintab === tab;
      t.classList.toggle('active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    $$('.main-tab-panel').forEach((p) => p.classList.toggle('hidden', p.dataset.panelMain !== tab));
    if (tab === 'docker' && refresh && dockerEnabled()) refreshDocker();
  }

  async function refreshDocker() {
    if (!dockerEnabled()) return;
    const btn = $('#btn-docker-refresh');
    const dot = $('#docker-status-dot');
    const txt = $('#docker-status-text');
    if (btn) btn.disabled = true;
    try {
      const [containersRes, foldersRes] = await Promise.all([
        api('docker/containers'),
        api('docker/folders'),
      ]);
      state.docker.containers = containersRes.containers || [];
      state.docker.folders = foldersRes.folders || [];
      renderDockerList();
      if (dot) dot.className = 'dot ok';
      if (txt) txt.textContent = `${state.docker.containers.length} Container`;
    } catch (e) {
      if (dot) dot.className = 'dot danger';
      if (txt) txt.textContent = e.message;
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function loadDockerFolders() {
    try {
      const { folders } = await api('docker/folders');
      state.docker.folders = folders || [];
      renderDockerList();
    } catch (e) {
      if (e.status !== 401) toast('Ordner konnten nicht geladen werden: ' + e.message, 'danger');
    }
  }

  function folderIdOf(name) {
    for (const f of state.docker.folders) {
      if ((f.containers || []).includes(name)) return f.id;
    }
    return null;
  }

  function buildDockerItem(c, folderId) {
    const el = document.createElement('div');
    el.className = 'docker-item' + (c.name === state.docker.selected ? ' active' : '');
    el.draggable = true;
    el.dataset.folderId = folderId === undefined || folderId === null ? '' : String(folderId);
    const dot = document.createElement('span');
    dot.className = 'dot ' + (c.state === 'running' ? 'ok' : c.state === 'exited' || c.state === 'dead' ? 'danger' : '');
    const info = document.createElement('div');
    info.className = 'docker-item-info';
    const name = document.createElement('div');
    name.className = 'docker-item-name';
    name.textContent = c.name;
    const image = document.createElement('div');
    image.className = 'docker-item-image';
    image.textContent = c.image || '';
    const st = document.createElement('div');
    st.className = 'docker-item-state';
    st.textContent = c.status || c.state;
    info.appendChild(name); info.appendChild(image); info.appendChild(st);
    el.appendChild(dot); el.appendChild(info);
    el.addEventListener('click', () => selectDockerContainer(c.name));
    el.addEventListener('dragstart', onContainerDragStart);
    el.addEventListener('dragend', onContainerDragEnd);
    el.addEventListener('dragover', onContainerDragOverItem);
    el.addEventListener('drop', (e) => onContainerDrop(e, folderId, c.name));
    return el;
  }

  function buildDockerFolder(folder) {
    const el = document.createElement('div');
    el.className = 'docker-folder';
    el.dataset.folderId = String(folder.id);

    const header = document.createElement('div');
    header.className = 'docker-folder-header';

    const grip = document.createElement('span');
    grip.className = 'docker-folder-grip';
    grip.draggable = true;
    grip.title = 'Zum Sortieren ziehen';
    grip.textContent = '⠿';

    const caret = document.createElement('button');
    caret.type = 'button';
    caret.className = 'docker-folder-caret';
    caret.setAttribute('aria-label', 'Ordner auf-/zuklappen');
    caret.textContent = '▸';

    const nameEl = document.createElement('span');
    nameEl.className = 'docker-folder-name';
    nameEl.textContent = folder.name;
    nameEl.title = 'Doppelklick zum Umbenennen';

    const count = document.createElement('span');
    count.className = 'docker-folder-count';
    count.textContent = (folder.containers || []).length;

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'docker-folder-delete';
    del.title = 'Ordner löschen';
    del.setAttribute('aria-label', 'Ordner löschen');
    del.textContent = '×';

    header.appendChild(grip);
    header.appendChild(caret);
    header.appendChild(nameEl);
    header.appendChild(count);
    header.appendChild(del);

    const body = document.createElement('div');
    body.className = 'docker-folder-body';
    body.dataset.folderId = String(folder.id);
    for (const name of (folder.containers || [])) {
      const c = state.docker.containers.find((x) => x.name === name);
      if (c) body.appendChild(buildDockerItem(c, folder.id));
    }

    el.appendChild(header);
    el.appendChild(body);

    caret.addEventListener('click', (ev) => { ev.stopPropagation(); el.classList.toggle('collapsed'); });
    header.addEventListener('click', () => el.classList.toggle('collapsed'));
    nameEl.addEventListener('dblclick', (ev) => { ev.stopPropagation(); startFolderRename(el, folder, nameEl); });
    del.addEventListener('click', (ev) => { ev.stopPropagation(); deleteDockerFolder(folder); });

    grip.addEventListener('dragstart', onFolderDragStart);
    grip.addEventListener('dragend', onFolderDragEnd);
    header.addEventListener('dragover', onFolderDragOver);
    header.addEventListener('dragleave', onFolderDragLeave);
    header.addEventListener('drop', onFolderDrop);

    body.addEventListener('dragover', onContainerDragOver);
    body.addEventListener('dragleave', onContainerDragLeave);
    body.addEventListener('drop', (e) => onContainerDrop(e, folder.id, null));

    return el;
  }

  function renderDockerList() {
    if (state.docker.dragging) return; // während eines Drags nicht neu rendern
    const wrap = $('#docker-list');
    if (!wrap) return;
    wrap.textContent = '';
    const list = state.docker.containers || [];
    const folders = state.docker.folders || [];

    if (!list.length && !folders.length) {
      const p = document.createElement('p');
      p.className = 'muted';
      p.textContent = 'Keine Container gefunden.';
      wrap.appendChild(p);
      return;
    }

    const membership = new Set();
    for (const f of folders) for (const n of (f.containers || [])) membership.add(n);

    // Wurzelbereich ("Ohne Ordner") – dient gleichzeitig als Ablage zum Herausnehmen.
    const root = document.createElement('div');
    root.className = 'docker-folder root';
    const rootHeader = document.createElement('div');
    rootHeader.className = 'docker-folder-header root-header';
    rootHeader.textContent = 'Ohne Ordner';
    const rootBody = document.createElement('div');
    rootBody.className = 'docker-folder-body';
    rootBody.dataset.folderId = '';
    for (const c of list) {
      if (!membership.has(c.name)) rootBody.appendChild(buildDockerItem(c, null));
    }
    rootBody.addEventListener('dragover', onContainerDragOver);
    rootBody.addEventListener('dragleave', onContainerDragLeave);
    rootBody.addEventListener('drop', (e) => onContainerDrop(e, null, null));
    root.appendChild(rootHeader);
    root.appendChild(rootBody);
    wrap.appendChild(root);

    for (const f of folders) wrap.appendChild(buildDockerFolder(f));
  }

  // ---------------------------------------------------------------------
  // Drag & Drop (Container)
  // ---------------------------------------------------------------------
  function clearContainerDropTargets() {
    $$('.docker-item.drop-target, .docker-folder-body.drop-target').forEach((el) => el.classList.remove('drop-target'));
  }

  function onContainerDragStart(e) {
    const name = e.currentTarget.querySelector('.docker-item-name')?.textContent || '';
    if (!name) return;
    state.docker.dragging = name;
    e.currentTarget.classList.add('dragging');
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', name);
    }
  }

  function onContainerDragEnd(e) {
    state.docker.dragging = null;
    $$('.docker-item.dragging').forEach((el) => el.classList.remove('dragging'));
    clearContainerDropTargets();
  }

  function onContainerDragOver(e) {
    if (!state.docker.dragging) return;
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
    clearContainerDropTargets();
    e.currentTarget.classList.add('drop-target');
  }

  function onContainerDragOverItem(e) {
    if (!state.docker.dragging) return;
    e.preventDefault();
    e.stopPropagation();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
    clearContainerDropTargets();
    e.currentTarget.classList.add('drop-target');
  }

  function onContainerDragLeave(e) {
    if (e.currentTarget === e.target) e.currentTarget.classList.remove('drop-target');
  }

  async function onContainerDrop(e, folderId, beforeName) {
    if (!state.docker.dragging) return;
    e.preventDefault();
    if (e.stopPropagation) e.stopPropagation();
    const name = state.docker.dragging;
    clearContainerDropTargets();
    state.docker.dragging = null;
    if (name === beforeName) return;
    await moveDockerContainer(name, folderId, beforeName);
  }

  async function moveDockerContainer(name, targetFolderId, beforeName) {
    const folders = state.docker.folders;
    const target = folders.find((f) => f.id === targetFolderId) || null;
    if (folderIdOf(name) === null && targetFolderId === null) return; // bereits ohne Ordner
    try {
      if (targetFolderId === null) {
        await api('docker/containers/' + encodeURIComponent(name) + '/folder', { method: 'PUT', body: { folder_id: null } });
      } else {
        const base = ((target && target.containers) || []).filter((n) => n !== name);
        let at = base.length;
        if (beforeName) { const bi = base.indexOf(beforeName); if (bi >= 0) at = bi; }
        base.splice(at, 0, name);
        await api('docker/folders/' + targetFolderId + '/containers', { method: 'PUT', body: { containers: base } });
      }
      for (const f of folders) f.containers = (f.containers || []).filter((n) => n !== name);
      if (targetFolderId !== null && target) {
        let at = target.containers.length;
        if (beforeName) { const bi = target.containers.indexOf(beforeName); if (bi >= 0) at = bi; }
        target.containers.splice(at, 0, name);
      }
      renderDockerList();
    } catch (e) {
      toast(e.message, 'danger');
      renderDockerList();
    }
  }

  // ---------------------------------------------------------------------
  // Drag & Drop (Ordner-Reihenfolge)
  // ---------------------------------------------------------------------
  function clearFolderDropTargets() {
    $$('.docker-folder.drop-before, .docker-folder.drop-after').forEach((el) => el.classList.remove('drop-before', 'drop-after'));
  }

  function onFolderDragStart(e) {
    if (state.docker.dragging) { e.preventDefault(); return; }
    const folderEl = e.currentTarget.closest('.docker-folder');
    state.docker.draggingFolder = Number(folderEl.dataset.folderId);
    folderEl.classList.add('dragging');
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', folderEl.dataset.folderId);
    }
  }

  function onFolderDragEnd() {
    state.docker.draggingFolder = null;
    $$('.docker-folder.dragging').forEach((el) => el.classList.remove('dragging'));
    clearFolderDropTargets();
  }

  function onFolderDragOver(e) {
    if (state.docker.draggingFolder == null) return;
    const folderEl = e.currentTarget.closest('.docker-folder');
    if (!folderEl || folderEl.classList.contains('root')) return;
    const id = Number(folderEl.dataset.folderId);
    if (id === state.docker.draggingFolder) return;
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
    clearFolderDropTargets();
    const rect = e.currentTarget.getBoundingClientRect();
    const before = (e.clientY - rect.top) < rect.height / 2;
    folderEl.classList.add(before ? 'drop-before' : 'drop-after');
  }

  function onFolderDragLeave(e) {
    const folderEl = e.currentTarget.closest('.docker-folder');
    if (folderEl && !folderEl.contains(e.relatedTarget)) {
      folderEl.classList.remove('drop-before', 'drop-after');
    }
  }

  function onFolderDrop(e) {
    if (state.docker.draggingFolder == null) return;
    e.preventDefault();
    const folderEl = e.currentTarget.closest('.docker-folder');
    if (!folderEl || folderEl.classList.contains('root')) return;
    const targetId = Number(folderEl.dataset.folderId);
    const sourceId = state.docker.draggingFolder;
    clearFolderDropTargets();
    if (sourceId === targetId) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const before = (e.clientY - rect.top) < rect.height / 2;
    reorderFolders(sourceId, targetId, before);
  }

  async function reorderFolders(sourceId, targetId, before) {
    const folders = state.docker.folders;
    const ids = folders.map((f) => f.id);
    const from = ids.indexOf(sourceId);
    if (from < 0) return;
    ids.splice(from, 1);
    let to = ids.indexOf(targetId);
    if (to < 0) return;
    ids.splice(before ? to : to + 1, 0, sourceId);
    try {
      await api('docker/folders/order', { method: 'PUT', body: { ids } });
      const map = new Map(folders.map((f) => [f.id, f]));
      state.docker.folders = ids.map((id) => map.get(id));
      renderDockerList();
    } catch (e) {
      toast(e.message, 'danger');
      renderDockerList();
    }
  }

  // ---------------------------------------------------------------------
  // Ordner-Verwaltung
  // ---------------------------------------------------------------------
  async function onAddDockerFolder(ev) {
    ev.preventDefault();
    const input = $('#docker-folder-add-input');
    const name = (input.value || '').trim();
    if (!name) { input.focus(); return; }
    try {
      await api('docker/folders', { method: 'POST', body: { name } });
      input.value = '';
      await loadDockerFolders();
      toast('Ordner angelegt', 'ok');
    } catch (e) {
      toast(e.message, 'danger');
    }
  }

  function startFolderRename(folderEl, folder, nameEl) {
    if (folderEl.querySelector('input.docker-folder-rename-input')) return;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'docker-folder-rename-input';
    input.value = folder.name;
    input.maxLength = 128;
    nameEl.replaceWith(input);
    input.focus();
    input.select();

    let done = false;
    const finish = async (save) => {
      if (done) return;
      done = true;
      const newName = (input.value || '').trim();
      input.replaceWith(nameEl);
      if (!save || newName === '' || newName === folder.name) return;
      try {
        await api('docker/folders/' + folder.id, { method: 'PUT', body: { name: newName } });
        folder.name = newName;
        nameEl.textContent = newName;
      } catch (e) {
        toast(e.message, 'danger');
        nameEl.textContent = folder.name;
      }
    };
    input.addEventListener('click', (e) => e.stopPropagation());
    input.addEventListener('dblclick', (e) => e.stopPropagation());
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { finish(false); }
    });
    input.addEventListener('blur', () => finish(true));
  }

  async function deleteDockerFolder(folder) {
    if (!window.confirm(`Ordner „${folder.name}" löschen? Die enthaltenen Container werden dann ohne Ordner angezeigt.`)) return;
    try {
      await api('docker/folders/' + folder.id, { method: 'DELETE' });
      await loadDockerFolders();
      toast('Ordner gelöscht', 'ok');
    } catch (e) {
      toast(e.message, 'danger');
    }
  }

  async function selectDockerContainer(name) {
    if (!name) return;
    state.docker.selected = name;
    renderDockerList();
    const emptyEl = $('#docker-empty');
    if (emptyEl) emptyEl.hidden = true;
    const detailEl = $('#docker-detail');
    if (detailEl) detailEl.hidden = false;
    const nameEl = $('#docker-detail-name');
    if (nameEl) nameEl.textContent = name;
    const metaEl = $('#docker-detail-meta');
    if (metaEl) metaEl.textContent = 'Lade …';
    ['docker-stats', 'docker-mounts', 'docker-networks', 'docker-ports'].forEach((id) => { const n = $('#' + id); if (n) n.textContent = ''; });
    const pre = $('#docker-logs'); if (pre) { pre.textContent = ''; pre.classList.remove('empty'); }
    const note = $('#docker-note'); if (note) note.value = '';
    const noteMeta = $('#docker-note-meta'); if (noteMeta) noteMeta.textContent = '';
    setDockerButtons(false);
    try {
      const [detail, stats] = await Promise.all([
        api('docker/containers/' + encodeURIComponent(name)),
        api('docker/containers/' + encodeURIComponent(name) + '/stats'),
      ]);
      state.docker.detail = detail;
      state.docker.stats = stats;
      renderDockerDetail();
    } catch (e) {
      if (metaEl) metaEl.textContent = e.message;
    }
  }

  function renderDockerDetail() {
    const d = state.docker.detail;
    const s = state.docker.stats;
    if (!d) return;
    const running = d.running === true;
    const nameEl = $('#docker-detail-name');
    if (nameEl) nameEl.textContent = (d.name || state.docker.selected).replace(/^\//, '');
    const metaEl = $('#docker-detail-meta');
    if (metaEl) {
      const meta = [];
      if (d.compose_project) meta.push('Compose: ' + d.compose_project + (d.compose_service ? '/' + d.compose_service : ''));
      if (d.image) meta.push(d.image);
      if (d.status) meta.push(d.status);
      metaEl.textContent = meta.join(' · ');
    }
    setDockerButtons(true, running);

    const wrap = $('#docker-stats');
    if (wrap) {
      wrap.textContent = '';
      const stats = [
        ['CPU', s && s.cpu != null ? s.cpu : '–'],
        ['RAM', s && s.memory != null ? s.memory : '–'],
        ['RAM %', s && s.memory_percent != null ? s.memory_percent : '–'],
        ['Netzwerk', s && s.network_io != null ? s.network_io : '–'],
        ['Block I/O', s && s.block_io != null ? s.block_io : '–'],
        ['PIDs', s && s.pids != null ? s.pids : '–'],
      ];
      for (const [label, value] of stats) {
        const box = document.createElement('div');
        box.className = 'docker-stat';
        const l = document.createElement('div'); l.className = 'docker-stat-label'; l.textContent = label;
        const v = document.createElement('div'); v.className = 'docker-stat-value'; v.textContent = value;
        box.appendChild(l); box.appendChild(v);
        wrap.appendChild(box);
      }
    }

    const mounts = $('#docker-mounts');
    if (mounts) {
      mounts.textContent = '';
      const mList = d.mounts || [];
      if (!mList.length) {
        mounts.appendChild(kvEmpty('Keine Mounts / Volumes.'));
      } else {
        for (const m of mList) {
          const row = document.createElement('div');
          row.className = 'kv-row';
          const k = document.createElement('div'); k.className = 'k';
          k.textContent = m.destination || m.name || m.type;
          const v = document.createElement('div'); v.className = 'v';
          const parts = [m.type];
          if (m.name) parts.push(m.name);
          if (m.source && m.source !== m.name) parts.push(m.source);
          parts.push(m.rw === false ? 'ro' : 'rw');
          if (m.mode) parts.push(m.mode);
          v.textContent = parts.join(' · ');
          row.appendChild(k); row.appendChild(v);
          mounts.appendChild(row);
        }
      }
    }

    const nets = $('#docker-networks');
    if (nets) {
      nets.textContent = '';
      const nList = d.networks || [];
      const pList = d.ports || [];
      if (!nList.length && !pList.length) {
        nets.appendChild(kvEmpty('Keine Netzwerke oder Port-Weiterleitungen.'));
      } else {
        for (const n of nList) {
          const row = document.createElement('div');
          row.className = 'kv-row';
          const k = document.createElement('div'); k.className = 'k'; k.textContent = n.name;
          const v = document.createElement('div'); v.className = 'v';
          const parts = ['IP ' + (n.ip || '–')];
          if (n.gateway) parts.push('Gateway ' + n.gateway);
          if (n.mac) parts.push('MAC ' + n.mac);
          v.textContent = parts.join(' · ');
          row.appendChild(k); row.appendChild(v);
          nets.appendChild(row);
        }
        for (const p of pList) {
          const row = document.createElement('div');
          row.className = 'kv-row';
          const k = document.createElement('div'); k.className = 'k';
          k.textContent = `Port ${p.container || '–'}`;
          const v = document.createElement('div'); v.className = 'v';
          v.textContent = `${p.host_ip || '0.0.0.0'}:${p.host_port || ''} → ${p.container || '–'}${p.protocol ? ' (' + p.protocol + ')' : ''}`;
          row.appendChild(k); row.appendChild(v);
          nets.appendChild(row);
        }
      }
    }

    const note = $('#docker-note');
    if (note) note.value = d.note || '';
    const noteMeta = $('#docker-note-meta');
    if (noteMeta) noteMeta.textContent = d.note_updated_at ? 'Zuletzt gespeichert ' + fmtDateTime(d.note_updated_at) : '';
  }

  function kvEmpty(text) {
    const d = document.createElement('div');
    d.className = 'kv-empty';
    d.textContent = text;
    return d;
  }

  function setDockerButtons(hasDetail, running) {
    const start = $('#btn-docker-start'), stop = $('#btn-docker-stop'), restart = $('#btn-docker-restart');
    if (start) start.disabled = !hasDetail || !!running;
    if (stop) stop.disabled = !hasDetail || !running;
    if (restart) restart.disabled = !hasDetail;
  }

  async function dockerAction(action) {
    const name = state.docker.selected;
    if (!name) return;
    const btn = action === 'start' ? $('#btn-docker-start') : action === 'stop' ? $('#btn-docker-stop') : $('#btn-docker-restart');
    if (btn) btn.disabled = true;
    try {
      await api('docker/containers/' + encodeURIComponent(name) + '/' + action, { method: 'POST' });
      toast(`Container ${action === 'restart' ? 'neu gestartet' : action === 'start' ? 'gestartet' : 'gestoppt'}`, 'ok');
      await refreshDocker();
      await selectDockerContainer(name);
    } catch (e) {
      toast(e.message, 'danger');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function dockerLoadLogs() {
    const name = state.docker.selected;
    if (!name) return;
    const lines = $('#docker-log-lines').value;
    const pre = $('#docker-logs');
    if (pre) pre.textContent = 'Lade Logs …';
    try {
      const { logs } = await api('docker/containers/' + encodeURIComponent(name) + '/logs?lines=' + encodeURIComponent(lines));
      if (pre) {
        pre.textContent = (logs && logs.length) ? logs.join('\n') : '(keine Log-Einträge)';
        pre.classList.toggle('empty', !logs || !logs.length);
      }
    } catch (e) {
      if (pre) pre.textContent = 'Fehler: ' + e.message;
    }
  }

  async function dockerSaveNote() {
    const name = state.docker.selected;
    if (!name) return;
    const note = $('#docker-note').value;
    const btn = $('#btn-docker-note-save');
    if (btn) btn.disabled = true;
    try {
      await api('docker/containers/' + encodeURIComponent(name) + '/note', { method: 'PUT', body: { note } });
      if (state.docker.detail) {
        state.docker.detail.note = note;
        state.docker.detail.note_updated_at = Math.floor(Date.now() / 1000);
      }
      const noteMeta = $('#docker-note-meta');
      if (noteMeta) noteMeta.textContent = 'Zuletzt gespeichert ' + fmtDateTime(Math.floor(Date.now() / 1000));
      toast('Notiz gespeichert', 'ok');
    } catch (e) {
      toast(e.message, 'danger');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function renderDockerForm() {
    const cfg = state.docker.config || {};
    const enabled = $('#docker-enabled'); if (enabled) enabled.checked = !!cfg.enabled;
    const host = $('#docker-host'); if (host) host.value = cfg.host || '';
    const port = $('#docker-port'); if (port) port.value = cfg.port || 22;
    const username = $('#docker-username'); if (username) username.value = cfg.username || '';
    const authType = $('#docker-auth-type'); if (authType) authType.value = cfg.auth_type || 'password';
    const pw = $('#docker-password'); if (pw) { pw.value = ''; pw.placeholder = cfg.has_password ? 'gesetzt – leer lassen zum Beibehalten' : ''; }
    const key = $('#docker-private-key'); if (key) { key.value = ''; key.placeholder = cfg.has_private_key ? 'gesetzt – leer lassen zum Beibehalten' : ''; }
    applyDockerFieldVisibility();
  }

  function applyDockerFieldVisibility() {
    const enabled = $('#docker-enabled');
    const fields = $('#docker-fields');
    if (fields) fields.hidden = !(enabled && enabled.checked);
    const keyAuth = $('#docker-auth-type') && $('#docker-auth-type').value === 'key';
    const pwRow = $('#docker-auth-password'); if (pwRow) pwRow.hidden = !!keyAuth;
    const keyRow = $('#docker-auth-key'); if (keyRow) keyRow.hidden = !keyAuth;
  }

  function dockerFormBody() {
    const body = {
      enabled: $('#docker-enabled').checked,
      host: $('#docker-host').value.trim(),
      port: parseInt($('#docker-port').value, 10) || 22,
      username: $('#docker-username').value.trim(),
      auth_type: $('#docker-auth-type').value,
    };
    if (body.auth_type === 'password' && $('#docker-password').value) body.password = $('#docker-password').value;
    if (body.auth_type === 'key' && $('#docker-private-key').value.trim()) body.private_key = $('#docker-private-key').value.trim();
    return body;
  }

  async function onDockerSave(ev) {
    if (ev) ev.preventDefault();
    const msg = $('#docker-msg');
    const statusMsg = $('#docker-status-msg');
    if (msg) { msg.hidden = true; msg.classList.remove('ok'); }
    if (statusMsg) statusMsg.hidden = true;
    const btn = ev && ev.target ? ev.target.querySelector('button[type="submit"]') : null;
    if (btn) btn.disabled = true;
    try {
      const res = await api('docker/config', { method: 'PUT', body: dockerFormBody() });
      state.docker.config = res;
      if (msg) { msg.textContent = 'Docker-Einstellungen gespeichert.'; msg.classList.add('ok'); msg.hidden = false; }
      if (statusMsg) {
        statusMsg.textContent = res.status === 'ok' ? 'Verbindung zum Docker-Host erfolgreich.' : 'Gespeichert, aber der Docker-Host ist nicht erreichbar.';
        statusMsg.hidden = false;
      }
      applyDockerConfig();
      renderDockerForm();
      if (res.enabled) switchMainTab('docker');
      toast('Docker-Einstellungen gespeichert', 'ok');
      return true;
    } catch (e) {
      if (msg) { msg.textContent = e.message; msg.hidden = false; }
      return false;
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function onDockerTest() {
    const statusMsg = $('#docker-status-msg');
    const btn = $('#btn-docker-test');
    if (btn) btn.disabled = true;
    if (statusMsg) { statusMsg.textContent = 'Einstellungen werden gespeichert und Verbindung getestet …'; statusMsg.hidden = false; }
    const saved = await onDockerSave(null);
    if (!saved) {
      if (btn) btn.disabled = false;
      return;
    }
    try {
      const { ok } = await api('docker/status');
      if (statusMsg) statusMsg.textContent = ok ? 'Docker-Host ist erreichbar.' : 'Docker-Host nicht erreichbar.';
    } catch (e) {
      if (statusMsg) statusMsg.textContent = 'Fehler: ' + e.message;
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  // ---------------------------------------------------------------------
  // Login / Logout
  // ---------------------------------------------------------------------
  async function onLogin(ev) {
    ev.preventDefault();
    const err = $('#login-error');
    const btn = $('#login-submit');
    err.hidden = true;
    btn.disabled = true;
    try {
      const res = await api('login', { method: 'POST', body: { username: $('#login-user').value.trim(), password: $('#login-pass').value } });
      state.csrf = res.csrf;
      state.user = res.user;
      showApp();
    } catch (e) {
      let text = e.message;
      if (e.payload && e.payload.retry_after) text += ` (${Math.ceil(e.payload.retry_after / 60)} min)`;
      err.textContent = text; err.hidden = false;
      $('#login-pass').value = '';
      $('#login-pass').focus();
    } finally {
      btn.disabled = false;
    }
  }

  async function onLogout() {
    try { await api('logout', { method: 'POST' }); } catch (_) { /* ignorieren */ }
    state.user = null;
    state.apiKey = null;
    hideApiKeyReveal();
    showLogin();
  }

  // ---------------------------------------------------------------------
  // Init
  // ---------------------------------------------------------------------
  function bind() {
    $('#login-form').addEventListener('submit', onLogin);
    $('#btn-logout').addEventListener('click', onLogout);
    $('#btn-refresh').addEventListener('click', refreshAll);
    $('#btn-settings').addEventListener('click', () => openSettings('services'));
    $('#btn-manage-services').addEventListener('click', () => openSettings('services'));
    $$('.range-picker button').forEach((b) => b.addEventListener('click', () => setRange(b.dataset.range)));
    $('#toggle-cores').checked = state.showCores;
    $('#toggle-cores').addEventListener('change', (e) => {
      state.showCores = e.target.checked;
      localStorage.setItem('qi.cores', state.showCores ? '1' : '0');
      renderCharts();
    });
    $$('#modal-settings [data-close]').forEach((el) => el.addEventListener('click', closeSettings));
    $$('#modal-settings .tab').forEach((t) => t.addEventListener('click', () => switchTab(t.dataset.tab)));
    $$('.main-tabs .tab').forEach((t) => t.addEventListener('click', () => switchMainTab(t.dataset.maintab)));
    $('#service-add-form').addEventListener('submit', onAddService);
    $('#password-form').addEventListener('submit', onChangePassword);
    $('#system-form').addEventListener('submit', onSystemSave);
    $('#btn-api-rotate').addEventListener('click', onRotateApiKey);
    $('#btn-api-revoke').addEventListener('click', onRevokeApiKey);
    $('#btn-api-copy').addEventListener('click', onCopyApiKey);

    // Docker
    const dockerForm = $('#docker-form');
    if (dockerForm) {
      dockerForm.addEventListener('submit', onDockerSave);
      const dockerEnabled = $('#docker-enabled');
      if (dockerEnabled) dockerEnabled.addEventListener('change', applyDockerFieldVisibility);
      const dockerAuthType = $('#docker-auth-type');
      if (dockerAuthType) dockerAuthType.addEventListener('change', applyDockerFieldVisibility);
    }
    const btnDockerTest = $('#btn-docker-test');
    if (btnDockerTest) btnDockerTest.addEventListener('click', onDockerTest);
    const btnDockerRefresh = $('#btn-docker-refresh');
    if (btnDockerRefresh) btnDockerRefresh.addEventListener('click', refreshDocker);
    const btnDockerStart = $('#btn-docker-start');
    if (btnDockerStart) btnDockerStart.addEventListener('click', () => dockerAction('start'));
    const btnDockerStop = $('#btn-docker-stop');
    if (btnDockerStop) btnDockerStop.addEventListener('click', () => dockerAction('stop'));
    const btnDockerRestart = $('#btn-docker-restart');
    if (btnDockerRestart) btnDockerRestart.addEventListener('click', () => dockerAction('restart'));
    const btnDockerLogs = $('#btn-docker-logs');
    if (btnDockerLogs) btnDockerLogs.addEventListener('click', dockerLoadLogs);
    const btnDockerNote = $('#btn-docker-note-save');
    if (btnDockerNote) btnDockerNote.addEventListener('click', dockerSaveNote);
    const dockerFolderAddForm = $('#docker-folder-add-form');
    if (dockerFolderAddForm) dockerFolderAddForm.addEventListener('submit', onAddDockerFolder);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !$('#modal-settings').classList.contains('hidden')) closeSettings();
      if (!$('#view-app').classList.contains('hidden') && !e.metaKey && !e.ctrlKey && !e.altKey) {
        const idx = ['1', '2', '3', '4', '5'].indexOf(e.key);
        if (idx >= 0 && !/INPUT|TEXTAREA/.test(document.activeElement.tagName)) setRange(RANGES[idx]);
      }
    });
  }

  async function boot() {
    bind();
    try {
      const s = await api('session');
      state.csrf = s.csrf;
      state.system = s.system || null;
      $('#version').textContent = s.version ? 'v' + s.version : '';
      if (s.authenticated) {
        state.user = s.user;
        showApp();
      } else {
        showLogin();
      }
    } catch (e) {
      showLogin();
      toast('API nicht erreichbar: ' + e.message, 'danger');
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
