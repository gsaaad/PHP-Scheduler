/* Robot Fleet Scheduler — dashboard.
 *
 * Three views over one access-scoped fleet:
 *   fleet    — card grid, paginated, with ping and hover media
 *   calendar — conventional month grid, click a day to book
 *   gantt    — robot rows against time, with the duty budget drawn per robot
 *
 * Every fetch goes through api(), which drops to the login view on 401.
 * All DOM is built with createElement/textContent: robot names are
 * attacker-controlled via POST /api/robots, so innerHTML would be an XSS sink.
 */

const STATUSES = ['idle', 'busy', 'maintenance', 'error', 'charging'];
const PAGE_SIZE = 24;
const HOUR_START = 6;          // Gantt day window
const HOUR_END = 22;

/* If a supplied map image is slightly off-layout, nudge the overlay here
   rather than regenerating the picture. Values are percentages. */
const MAP_CALIBRATION = { xOffset: 0, yOffset: 0, xScale: 1, yScale: 1 };

/* The frame every lat/lng in the database was generated against -- see
   sql/migrations/008_map_alignment_v2.sql. A straight 0-100% mapping of the
   artwork's own axes, kept in lockstep with the SQL side rather than
   re-derived from site positions on this side. */
const MAP_FRAME = { lngMin: -74.0360, lngSpan: 0.06, latMax: 40.7328, latSpan: 0.04 };

let ME = null;
let page = 0;
let calendarMonth = null;      // Date anchored to the 1st
let ganttDay = null;           // Date
let tasksCache = [];

const el = id => document.getElementById(id);
const clear = n => { while (n.firstChild) n.removeChild(n.firstChild); };

function node(tag, className, text) {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text !== undefined) n.textContent = text;
  return n;
}

async function api(path, options = {}) {
  const res = await fetch(path, { credentials: 'same-origin', ...options });
  if (res.status === 401) { showLogin(); throw new Error('Not signed in'); }
  return res;
}

const showLogin = () => { el('login-view').classList.remove('hidden'); el('app-view').classList.add('hidden'); };
const showApp = () => { el('login-view').classList.add('hidden'); el('app-view').classList.remove('hidden'); };

const pad = n => String(n).padStart(2, '0');
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const sqlTime = d => `${ymd(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;

function humanMinutes(m) {
  if (m < 60) return `${m} min`;
  const h = Math.floor(m / 60), r = m % 60;
  return r === 0 ? `${h}h` : `${h}h ${r}m`;
}

/* ------------------------------------------------------------------ auth */

async function login() {
  const err = el('login-error'); err.textContent = '';
  try {
    const res = await fetch('/api/auth/login', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: el('username').value, password: el('password').value })
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) { err.textContent = body.error ?? `Sign-in failed (HTTP ${res.status})`; return; }
    el('password').value = '';
    await start();
  } catch (e) { err.textContent = `Sign-in failed: ${e.message}`; }
}

async function logout() {
  stopMapAnimation();
  await fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' });
  ME = null; showLogin();
}

/* ------------------------------------------------------------------ tabs */

function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => {
    const on = t.dataset.tab === name;
    t.classList.toggle('active', on);
    t.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  document.querySelectorAll('.panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== name));

  if (name === 'calendar' && !calendarMonth) { calendarMonth = firstOfMonth(new Date()); loadCalendar(); }
  if (name === 'gantt' && !ganttDay) { ganttDay = new Date(); loadGantt(); }
  if (name === 'map') loadMap(); else stopMapAnimation();
}

const firstOfMonth = d => new Date(d.getFullYear(), d.getMonth(), 1);

/* ----------------------------------------------------------------- scope */

function renderScope(me) {
  const box = el('scope-box'); clear(box);
  const head = node('div');
  head.appendChild(node('strong', null, `${me.access.accessible_robots} robot(s)`));
  head.append(' are within your access rules.');
  box.appendChild(head);

  if (!me.access.rules.length) {
    box.appendChild(node('div', 'muted', 'No access rules are configured for your department, so no robots are reachable.'));
    return;
  }
  me.access.rules.forEach(rule => {
    const r = node('div', 'rule');
    r.appendChild(node('span', 'rule-name', rule.rule));
    if (rule.criteria?.length) {
      r.appendChild(node('span', 'conj', ' — all of: '));
      rule.criteria.forEach(c => r.appendChild(node('span', 'crit', `${c.kind} = ${c.value}`)));
    } else {
      r.appendChild(node('span', 'conj', ' — unrestricted'));
    }
    box.appendChild(r);
  });
  box.appendChild(node('div', 'conj', 'A robot is reachable if ANY rule above matches.'));
}

async function loadStats() {
  const s = (await (await api('/api/summary')).json()).data;
  const box = el('stats'); clear(box);
  const tile = (n, k) => {
    const t = node('div', 'stat');
    t.appendChild(node('div', 'n', String(n)));
    t.appendChild(node('div', 'k', k));
    return t;
  };
  box.append(
    tile(s.total_robots, 'Accessible'),
    tile(s.by_status.idle ?? 0, 'Idle'),
    tile(s.by_status.busy ?? 0, 'Busy'),
    tile(s.by_status.charging ?? 0, 'Charging'),
    tile((s.by_status.maintenance ?? 0) + (s.by_status.error ?? 0), 'Out of service'),
    tile(s.upcoming_bookings, 'Upcoming')
  );
}

/* ------------------------------------------------------------ tab: fleet */

const batteryColor = p => p >= 60 ? 'var(--ok)' : p >= 30 ? 'var(--warn)' : 'var(--danger)';

function dutyBar(r) {
  const endurance = Number(r.max_duty_minutes ?? 0);
  const used = Number(r.duty_minutes_used ?? 0);
  const reserve = Number(r.return_reserve_minutes ?? 0);
  if (!endurance) return null;

  const wrap = node('div', 'duty');
  const lbl = node('div', 'batt-label');
  lbl.appendChild(node('span', null, `Duty · ${r.duty_class ?? 'standard'}`));
  lbl.appendChild(node('span', null, `${humanMinutes(Math.max(0, endurance - reserve - used))} bookable`));
  wrap.appendChild(lbl);

  // Three segments: consumed, still bookable, and the reserved return trip.
  const track = node('div', 'duty-track');
  const seg = (pct, cls, title) => {
    const s = node('span', `duty-seg ${cls}`);
    s.style.width = `${pct}%`;
    s.title = title;
    return s;
  };
  const usedPct = Math.min(100, used / endurance * 100);
  const reservePct = Math.min(100 - usedPct, reserve / endurance * 100);
  const freePct = Math.max(0, 100 - usedPct - reservePct);

  track.append(
    seg(usedPct, 'duty-used', `${humanMinutes(used)} used`),
    seg(freePct, 'duty-free', `${humanMinutes(Math.max(0, endurance - reserve - used))} bookable`),
    seg(reservePct, 'duty-reserve', `${humanMinutes(reserve)} reserved for the return trip to a dock`)
  );
  wrap.appendChild(track);
  return wrap;
}

function renderRobot(r) {
  const card = node('div', 'robot');

  // Media: still image swapped for the hover animation on pointer-over.
  const media = node('div', 'robot-media');
  const img = document.createElement('img');
  img.alt = '';
  img.loading = 'lazy';
  img.src = `/api/robots/${r.id}/media/image`;
  // No image uploaded yet -> fall back to a type badge rather than a broken icon.
  img.onerror = () => {
    media.classList.add('no-media');
    media.textContent = (r.type ?? '?').slice(0, 2).toUpperCase();
  };
  media.appendChild(img);

  let hoverLoaded = false;
  media.addEventListener('pointerenter', () => {
    if (hoverLoaded || media.classList.contains('no-media')) return;
    hoverLoaded = true;
    const hover = new Image();
    hover.onload = () => { img.dataset.still = img.src; img.src = hover.src; };
    hover.src = `/api/robots/${r.id}/media/hover`;
  });
  media.addEventListener('pointerleave', () => {
    if (img.dataset.still) img.src = img.dataset.still;
  });
  card.appendChild(media);

  const body = node('div', 'robot-body');
  const top = node('div', 'robot-top');
  const left = node('div');
  left.appendChild(node('div', 'robot-name', r.name ?? 'Unknown'));
  left.appendChild(node('div', 'robot-meta', `${r.type ?? 'unknown'} · id ${r.id}`));
  top.appendChild(left);

  const st = String(r.status ?? '').toLowerCase();
  top.appendChild(node('span', `pill s-${STATUSES.includes(st) ? st : 'unknown'}`, st || 'unknown'));
  body.appendChild(top);

  const pct = Number(r.battery_level ?? 0);
  const batt = node('div', 'batt');
  const bl = node('div', 'batt-label');
  bl.appendChild(node('span', null, 'Battery'));
  bl.appendChild(node('span', null, `${pct}%`));
  batt.appendChild(bl);
  const track = node('div', 'batt-track');
  const fill = node('div', 'batt-fill');
  fill.style.width = `${Math.max(0, Math.min(100, pct))}%`;
  fill.style.background = batteryColor(pct);
  track.appendChild(fill);
  batt.appendChild(track);
  body.appendChild(batt);

  const duty = dutyBar(r);
  if (duty) body.appendChild(duty);

  const actions = node('div', 'robot-actions');
  const pingBtn = node('button', 'ghost tiny', 'Ping');
  pingBtn.onclick = () => pingRobot(r.id, reply);
  actions.appendChild(pingBtn);
  body.appendChild(actions);

  const reply = node('div', 'ping-reply hidden');
  body.appendChild(reply);

  card.appendChild(body);
  return card;
}

async function pingRobot(id, target) {
  target.classList.remove('hidden');
  target.textContent = 'Pinging…';
  try {
    const d = (await (await api(`/api/robots/${id}/ping`, { method: 'POST' })).json()).data;
    clear(target);
    target.appendChild(node('div', 'ping-msg', d.message));
    const t = d.telemetry;
    const where = t.in_transit
      ? `in transit · ${t.distance_m} m from ${t.nearest_site}`
      : `at ${t.location}`;
    target.appendChild(node('div', 'ping-meta', `${where} · ${humanMinutes(t.duty_minutes_left)} bookable`));
  } catch (e) {
    target.textContent = `Ping failed: ${e.message}`;
  }
}

function renderPager(total, shown) {
  const box = el('pager'); clear(box);
  const pages = Math.ceil(total / PAGE_SIZE);
  if (pages <= 1) return;

  const prev = node('button', 'ghost', '← Previous');
  prev.disabled = page === 0;
  prev.onclick = () => { page--; loadRobots(); };

  const next = node('button', 'ghost', 'Next →');
  next.disabled = page >= pages - 1;
  next.onclick = () => { page++; loadRobots(); };

  box.append(prev, node('span', 'muted',
    `Page ${page + 1} of ${pages} · ${page * PAGE_SIZE + 1}–${page * PAGE_SIZE + shown} of ${total}`), next);
}

async function loadRobots(resetPage = false) {
  if (resetPage) page = 0;
  const grid = el('robot-grid');
  const params = new URLSearchParams({ limit: String(PAGE_SIZE), offset: String(page * PAGE_SIZE) });
  for (const [id, key] of [['f-arena', 'arena_id'], ['f-status', 'status'], ['f-type', 'type']]) {
    const v = el(id).value; if (v) params.set(key, v);
  }
  try {
    const payload = await (await api(`/api/robots?${params}`)).json();
    const robots = payload.data ?? [];
    const total = payload.meta.total;

    if (robots.length === 0 && total > 0 && page > 0) { page = 0; return loadRobots(); }

    clear(grid);
    if (!robots.length) {
      grid.appendChild(node('div', 'muted', 'No robots match. Either your access rules exclude them, or the filters are too narrow.'));
    } else {
      robots.forEach(r => grid.appendChild(renderRobot(r)));
    }
    el('list-meta').textContent = `${total} accessible · scope: ${payload.meta.scope}`;
    renderPager(total, robots.length);
  } catch (e) {
    clear(grid); grid.appendChild(node('div', 'err', `Could not load robots: ${e.message}`));
  }
}

async function loadArenas() {
  const sel = el('f-arena');
  while (sel.options.length > 1) sel.remove(1);
  ((await (await api('/api/arenas')).json()).data ?? []).forEach(a => {
    const o = document.createElement('option');
    o.value = a.id; o.textContent = `${a.name} (${a.robot_count})`;
    sel.appendChild(o);
  });
}

async function loadTasks() {
  tasksCache = (await (await api('/api/tasks?limit=100')).json()).data ?? [];
  [el('task-select'), el('book-task')].forEach(sel => {
    if (!sel) return;
    clear(sel);
    tasksCache.forEach(t => {
      const o = document.createElement('option');
      o.value = t.id;
      o.textContent = `${t.title} · ${t.estimated_duration} min · ${t.min_battery_level}% battery`;
      sel.appendChild(o);
    });
  });
}

/* ----------------------------------------------------------- eligibility */

async function checkEligible() {
  const out = el('elig-out'); clear(out);
  const taskId = el('task-select').value;
  if (!taskId) { out.appendChild(node('div', 'muted', 'No tasks available.')); return; }

  out.appendChild(node('div', 'muted', 'Checking…'));
  try {
    const payload = await (await api(`/api/tasks/${encodeURIComponent(taskId)}/eligible-robots?limit=200`)).json();
    const robots = payload.data ?? [];
    const t = payload.meta.task;
    clear(out);
    out.appendChild(node('div', `banner ${robots.length ? 'ok' : 'bad'}`,
      robots.length
        ? `${robots.length} robot(s) can take "${t.title}".`
        : `No robot you can access is currently eligible for "${t.title}".`));

    robots.slice(0, 25).forEach(r => {
      const row = node('div', 'elig-row');
      const l = node('div');
      l.appendChild(node('strong', null, r.name));
      l.append(` · ${r.type} · id ${r.id}`);
      row.appendChild(l);
      const right = node('div');
      right.appendChild(node('span', `pill s-${STATUSES.includes(r.status) ? r.status : 'unknown'}`, r.status));
      right.append(` ${r.battery_level}%`);
      row.appendChild(right);
      out.appendChild(row);
    });
  } catch (e) {
    clear(out); out.appendChild(node('div', 'err', `Could not check eligibility: ${e.message}`));
  }
}

/* --------------------------------------------------------- tab: calendar */

async function loadCalendar() {
  const grid = el('cal-grid');
  clear(grid);

  const first = calendarMonth;
  const year = first.getFullYear(), month = first.getMonth();
  const last = new Date(year, month + 1, 0);

  el('cal-label').textContent = first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

  const from = `${ymd(first)} 00:00:00`;
  const to = `${ymd(last)} 23:59:59`;

  let byDay = new Map();
  try {
    const payload = await (await api(`/api/schedules/window?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)).json();
    (payload.data ?? []).forEach(s => {
      const key = String(s.start_time).slice(0, 10);
      if (!byDay.has(key)) byDay.set(key, []);
      byDay.get(key).push(s);
    });
  } catch (e) {
    grid.appendChild(node('div', 'err', `Could not load the calendar: ${e.message}`));
    return;
  }

  ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach(d =>
    grid.appendChild(node('div', 'cal-head', d)));

  // Monday-first offset
  const lead = (first.getDay() + 6) % 7;
  for (let i = 0; i < lead; i++) grid.appendChild(node('div', 'cal-cell empty'));

  const todayKey = ymd(new Date());

  for (let day = 1; day <= last.getDate(); day++) {
    const date = new Date(year, month, day);
    const key = ymd(date);
    const cell = node('div', 'cal-cell');
    if (key === todayKey) cell.classList.add('today');
    cell.tabIndex = 0;
    cell.setAttribute('role', 'button');
    cell.setAttribute('aria-label', `Book a robot on ${key}`);

    cell.appendChild(node('div', 'cal-day', String(day)));

    const bookings = byDay.get(key) ?? [];
    const list = node('div', 'cal-events');
    bookings.slice(0, 3).forEach(s => {
      const ev = node('div', 'cal-event');
      ev.title = `${s.robot_name} · ${s.task_title} · ${String(s.start_time).slice(11, 16)}–${String(s.end_time).slice(11, 16)}`;
      ev.textContent = `${String(s.start_time).slice(11, 16)} ${s.task_title}`;
      list.appendChild(ev);
    });
    if (bookings.length > 3) list.appendChild(node('div', 'cal-more', `+${bookings.length - 3} more`));
    cell.appendChild(list);

    const open = () => openBooking(key);
    cell.onclick = open;
    cell.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } };

    grid.appendChild(cell);
  }
}

function shiftMonth(delta) {
  calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + delta, 1);
  loadCalendar();
}

/* ------------------------------------------------------------- booking */

let bookingDate = null;

async function openBooking(dateKey) {
  bookingDate = dateKey;
  el('book-date').textContent = dateKey;
  el('book-time').value = '09:00';
  el('book-result').textContent = '';
  clear(el('book-robot'));
  el('booking').classList.remove('hidden');
  await refreshBookingCandidates();
}

function closeBooking() { el('booking').classList.add('hidden'); }

/* Only robots that pass every gate for this task and this window are offered,
   so the dialog cannot present a choice the scheduler will refuse. */
async function refreshBookingCandidates() {
  const sel = el('book-robot');
  clear(sel);
  const taskId = el('book-task').value;
  if (!taskId || !bookingDate) return;

  const start = `${bookingDate} ${el('book-time').value}:00`;
  sel.appendChild(node('option', null, 'Loading…'));

  try {
    const payload = await (await api(
      `/api/tasks/${encodeURIComponent(taskId)}/eligible-robots?limit=100&start_time=${encodeURIComponent(start)}`
    )).json();
    const robots = payload.data ?? [];
    clear(sel);

    if (!robots.length) {
      const o = document.createElement('option');
      o.value = '';
      o.textContent = 'No eligible robot for this task and time';
      sel.appendChild(o);
      el('book-submit').disabled = true;
      return;
    }
    el('book-submit').disabled = false;
    robots.forEach(r => {
      const o = document.createElement('option');
      o.value = r.id;
      o.textContent = `${r.name} · ${r.status} · ${r.battery_level}% · ${humanMinutes(
        Math.max(0, r.max_duty_minutes - r.return_reserve_minutes - r.duty_minutes_used))} bookable`;
      sel.appendChild(o);
    });
  } catch (e) {
    clear(sel);
    sel.appendChild(node('option', null, `Could not load: ${e.message}`));
  }
}

async function submitBooking() {
  const out = el('book-result');
  out.className = 'book-result';
  out.textContent = 'Booking…';

  const body = {
    robot_id: Number(el('book-robot').value),
    task_id: Number(el('book-task').value),
    start_time: `${bookingDate} ${el('book-time').value}:00`
  };

  try {
    const res = await api('/api/schedules', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const payload = await res.json();
    if (!res.ok) {
      out.className = 'book-result bad';
      out.textContent = payload.error ?? `Failed (HTTP ${res.status})`;
      return;
    }
    const d = payload.data;
    out.className = 'book-result ok';
    clear(out);
    out.appendChild(node('div', null,
      `Booked ${String(d.start_time).slice(0, 16)} → ${String(d.end_time).slice(11, 16)}.`));
    if (d.duty) {
      out.appendChild(node('div', 'muted',
        `${humanMinutes(d.duty.schedulable_remaining)} left for the next department. ${d.duty.note}`));
    }
    await Promise.all([loadCalendar(), loadStats()]);
    if (ganttDay) loadGantt();
  } catch (e) {
    out.className = 'book-result bad';
    out.textContent = `Failed: ${e.message}`;
  }
}

/* ------------------------------------------------------------ tab: gantt */

async function loadGantt() {
  const host = el('gantt');
  clear(host);
  el('gantt-label').textContent = ganttDay.toLocaleDateString(undefined,
    { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

  const from = `${ymd(ganttDay)} ${pad(HOUR_START)}:00:00`;
  const to = `${ymd(ganttDay)} ${pad(HOUR_END)}:00:00`;

  let payload;
  try {
    payload = await (await api(
      `/api/schedules/window?view=gantt&limit=40&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)).json();
  } catch (e) {
    host.appendChild(node('div', 'err', `Could not load the chart: ${e.message}`));
    return;
  }

  const robots = payload.robots ?? [];
  const byRobot = new Map();
  (payload.data ?? []).forEach(s => {
    if (!byRobot.has(s.robot_id)) byRobot.set(s.robot_id, []);
    byRobot.get(s.robot_id).push(s);
  });

  if (!robots.length) {
    host.appendChild(node('div', 'muted', 'No robots in scope.'));
    return;
  }

  const totalMin = (HOUR_END - HOUR_START) * 60;
  const pctOf = date => {
    const d = new Date(String(date).replace(' ', 'T'));
    const mins = (d.getHours() - HOUR_START) * 60 + d.getMinutes();
    return Math.max(0, Math.min(100, mins / totalMin * 100));
  };

  // Hour ruler
  const ruler = node('div', 'g-row g-ruler');
  ruler.appendChild(node('div', 'g-label', ''));
  const rlane = node('div', 'g-lane');
  for (let h = HOUR_START; h < HOUR_END; h++) {
    const tick = node('div', 'g-tick', `${pad(h)}`);
    tick.style.left = `${(h - HOUR_START) / (HOUR_END - HOUR_START) * 100}%`;
    rlane.appendChild(tick);
  }
  ruler.appendChild(rlane);
  host.appendChild(ruler);

  robots.forEach(r => {
    const row = node('div', 'g-row');

    const label = node('div', 'g-label');
    label.appendChild(node('div', 'g-name', r.name));
    const bookable = Math.max(0, r.max_duty_minutes - r.return_reserve_minutes - r.duty_minutes_used);
    label.appendChild(node('div', 'g-sub', `${r.duty_class} · ${humanMinutes(bookable)} bookable`));
    row.appendChild(label);

    const lane = node('div', 'g-lane');
    for (let h = HOUR_START + 1; h < HOUR_END; h++) {
      const g = node('div', 'g-grid');
      g.style.left = `${(h - HOUR_START) / (HOUR_END - HOUR_START) * 100}%`;
      lane.appendChild(g);
    }

    (byRobot.get(r.id) ?? []).forEach(s => {
      const l = pctOf(s.start_time), rr = pctOf(s.end_time);
      const bar = node('div', `g-bar g-${s.status}`);
      bar.style.left = `${l}%`;
      bar.style.width = `${Math.max(1.2, rr - l)}%`;
      bar.title = `${s.task_title} · ${String(s.start_time).slice(11, 16)}–${String(s.end_time).slice(11, 16)} · ${humanMinutes(s.duty_minutes)} of duty`;
      bar.appendChild(node('span', null, s.task_title));
      lane.appendChild(bar);
    });

    // Robots with no bookable time left read as unavailable at a glance.
    if (bookable === 0) lane.classList.add('g-spent');

    row.appendChild(lane);
    host.appendChild(row);
  });
}

function shiftGantt(days) {
  ganttDay = new Date(ganttDay.getFullYear(), ganttDay.getMonth(), ganttDay.getDate() + days);
  loadGantt();
}

/* -------------------------------------------------------------- tab: map */

let mapLoaded = false;
let selectedRobotId = null;
let mapSitesCache = [];
let mapRobotsById = new Map();
let currentProject = null;
let driftTimer = null;
let pollTimer = null;
const robotDotEls = new Map();
const robotRowEls = new Map();

/* Great-circle distance in metres — same formula the backend uses for
   nearest-site resolution, so the sidebar's "at X" / "N m from X" agrees
   with what a ping would report. */
function haversineMeters(lat1, lng1, lat2, lng2) {
  const R = 6371000;
  const toRad = d => d * Math.PI / 180;
  const dLat = toRad(lat2 - lat1), dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(a));
}

function positionLabel(r) {
  if (r.lat == null || r.lng == null) return 'Position unknown';
  let best = null, bestD = Infinity;
  mapSitesCache.forEach(s => {
    const d = haversineMeters(Number(r.lat), Number(r.lng), Number(s.latitude), Number(s.longitude));
    if (d < bestD) { bestD = d; best = s; }
  });
  if (!best) return 'Position unknown';
  if (bestD <= Number(best.radius_m ?? 60)) return `At ${best.name}`;
  const dist = bestD >= 1000 ? `${(bestD / 1000).toFixed(1)} km` : `${Math.round(bestD)} m`;
  return `${dist} from ${best.name}`;
}

/* A robot reads as "moving" when it is actively busy, or when it is simply
   between sites — idle-but-in-transit is still a robot travelling, per the
   ping model. Docked/parked robots (idle at a site, charging, maintenance,
   error) hold still, because they genuinely are. */
const isMoving = r => r.status === 'busy' || !positionLabel(r).startsWith('At ');

/* Purely cosmetic wander within a small radius of the real projected point,
   so working robots read as active between the periodic real refreshes. */
function tickDrift() {
  robotDotEls.forEach(dot => {
    if (dot.dataset.moving !== '1') return;
    const bx = Number(dot.dataset.baseX), by = Number(dot.dataset.baseY);
    dot.style.left = `${bx + (Math.random() - 0.5) * 2.4}%`;
    dot.style.top = `${by + (Math.random() - 0.5) * 2.4}%`;
  });
}

/* Re-fetches live state and updates dots/rows in place -- status, battery,
   and position genuinely change server-side (dispatch-to-charging on duty
   exhaustion, other departments booking) and this is what actually reflects it. */
async function refreshMapData() {
  if (!currentProject) return;
  let robots;
  try {
    robots = ((await (await api('/api/map')).json()).data ?? {}).robots ?? [];
  } catch {
    return; // transient -- next tick tries again
  }
  robots.forEach(r => applyRobotUpdate(r));
}

function applyRobotUpdate(r) {
  mapRobotsById.set(r.id, r);
  const st = STATUSES.includes(r.status) ? r.status : 'unknown';
  const moving = isMoving(r);

  const dot = robotDotEls.get(r.id);
  if (dot) {
    const p = currentProject(Number(r.lat), Number(r.lng));
    dot.dataset.baseX = String(p.x);
    dot.dataset.baseY = String(p.y);
    dot.dataset.moving = moving ? '1' : '0';
    if (!moving) { dot.style.left = `${p.x}%`; dot.style.top = `${p.y}%`; }
    dot.className = ['rdot', `s-${st}`, dot.classList.contains('selected') && 'selected',
      dot.classList.contains('hovered') && 'hovered'].filter(Boolean).join(' ');
    dot.title = `${r.name} · ${r.type} · ${r.status} · ${r.battery_level}%`;
    dot.setAttribute('aria-label', `${r.name}, ${r.status}, ${r.battery_level}% battery`);
  }

  const oldRow = robotRowEls.get(r.id);
  if (oldRow) {
    const wasActive = oldRow.classList.contains('active');
    const freshRow = renderMapRow(r);
    if (wasActive) freshRow.classList.add('active');
    oldRow.replaceWith(freshRow);
  }

  if (r.id === selectedRobotId) renderMapDetail(r.id);
}

function startMapAnimation() {
  stopMapAnimation();
  driftTimer = setInterval(tickDrift, 2800);
  pollTimer = setInterval(refreshMapData, 20000);
}

function stopMapAnimation() {
  if (driftTimer) { clearInterval(driftTimer); driftTimer = null; }
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

function hoverRobot(id, on) {
  const dot = robotDotEls.get(id);
  if (dot) dot.classList.toggle('hovered', on);
}

function selectRobot(id) {
  if (selectedRobotId != null) {
    robotDotEls.get(selectedRobotId)?.classList.remove('selected');
    robotRowEls.get(selectedRobotId)?.classList.remove('active');
  }
  selectedRobotId = id;
  if (id != null) {
    robotDotEls.get(id)?.classList.add('selected');
    const row = robotRowEls.get(id);
    if (row) { row.classList.add('active'); row.scrollIntoView({ block: 'nearest' }); }
  }
  renderMapDetail(id);
}

function renderMapDetail(id) {
  const panel = el('map-detail');
  const placeholder = el('map-detail-placeholder');
  clear(panel);

  if (id == null) {
    panel.classList.add('hidden');
    placeholder.classList.remove('hidden');
    return;
  }
  const r = mapRobotsById.get(id);
  if (!r) return;

  placeholder.classList.add('hidden');
  panel.classList.remove('hidden');

  const top = node('div', 'map-detail-top');
  top.appendChild(node('div', 'map-detail-name', r.name));
  const st = STATUSES.includes(r.status) ? r.status : 'unknown';
  top.appendChild(node('span', `pill s-${st}`, r.status));
  panel.appendChild(top);
  panel.appendChild(node('div', 'muted', `${r.type} · id ${r.id}`));
  panel.appendChild(node('div', 'map-detail-pos', positionLabel(r)));

  const batt = node('div', 'batt');
  const bl = node('div', 'batt-label');
  bl.appendChild(node('span', null, 'Battery'));
  bl.appendChild(node('span', null, `${r.battery_level}%`));
  batt.appendChild(bl);
  const track = node('div', 'batt-track');
  const fill = node('div', 'batt-fill');
  fill.style.width = `${Math.max(0, Math.min(100, r.battery_level))}%`;
  fill.style.background = batteryColor(r.battery_level);
  track.appendChild(fill);
  batt.appendChild(track);
  panel.appendChild(batt);

  const pingBtn = node('button', 'ghost tiny', 'Ping');
  const reply = node('div', 'ping-reply hidden');
  pingBtn.onclick = () => pingRobot(id, reply);
  panel.appendChild(pingBtn);
  panel.appendChild(reply);
}

function renderMapRow(r) {
  const row = node('div', 'map-row');
  row.tabIndex = 0;
  row.setAttribute('role', 'button');
  row.setAttribute('aria-label', `Select ${r.name}`);

  const st = STATUSES.includes(r.status) ? r.status : 'unknown';
  row.appendChild(node('span', `map-row-dot s-${st}`));

  const main = node('div', 'map-row-main');
  const top = node('div', 'map-row-top');
  top.appendChild(node('span', 'map-row-name', r.name));
  top.appendChild(node('span', `pill s-${st}`, r.status));
  main.appendChild(top);
  main.appendChild(node('div', 'map-row-pos', positionLabel(r)));

  const battRow = node('div', 'map-row-batt');
  const track = node('div', 'batt-track mini');
  const fill = node('div', 'batt-fill');
  fill.style.width = `${Math.max(0, Math.min(100, r.battery_level))}%`;
  fill.style.background = batteryColor(r.battery_level);
  track.appendChild(fill);
  battRow.appendChild(track);
  battRow.appendChild(node('span', 'map-row-battpct', `${r.battery_level}%`));
  main.appendChild(battRow);

  row.appendChild(main);

  row.addEventListener('click', () => selectRobot(r.id));
  row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectRobot(r.id); } });
  row.addEventListener('mouseenter', () => hoverRobot(r.id, true));
  row.addEventListener('mouseleave', () => hoverRobot(r.id, false));

  robotRowEls.set(r.id, row);
  return row;
}

const STATUS_ORDER = { error: 0, maintenance: 1, busy: 2, charging: 3, idle: 4 };

async function loadMap() {
  if (mapLoaded) { startMapAnimation(); return; }
  mapLoaded = true;

  const host = el('map-host');
  clear(host);
  robotDotEls.clear();
  robotRowEls.clear();
  selectedRobotId = null;

  let data;
  try {
    data = (await (await api('/api/map')).json()).data;
  } catch (e) {
    host.appendChild(node('div', 'err', `Could not load the map: ${e.message}`));
    return;
  }

  const sites = data.sites ?? [];
  const robots = data.robots ?? [];
  mapSitesCache = sites;
  mapRobotsById = new Map(robots.map(r => [r.id, r]));
  if (!sites.length) { host.appendChild(node('div', 'muted', 'No mapped sites.')); return; }

  // Project against the FIXED frame the coordinates were generated on (see
  // sql/migrations/008_map_alignment_v2.sql), not a box re-derived from the
  // sites' own min/max. The sites only span roughly x:7-96, y:3-96 of the
  // artwork -- normalizing against their bounding box stretched the whole
  // map and pushed anything jittered past that box (several "in transit"
  // robots, by design) off the visible canvas. Clamped defensively in case
  // any future data point lands outside the frame anyway.
  const clampPct = n => Math.max(0, Math.min(100, n));
  const project = (lat, lng) => ({
    x: clampPct(((lng - MAP_FRAME.lngMin) / MAP_FRAME.lngSpan * 100) * MAP_CALIBRATION.xScale + MAP_CALIBRATION.xOffset),
    y: clampPct(((MAP_FRAME.latMax - lat) / MAP_FRAME.latSpan * 100) * MAP_CALIBRATION.yScale + MAP_CALIBRATION.yOffset)
  });
  currentProject = project;

  const layout = node('div', 'map-layout');
  const stage = node('div', 'map-stage');
  const canvas = node('div', 'map-canvas');

  // Use a supplied illustration when present; otherwise the schematic grid
  // stays, so the map works before any artwork exists. The aspect ratio is
  // taken from the image itself rather than assumed, so a 3:2 or 16:9 map
  // does not stretch the overlay away from the buildings.
  const bg = new Image();
  bg.onload = () => {
    canvas.classList.add('has-art');
    canvas.style.backgroundImage = `url(${bg.src})`;
    if (bg.naturalWidth && bg.naturalHeight) {
      canvas.style.aspectRatio = `${bg.naturalWidth} / ${bg.naturalHeight}`;
    }
  };
  bg.src = '/images/robotcity.png';

  sites.forEach(s => {
    const p = project(Number(s.latitude), Number(s.longitude));
    const marker = node('div', `site site-${s.domain}`);
    marker.style.left = `${p.x}%`;
    marker.style.top = `${p.y}%`;
    marker.title = `${s.name} (${s.code ?? s.domain})`;
    marker.appendChild(node('span', 'site-dot'));
    marker.appendChild(node('span', 'site-label', s.code ?? s.name));
    canvas.appendChild(marker);
  });

  // Robots draw after (and so on top of) site markers, with a click/hover
  // target padded well past the visible dot — precision-clicking a 10px mark
  // on a busy illustration is not reasonable.
  robots.forEach(r => {
    const p = project(Number(r.lat), Number(r.lng));
    const st = STATUSES.includes(r.status) ? r.status : 'unknown';
    const dot = node('div', `rdot s-${st}`);
    dot.style.left = `${p.x}%`;
    dot.style.top = `${p.y}%`;
    dot.dataset.baseX = String(p.x);
    dot.dataset.baseY = String(p.y);
    dot.dataset.moving = isMoving(r) ? '1' : '0';
    dot.title = `${r.name} · ${r.type} · ${r.status} · ${r.battery_level}%`;
    dot.tabIndex = 0;
    dot.setAttribute('role', 'button');
    dot.setAttribute('aria-label', `${r.name}, ${r.status}, ${r.battery_level}% battery`);
    dot.addEventListener('click', () => selectRobot(r.id));
    dot.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectRobot(r.id); } });
    dot.addEventListener('mouseenter', () => dot.classList.add('hovered'));
    dot.addEventListener('mouseleave', () => dot.classList.remove('hovered'));
    canvas.appendChild(dot);
    robotDotEls.set(r.id, dot);
  });

  stage.appendChild(canvas);

  const legend = node('div', 'map-legend');
  [['healthcare', 'Healthcare'], ['research', 'Research'], ['warehouse', 'Warehouse'],
   ['military', 'Military'], ['security', 'Security'], ['charging', 'Charging docks']]
    .forEach(([k, label]) => {
      const item = node('span', 'legend-item');
      item.appendChild(node('span', `legend-dot site-${k}`));
      item.append(label);
      legend.appendChild(item);
    });
  legend.appendChild(node('span', 'muted', `${sites.length} sites · ${robots.length} robots in scope`));
  stage.appendChild(legend);

  const sidebar = node('aside', 'map-sidebar');
  const head = node('div', 'map-sidebar-head');
  head.appendChild(node('h3', null, 'Fleet'));
  head.appendChild(node('span', 'muted', `${robots.length} robot(s)`));
  sidebar.appendChild(head);

  const detail = node('div', 'map-detail hidden');
  detail.id = 'map-detail';
  sidebar.appendChild(detail);
  const placeholder = node('div', 'map-detail-placeholder muted',
    'Select a robot on the map or in this list to see its position, battery, and send a ping.');
  placeholder.id = 'map-detail-placeholder';
  sidebar.appendChild(placeholder);

  const list = node('div', 'map-robot-list');
  [...robots]
    .sort((a, b) => (STATUS_ORDER[a.status] ?? 5) - (STATUS_ORDER[b.status] ?? 5) || a.name.localeCompare(b.name))
    .forEach(r => list.appendChild(renderMapRow(r)));
  sidebar.appendChild(list);

  layout.append(stage, sidebar);
  host.appendChild(layout);

  startMapAnimation();
}

/* ------------------------------------------------------------------ boot */

async function start() {
  try {
    const res = await api('/api/auth/me');
    if (!res.ok) { showLogin(); return; }
    ME = await res.json();

    el('who').textContent = ME.user.username;
    el('avatar').textContent = ME.user.username.slice(0, 2).toUpperCase();
    el('who-roles').textContent = (ME.user.roles.length ? ME.user.roles.join(', ') : 'no roles')
      + (ME.user.is_admin ? ' · fleet admin' : '');
    el('scope-tag').textContent = ME.user.is_admin ? '· whole fleet' : '· department scope';

    // Booking is only offered to callers who could actually complete it.
    const mayBook = ME.user.can_schedule || ME.user.is_admin;
    el('book-submit').disabled = !mayBook;
    if (!mayBook) el('book-hint').textContent = 'Your role cannot create bookings (needs can_schedule).';

    renderScope(ME);
    showApp();
    mapLoaded = false;
    calendarMonth = null;
    ganttDay = null;

    await Promise.all([loadStats(), loadArenas(), loadRobots(true), loadTasks()]);
    await checkEligible();
  } catch { showLogin(); }
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tab').forEach(t => t.onclick = () => switchTab(t.dataset.tab));
  el('login-btn').onclick = login;
  el('logout-btn').onclick = logout;
  ['username', 'password'].forEach(id =>
    el(id).addEventListener('keydown', e => { if (e.key === 'Enter') login(); }));

  ['f-arena', 'f-status', 'f-type'].forEach(id => el(id).onchange = () => loadRobots(true));
  el('task-select').onchange = checkEligible;
  el('elig-btn').onclick = checkEligible;

  el('cal-prev').onclick = () => shiftMonth(-1);
  el('cal-next').onclick = () => shiftMonth(1);
  el('g-prev').onclick = () => shiftGantt(-1);
  el('g-next').onclick = () => shiftGantt(1);
  el('g-today').onclick = () => { ganttDay = new Date(); loadGantt(); };

  el('book-close').onclick = closeBooking;
  el('book-cancel').onclick = closeBooking;
  el('book-submit').onclick = submitBooking;
  el('book-task').onchange = refreshBookingCandidates;
  el('book-time').onchange = refreshBookingCandidates;
  el('booking').addEventListener('click', e => { if (e.target.id === 'booking') closeBooking(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !el('booking').classList.contains('hidden')) closeBooking();
  });

  start();
});
