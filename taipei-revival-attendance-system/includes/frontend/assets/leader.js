// Taipei Revival Attendance — Leader Frontend (v0.1.x usable)

(function () {
  if (!window.TRAS_LEADER) return;

  const cfg = window.TRAS_LEADER;
  const ep = cfg.endpoints || {};

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (m) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }

  function setMsg(box, text, type) {
    if (!box) return;
    box.innerHTML = `<div class="tr-as-msg tr-as-msg-${type}">${esc(text)}</div>`;
  }

  async function apiGet(action, params = {}) {
    const url = new URL(cfg.ajax_url);
    url.searchParams.set('action', action);
    url.searchParams.set('nonce', cfg.nonce);
    Object.entries(params).forEach(([k, v]) => {
      if (v === undefined || v === null || v === '') return;
      url.searchParams.set(k, String(v));
    });

    const res = await fetch(url.toString(), { credentials: 'same-origin' });
    const json = await res.json().catch(() => null);
    if (!json || !json.success) {
      const msg = (json && json.data && (json.data.message || json.data.code)) ? (json.data.message || json.data.code) : 'ajax failed';
      throw new Error(msg);
    }
    return json.data;
  }

  async function apiPost(action, formObj = {}) {
    const form = new FormData();
    form.append('action', action);
    form.append('nonce', cfg.nonce);
    Object.entries(formObj).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      form.append(k, String(v));
    });

    const res = await fetch(cfg.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      body: form,
    });

    const json = await res.json().catch(() => null);
    if (!json || !json.success) {
      const msg = (json && json.data && (json.data.message || json.data.code)) ? (json.data.message || json.data.code) : 'ajax failed';
      throw new Error(msg);
    }
    return json.data;
  }

  function sessionLabel(s) {
    const ymd = s.ymd ?? '';
    const mt  = s.meeting_type ?? '';
    const slot = s.service_slot ?? '';
    const dt = s.display_text ?? '';
    const parts = [];
    if (ymd) parts.push(ymd);
    if (mt) parts.push(mt);
    if (slot) parts.push(slot);
    if (dt) parts.push(dt);
    const label = parts.filter(Boolean).join(' / ');
    return label || String(s.session_id ?? s.id ?? 'session');
  }

  // tri-state per SSOT:
  // unmarked -> present(onsite) -> present(online) -> unmarked
  function nextState(curStatus, curMode) {
    const st = String(curStatus || 'unmarked').toLowerCase();
    const md = String(curMode || '').toLowerCase();

    if (!st || st === 'unmarked') return { attend_status: 'present', attend_mode: 'onsite' };
    if (st === 'present' && md === 'onsite') return { attend_status: 'present', attend_mode: 'online' };
    return { attend_status: 'unmarked', attend_mode: '' };
  }

  function badge(st, md) {
    const v = String(st || 'unmarked').toLowerCase();
    const m = String(md || '').toLowerCase();

    if (v === 'present' && m === 'onsite') return `<span style="display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #16a34a;color:#16a34a;">出席・現場</span>`;
    if (v === 'present' && m === 'online') return `<span style="display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #2563eb;color:#2563eb;">出席・線上</span>`;
    return `<span style="display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #999;color:#666;">未點</span>`;
  }

  function renderApp(root) {
    root.innerHTML = `
      <div style="border:1px solid #ddd;border-radius:10px;padding:12px;max-width:980px;background:#fff;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <label>聚會場次：
            <select class="tr-as-session" style="min-width:320px;"></select>
          </label>

          <label>新朋友：
            <input class="tr-as-newcomers" type="number" min="0" step="1" value="0" style="width:90px;" />
          </label>

          <button class="tr-as-save" type="button">${esc((cfg.i18n && cfg.i18n.save) || '儲存')}</button>
          <button class="tr-as-reload" type="button">重新載入</button>

          <span class="tr-as-build" style="color:#888;font-size:12px;">build: ${esc(cfg.build)}</span>
        </div>

        <div class="tr-as-msgbox" style="margin-top:10px;"></div>

        <div class="tr-as-tablewrap" style="margin-top:10px;overflow:auto;">
          <table class="tr-as-table" style="width:100%;border-collapse:collapse;">
            <thead>
              <tr>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:8px;width:140px;">狀態</th>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:8px;">姓名</th>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:8px;width:120px;">小隊</th>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:8px;width:180px;">小組</th>
              </tr>
            </thead>
            <tbody class="tr-as-tbody"></tbody>
          </table>
        </div>

        <div class="tr-as-foot" style="margin-top:10px;color:#666;font-size:12px;">
          點「狀態」：未點 → 出席現場 → 出席線上 → 未點（符合 SSOT v1.5）
        </div>
      </div>
    `;

    return {
      sessionSel: qs('.tr-as-session', root),
      newcomersInp: qs('.tr-as-newcomers', root),
      saveBtn: qs('.tr-as-save', root),
      reloadBtn: qs('.tr-as-reload', root),
      msgBox: qs('.tr-as-msgbox', root),
      tbody: qs('.tr-as-tbody', root),
    };
  }

  function renderRows(tbody, rows, stateMap) {
    tbody.innerHTML = '';
    (rows || []).forEach(r => {
      const personId = r.person_id ?? r.id;
      const name = r.name ?? '';
      const teamNo = r.team_no ?? '';
      const groupName = r.group_name ?? '';

      const st = (r.attend_status ?? r.status ?? 'unmarked');
      const md = (r.attend_mode ?? r.mode ?? '');

      stateMap.set(String(personId), { attend_status: st, attend_mode: md });

      const tr = document.createElement('tr');
      tr.dataset.personId = String(personId || '');

      tr.innerHTML = `
        <td style="border-bottom:1px solid #f2f2f2;padding:8px;">
          <button type="button" class="tr-as-toggle" style="cursor:pointer;">${badge(st, md)}</button>
        </td>
        <td style="border-bottom:1px solid #f2f2f2;padding:8px;">${esc(name)}</td>
        <td style="border-bottom:1px solid #f2f2f2;padding:8px;">${esc(teamNo)}</td>
        <td style="border-bottom:1px solid #f2f2f2;padding:8px;">${esc(groupName)}</td>
      `;
      tbody.appendChild(tr);
    });
  }

  async function bootOne(root) {
    const ui = renderApp(root);
    setMsg(ui.msgBox, (cfg.i18n && cfg.i18n.loading) || '載入中…', 'info');

    let sessions = [];
    let currentSessionId = 0;

    // in-memory state
    const stateMap = new Map(); // personId -> {attend_status, attend_mode}
    let dirty = false;

    function setDirty(v) {
      dirty = !!v;
      ui.saveBtn.textContent = dirty ? '儲存（未儲存）' : ((cfg.i18n && cfg.i18n.saved) || '已儲存');
    }

    async function loadSessions() {
      sessions = [];
      ui.sessionSel.innerHTML = '';
      const data = await apiGet(ep.get_sessions || 'tr_as_get_sessions', {});
      sessions = Array.isArray(data.sessions) ? data.sessions : [];

      if (!sessions.length) {
        ui.sessionSel.innerHTML = `<option value="">（沒有可用場次）</option>`;
        setMsg(ui.msgBox, '目前沒有可用的聚會場次。', 'warn');
        return;
      }

      sessions.forEach(s => {
        const opt = document.createElement('option');
        opt.value = String(s.session_id ?? s.id ?? '');
        opt.textContent = sessionLabel(s);
        ui.sessionSel.appendChild(opt);
      });

      currentSessionId = parseInt(ui.sessionSel.value || '0', 10) || 0;
      if (currentSessionId > 0) {
        await loadAll(currentSessionId);
      }
    }

    async function loadNewcomers(sessionId) {
      const data = await apiGet(ep.get_newcomers || 'tr_as_get_newcomers', { session_id: sessionId });
      const nc = data.newcomers || {};
      const v = (nc.newcomers_count ?? nc.count ?? 0);
      ui.newcomersInp.value = String(parseInt(v, 10) || 0);
    }

    async function loadMatrix(sessionId) {
      const data = await apiGet(ep.get_matrix || 'tr_as_get_attendance_matrix', { session_id: sessionId });
      const rows = Array.isArray(data.rows) ? data.rows : [];
      stateMap.clear();
      renderRows(ui.tbody, rows, stateMap);
      return rows.length;
    }

    async function loadAll(sessionId) {
      setMsg(ui.msgBox, (cfg.i18n && cfg.i18n.loading) || '載入中…', 'info');
      ui.tbody.innerHTML = '';
      setDirty(false);

      const n = await loadMatrix(sessionId);
      await loadNewcomers(sessionId);

      setMsg(ui.msgBox, `已載入 ${n} 人。修改後按「儲存」。`, 'ok');
    }

    async function saveAll() {
      if (!currentSessionId) return;

      const records = [];
      stateMap.forEach((v, personId) => {
        const pid = parseInt(personId, 10) || 0;
        if (!pid) return;

        const st = String(v.attend_status || 'unmarked').toLowerCase();
        const md = String(v.attend_mode || '').toLowerCase();

        // normalize: only include allowed shapes
        if (st === 'present') {
          records.push({ person_id: pid, attend_status: 'present', attend_mode: (md === 'online' ? 'online' : 'onsite') });
        } else {
          records.push({ person_id: pid, attend_status: 'unmarked' });
        }
      });

      const newcomers = parseInt(ui.newcomersInp.value || '0', 10);
      const newcomers_count = Number.isFinite(newcomers) && newcomers >= 0 ? newcomers : 0;

      const data = await apiPost(ep.submit_bulk || 'tr_as_submit_leader_bulk', {
        session_id: currentSessionId,
        records: JSON.stringify(records),
        newcomers_count: newcomers_count,
      });

      setDirty(false);
      setMsg(ui.msgBox, `已儲存。attendance: ${esc(JSON.stringify(data.attendance_summary || {}))}`, 'ok');
    }

    ui.sessionSel.addEventListener('change', async () => {
      const sid = parseInt(ui.sessionSel.value || '0', 10) || 0;
      currentSessionId = sid;
      try {
        await loadAll(sid);
      } catch (e) {
        setMsg(ui.msgBox, `載入失敗：${e.message}`, 'error');
      }
    });

    ui.reloadBtn.addEventListener('click', async () => {
      try {
        await loadSessions();
      } catch (e) {
        setMsg(ui.msgBox, `載入失敗：${e.message}`, 'error');
      }
    });

    ui.saveBtn.addEventListener('click', async () => {
      try {
        setMsg(ui.msgBox, '儲存中…', 'info');
        await saveAll();
      } catch (e) {
        setMsg(ui.msgBox, `儲存失敗：${e.message}`, 'error');
      }
    });

    ui.tbody.addEventListener('click', (ev) => {
      const btn = ev.target.closest('.tr-as-toggle');
      if (!btn) return;

      const tr = ev.target.closest('tr');
      if (!tr) return;

      const personId = tr.dataset.personId || '';
      if (!personId) return;

      const cur = stateMap.get(String(personId)) || { attend_status: 'unmarked', attend_mode: '' };
      const nxt = nextState(cur.attend_status, cur.attend_mode);

      stateMap.set(String(personId), nxt);
      btn.innerHTML = badge(nxt.attend_status, nxt.attend_mode);

      setDirty(true);
    });

    ui.newcomersInp.addEventListener('input', () => setDirty(true));

    // bfcache safety (mobile back)
    window.addEventListener('pageshow', function (e) {
      if (e && e.persisted) {
        // reload current session data to avoid stale UI
        if (currentSessionId) {
          loadAll(currentSessionId).catch(() => {});
        }
      }
    });

    try {
      await loadSessions();
    } catch (e) {
      setMsg(ui.msgBox, `載入失敗：${e.message}`, 'error');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tr-as-leader-app').forEach((root) => {
      bootOne(root);
    });
  });
})();
