(function () {
    'use strict';

    function getAjaxUrl() {
        if (window.bc_ajax && window.bc_ajax.ajax_url) return window.bc_ajax.ajax_url;
        if (window.ajaxurl) return window.ajaxurl;
        return '/wp-admin/admin-ajax.php';
    }

    function getApp() {
        return document.getElementById('bc-app');
    }

    function getMeta() {
        const app = getApp();
        if (!app) return null;
        const nonce = app.dataset.nonce;
        const projectId = app.dataset.projectId;
        if (!nonce || !projectId) return null;
        return { nonce, projectId };
    }

    function qsAll(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function syncMatrixFromServer() {
        const meta = getMeta();
        if (!meta) return;

        const checkboxes = qsAll('input.bc-checkin[data-person-id][data-ymd]');
        if (!checkboxes.length) return;

        const personSet = new Set();
        const ymdSet = new Set();

        checkboxes.forEach(cb => {
            personSet.add(String(cb.dataset.personId || '').trim());
            ymdSet.add(String(cb.dataset.ymd || '').trim());
        });

        const personIds = Array.from(personSet).filter(v => /^\d+$/.test(v)).slice(0, 500);
        const ymds = Array.from(ymdSet).filter(v => /^\d{8}$/.test(v)).slice(0, 500);
        if (!personIds.length || !ymds.length) return;

        const formData = new FormData();
        formData.append('action', 'bc_get_checkins_snapshot');
        formData.append('nonce', meta.nonce);
        formData.append('project_id', meta.projectId);
        personIds.forEach(id => formData.append('person_ids[]', id));
        ymds.forEach(ymd => formData.append('ymds[]', ymd));

        fetch(getAjaxUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Cache-Control': 'no-cache'
            },
            body: formData
        })
        .then(res => res.json())
        .then(json => {
            if (!json || !json.success || !json.data || !Array.isArray(json.data.checked)) return;
            const checkedSet = new Set(json.data.checked);
            checkboxes.forEach(cb => {
                const key = String(cb.dataset.personId) + '_' + String(cb.dataset.ymd);
                cb.checked = checkedSet.has(key);
            });
        })
        .catch(err => console.error('Snapshot sync error:', err));
    }

    function handleToggle(checkbox) {
        const meta = getMeta();
        if (!meta) {
            alert('系統錯誤：找不到 nonce / project_id');
            checkbox.checked = !checkbox.checked;
            return;
        }

        if (checkbox.disabled) return;

        const personId = checkbox.dataset.personId;
        const ymd = checkbox.dataset.ymd;

        if (!personId || !ymd) {
            alert('資料不完整，無法打卡');
            checkbox.checked = !checkbox.checked;
            return;
        }

        checkbox.disabled = true;

        const formData = new FormData();
        formData.append('action', 'bc_toggle_checkin');
        formData.append('nonce', meta.nonce);
        formData.append('project_id', meta.projectId);
        formData.append('person_id', personId);
        formData.append('ymd', ymd);

        fetch(getAjaxUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Cache-Control': 'no-cache'
            },
            body: formData
        })
        .then(res => res.json())
        .then(json => {
            if (!json || !json.success) {
                throw new Error((json && json.data && json.data.message) ? json.data.message : '打卡失敗');
            }
            if (json.data && (json.data.status === 'checked' || json.data.status === 'unchecked')) {
                checkbox.checked = (json.data.status === 'checked');
            }
        })
        .catch(err => {
            console.error('Check-in error:', err);
            checkbox.checked = !checkbox.checked;
            alert('打卡失敗，請重新嘗試');
        })
        .finally(() => {
            checkbox.disabled = false;
        });
    }

    document.addEventListener('change', function (e) {
        const checkbox = e.target;
        if (!checkbox || !checkbox.classList || !checkbox.classList.contains('bc-checkin')) return;
        handleToggle(checkbox);
    });

    document.addEventListener('DOMContentLoaded', function () {
        syncMatrixFromServer();

        window.addEventListener('pageshow', function (evt) {
            if (evt && evt.persisted) {
                syncMatrixFromServer();
            } else {
                // 非 persisted 也同步一次（某些手機重整不走 persisted）
                syncMatrixFromServer();
            }
        });
    });

})();
