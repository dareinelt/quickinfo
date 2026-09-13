/**
 * quickinfo – Minimaler, hochauflösender Liniengraph auf HTML5 Canvas (Retina / devicePixelRatio).
 * Keine Abhängigkeiten. Unterstützt mehrere Serien, Lückenerkennung, Flächenfüllung,
 * Hover-Tooltip mit Fadenkreuz und Legende mit ein-/ausblendbaren Serien.
 */
(function (global) {
  'use strict';

  const DAY_NAMES = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  const TIME_STEPS = [60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 259200, 604800];

  const pad2 = (n) => String(n).padStart(2, '0');
  const fmtTime = (d) => pad2(d.getHours()) + ':' + pad2(d.getMinutes());
  const fmtDate = (d) => pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) + '.';

  function niceStep(range, targetTicks) {
    const raw = range / Math.max(1, targetTicks);
    const mag = Math.pow(10, Math.floor(Math.log10(raw || 1)));
    const norm = raw / mag;
    let nice;
    if (norm <= 1) nice = 1; else if (norm <= 2) nice = 2; else if (norm <= 2.5) nice = 2.5; else if (norm <= 5) nice = 5; else nice = 10;
    return nice * mag;
  }

  class LineChart {
    /**
     * @param {HTMLCanvasElement} canvas
     * @param {object} opts { unit, min, max, yFormat, legendEl, tooltipEl, emptyText, softMax }
     */
    constructor(canvas, opts = {}) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.opts = Object.assign({
        unit: '', min: undefined, max: undefined, softMax: undefined, yFormat: null,
        legendEl: null, tooltipEl: null, emptyText: 'Keine Daten für diesen Zeitraum',
        padding: { top: 12, right: 14, bottom: 28, left: 46 },
      }, opts);
      this.data = { from: 0, to: 0, step: 60, series: [] };
      this.hidden = new Set();
      this.hover = null;
      this.width = 0;
      this.height = 0;
      this.dpr = 1;
      this.styles = this._readStyles();

      this._onMove = this._onMove.bind(this);
      this._onLeave = this._onLeave.bind(this);
      canvas.addEventListener('mousemove', this._onMove);
      canvas.addEventListener('touchstart', this._onMove, { passive: true });
      canvas.addEventListener('touchmove', this._onMove, { passive: true });
      canvas.addEventListener('mouseleave', this._onLeave);
      canvas.addEventListener('touchend', this._onLeave);

      this.ro = new ResizeObserver(() => this.resize());
      this.ro.observe(canvas.parentElement || canvas);
      this.resize();
    }

    _readStyles() {
      const cs = getComputedStyle(document.documentElement);
      const v = (name, fallback) => (cs.getPropertyValue(name) || fallback).trim();
      return {
        grid: 'rgba(255,255,255,0.06)',
        axis: 'rgba(255,255,255,0.12)',
        text: v('--text-muted', '#8a97ad'),
        textDim: v('--text-dim', '#5d6a80'),
        crosshair: 'rgba(255,255,255,0.25)',
        font: '11px ' + v('--font', 'system-ui, sans-serif'),
      };
    }

    destroy() {
      this.ro.disconnect();
      this.canvas.removeEventListener('mousemove', this._onMove);
      this.canvas.removeEventListener('touchstart', this._onMove);
      this.canvas.removeEventListener('touchmove', this._onMove);
      this.canvas.removeEventListener('mouseleave', this._onLeave);
      this.canvas.removeEventListener('touchend', this._onLeave);
    }

    resize() {
      const rect = (this.canvas.parentElement || this.canvas).getBoundingClientRect();
      const cs = getComputedStyle(this.canvas.parentElement || this.canvas);
      const w = Math.max(50, rect.width - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0));
      const h = Math.max(50, rect.height - parseFloat(cs.paddingTop || 0) - parseFloat(cs.paddingBottom || 0));
      const dpr = Math.max(1, Math.min(4, window.devicePixelRatio || 1));
      if (w === this.width && h === this.height && dpr === this.dpr) return;
      this.width = w; this.height = h; this.dpr = dpr;
      this.canvas.width = Math.round(w * dpr);
      this.canvas.height = Math.round(h * dpr);
      this.canvas.style.width = w + 'px';
      this.canvas.style.height = h + 'px';
      this.draw();
    }

    /**
     * @param {{from:number,to:number,step:number,series:Array<{key:string,label:string,color:string,points:Array<[number,number]>,width?:number,fill?:boolean,dash?:number[]}>}} data
     */
    setData(data) {
      this.data = data;
      for (const s of this.data.series) {
        s._map = new Map(s.points);
      }
      this._renderLegend();
      this.draw();
    }

    toggleSeries(key) {
      if (this.hidden.has(key)) this.hidden.delete(key); else this.hidden.add(key);
      this._renderLegend();
      this.draw();
    }

    _visible() {
      return this.data.series.filter((s) => !this.hidden.has(s.key));
    }

    _renderLegend() {
      const el = this.opts.legendEl;
      if (!el) return;
      el.textContent = '';
      for (const s of this.data.series) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = this.hidden.has(s.key) ? 'off' : '';
        const sw = document.createElement('i');
        sw.style.background = s.color;
        b.appendChild(sw);
        b.appendChild(document.createTextNode(s.label));
        b.addEventListener('click', () => this.toggleSeries(s.key));
        el.appendChild(b);
      }
    }

    _yRange() {
      const { min, max, softMax } = this.opts;
      let lo = Infinity, hi = -Infinity;
      for (const s of this._visible()) {
        for (const [, v] of s.points) {
          if (v == null) continue;
          if (v < lo) lo = v;
          if (v > hi) hi = v;
        }
      }
      if (!isFinite(lo)) { lo = 0; hi = 1; }
      if (min !== undefined) lo = min;
      if (max !== undefined) hi = max;
      if (softMax !== undefined && hi < softMax) hi = softMax;
      if (hi === lo) hi = lo + 1;
      if (min === undefined || max === undefined) {
        const step = niceStep(hi - lo, 4);
        if (min === undefined) lo = Math.floor(lo / step) * step;
        if (max === undefined) hi = Math.ceil(hi / step) * step;
        if (hi === lo) hi = lo + step;
      }
      return { lo, hi, step: niceStep(hi - lo, 4) };
    }

    _timeTicks(from, to, plotW) {
      const maxTicks = Math.max(3, Math.floor(plotW / 84));
      const range = to - from;
      let interval = TIME_STEPS[TIME_STEPS.length - 1];
      for (const s of TIME_STEPS) {
        if (range / s <= maxTicks) { interval = s; break; }
      }
      const ticks = [];
      if (interval >= 86400) {
        // an lokalen Mitternachten ausrichten
        const d = new Date(from * 1000);
        d.setHours(0, 0, 0, 0);
        const days = interval / 86400;
        for (let t = d.getTime() / 1000; t <= to; t += 86400 * days) {
          if (t >= from) ticks.push(t);
        }
      } else {
        const offset = new Date(from * 1000).getTimezoneOffset() * 60;
        let t = Math.ceil((from - offset) / interval) * interval + offset;
        for (; t <= to; t += interval) ticks.push(t);
      }
      return { ticks, interval, showDate: range > 86400 };
    }

    _fmtY(v) {
      if (this.opts.yFormat) return this.opts.yFormat(v);
      const abs = Math.abs(v);
      const s = abs >= 100 ? v.toFixed(0) : abs >= 10 ? v.toFixed(1).replace(/\.0$/, '') : v.toFixed(1).replace(/\.0$/, '');
      return s + this.opts.unit;
    }

    draw() {
      const ctx = this.ctx;
      const { width: W, height: H, dpr } = this;
      const P = this.opts.padding;
      const st = this.styles;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);

      const plotX = P.left, plotY = P.top;
      const plotW = Math.max(10, W - P.left - P.right);
      const plotH = Math.max(10, H - P.top - P.bottom);
      const { from, to, step } = this.data;
      const series = this._visible();
      const hasData = series.some((s) => s.points.length > 0);

      const { lo, hi, step: yStep } = this._yRange();
      const xOf = (t) => plotX + ((t - from) / Math.max(1, to - from)) * plotW;
      const yOf = (v) => plotY + plotH - ((v - lo) / (hi - lo)) * plotH;

      ctx.font = st.font;
      ctx.textBaseline = 'middle';

      // Y-Grid & Labels
      ctx.lineWidth = 1;
      for (let v = lo; v <= hi + yStep * 0.001; v += yStep) {
        const y = Math.round(yOf(v)) + 0.5;
        ctx.strokeStyle = st.grid;
        ctx.beginPath(); ctx.moveTo(plotX, y); ctx.lineTo(plotX + plotW, y); ctx.stroke();
        ctx.fillStyle = st.text;
        ctx.textAlign = 'right';
        ctx.fillText(this._fmtY(v), plotX - 8, y);
      }

      // X-Ticks
      if (to > from) {
        const { ticks, interval, showDate } = this._timeTicks(from, to, plotW);
        ctx.textAlign = 'center';
        for (const t of ticks) {
          const x = Math.round(xOf(t)) + 0.5;
          ctx.strokeStyle = st.grid;
          ctx.beginPath(); ctx.moveTo(x, plotY); ctx.lineTo(x, plotY + plotH); ctx.stroke();
          const d = new Date(t * 1000);
          const isMidnight = d.getHours() === 0 && d.getMinutes() === 0;
          let label;
          if (interval >= 86400) label = DAY_NAMES[d.getDay()] + ' ' + fmtDate(d);
          else if (showDate && isMidnight) label = DAY_NAMES[d.getDay()] + ' ' + fmtDate(d);
          else label = fmtTime(d);
          ctx.fillStyle = (showDate && isMidnight) ? st.text : st.text;
          ctx.fillText(label, x, plotY + plotH + 14);
        }
      }

      // Achse
      ctx.strokeStyle = st.axis;
      ctx.beginPath();
      ctx.moveTo(plotX + 0.5, plotY); ctx.lineTo(plotX + 0.5, plotY + plotH + 0.5); ctx.lineTo(plotX + plotW, plotY + plotH + 0.5);
      ctx.stroke();

      if (!hasData) {
        ctx.fillStyle = st.textDim;
        ctx.textAlign = 'center';
        ctx.font = '13px ' + st.font.split(' ').slice(1).join(' ');
        ctx.fillText(this.opts.emptyText, plotX + plotW / 2, plotY + plotH / 2);
        this._hideTooltip();
        return;
      }

      // Serien
      ctx.save();
      ctx.beginPath(); ctx.rect(plotX, plotY - 1, plotW, plotH + 2); ctx.clip();
      const gap = Math.max(step * 2.5, 90);
      for (const s of series) {
        if (!s.points.length) continue;
        ctx.lineWidth = s.width || 1.5;
        ctx.strokeStyle = s.color;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.setLineDash(s.dash || []);

        // Segmente mit Lückenerkennung
        const segments = [];
        let cur = [];
        let prevT = null;
        for (const [t, v] of s.points) {
          if (v == null) { if (cur.length) segments.push(cur); cur = []; prevT = null; continue; }
          if (prevT !== null && t - prevT > gap) { segments.push(cur); cur = []; }
          cur.push([t, v]);
          prevT = t;
        }
        if (cur.length) segments.push(cur);

        for (const seg of segments) {
          if (seg.length === 1) {
            ctx.fillStyle = s.color;
            ctx.beginPath(); ctx.arc(xOf(seg[0][0]), yOf(seg[0][1]), ctx.lineWidth, 0, Math.PI * 2); ctx.fill();
            continue;
          }
          ctx.beginPath();
          seg.forEach(([t, v], i) => { const x = xOf(t), y = yOf(v); i ? ctx.lineTo(x, y) : ctx.moveTo(x, y); });
          ctx.stroke();

          if (s.fill) {
            const grad = ctx.createLinearGradient(0, plotY, 0, plotY + plotH);
            grad.addColorStop(0, this._alpha(s.color, 0.28));
            grad.addColorStop(1, this._alpha(s.color, 0.0));
            ctx.lineTo(xOf(seg[seg.length - 1][0]), plotY + plotH);
            ctx.lineTo(xOf(seg[0][0]), plotY + plotH);
            ctx.closePath();
            ctx.fillStyle = grad;
            ctx.fill();
          }
        }
        ctx.setLineDash([]);
      }
      ctx.restore();

      // Hover
      if (this.hover !== null) {
        const t = this.hover;
        const x = Math.round(xOf(t)) + 0.5;
        ctx.strokeStyle = st.crosshair;
        ctx.setLineDash([3, 3]);
        ctx.beginPath(); ctx.moveTo(x, plotY); ctx.lineTo(x, plotY + plotH); ctx.stroke();
        ctx.setLineDash([]);
        for (const s of series) {
          const v = s._map ? s._map.get(t) : undefined;
          if (v == null) continue;
          ctx.fillStyle = s.color;
          ctx.strokeStyle = '#0b0f17';
          ctx.lineWidth = 2;
          ctx.beginPath(); ctx.arc(x, yOf(v), 3.5, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
        }
        this._showTooltip(t, x, series);
      } else {
        this._hideTooltip();
      }
    }

    _alpha(color, a) {
      if (color.startsWith('#')) {
        const hex = color.length === 4 ? color.slice(1).split('').map((c) => c + c).join('') : color.slice(1);
        const n = parseInt(hex, 16);
        return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
      }
      const m = color.match(/^hsla?\(([^)]+)\)$/);
      if (m) return `hsla(${m[1].split(',').slice(0, 3).join(',')},${a})`;
      const r = color.match(/^rgba?\(([^)]+)\)$/);
      if (r) return `rgba(${r[1].split(',').slice(0, 3).join(',')},${a})`;
      return color;
    }

    _onMove(ev) {
      const { from, to, step } = this.data;
      if (!to || to <= from) return;
      const rect = this.canvas.getBoundingClientRect();
      const clientX = ev.touches ? ev.touches[0].clientX : ev.clientX;
      const x = clientX - rect.left;
      const P = this.opts.padding;
      const plotW = this.width - P.left - P.right;
      const frac = Math.min(1, Math.max(0, (x - P.left) / plotW));
      const t = Math.round((from + frac * (to - from)) / step) * step;
      // nächsten tatsächlich vorhandenen Zeitstempel suchen
      let best = null, bestD = Infinity;
      for (const s of this._visible()) {
        for (const [pt] of s.points) {
          const d = Math.abs(pt - t);
          if (d < bestD) { bestD = d; best = pt; }
          if (pt > t + step * 2) break;
        }
      }
      if (best === null || bestD > step * 3) { this.hover = null; this.draw(); return; }
      if (best === this.hover) return;
      this.hover = best;
      this.draw();
    }

    _onLeave() {
      if (this.hover === null) return;
      this.hover = null;
      this.draw();
    }

    _showTooltip(t, x, series) {
      const el = this.opts.tooltipEl;
      if (!el) return;
      const d = new Date(t * 1000);
      const rows = [];
      for (const s of series) {
        const v = s._map ? s._map.get(t) : undefined;
        if (v == null) continue;
        rows.push({ label: s.label, color: s.color, v });
      }
      if (!rows.length) { this._hideTooltip(); return; }
      const showDate = (this.data.to - this.data.from) > 86400;
      const timeLabel = (showDate ? DAY_NAMES[d.getDay()] + ' ' + fmtDate(d) + ' ' : '') + fmtTime(d);
      el.textContent = '';
      const head = document.createElement('div');
      head.className = 'tt-time';
      head.textContent = timeLabel;
      el.appendChild(head);
      const maxRows = 12;
      rows.slice(0, maxRows).forEach((r) => {
        const row = document.createElement('div');
        row.className = 'tt-row';
        const left = document.createElement('span');
        const sw = document.createElement('i');
        sw.className = 'tt-swatch';
        sw.style.background = r.color;
        left.appendChild(sw);
        left.appendChild(document.createTextNode(r.label));
        const right = document.createElement('b');
        right.textContent = this._fmtY(r.v);
        row.appendChild(left);
        row.appendChild(right);
        el.appendChild(row);
      });
      if (rows.length > maxRows) {
        const more = document.createElement('div');
        more.className = 'tt-row';
        more.style.color = this.styles.textDim;
        more.textContent = '+ ' + (rows.length - maxRows) + ' weitere';
        el.appendChild(more);
      }
      el.style.display = 'block';
      const wrap = el.parentElement;
      const wrapW = wrap ? wrap.clientWidth : this.width;
      const ttW = el.offsetWidth;
      const canvasLeft = this.canvas.offsetLeft;
      let left = canvasLeft + x + 14;
      if (left + ttW > wrapW - 6) left = canvasLeft + x - ttW - 14;
      el.style.left = Math.max(0, left) + 'px';
      el.style.top = (this.canvas.offsetTop + 8) + 'px';
    }

    _hideTooltip() {
      if (this.opts.tooltipEl) this.opts.tooltipEl.style.display = 'none';
    }
  }

  /** Erzeugt eine gut unterscheidbare Farbe für Index i von n. */
  LineChart.paletteColor = function (i, n, sat = 70, light = 62) {
    const hue = Math.round((i * 360) / Math.max(1, n) + 210) % 360;
    return `hsl(${hue}, ${sat}%, ${light}%)`;
  };

  global.LineChart = LineChart;
})(window);
