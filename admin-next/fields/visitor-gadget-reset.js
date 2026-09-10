/**
 * Visitor Gadget — bảng số liệu + nút "Reset bộ đếm" (custom web component
 * field), hiện ngay trong form cấu hình plugin ở Admin2 (Plugins > [TIP] -
 * Visitor Gadget), cuối form. Tách bạch 3 dòng:
 *   - Số thực trong stats.json (GET /visitor-gadget/stats -> raw)
 *   - Số cộng thêm đang cấu hình (-> offset, = 2 field initial_* phía trên)
 *   - Tổng = số thực hiện ra ngoài widget frontend (-> display = raw + offset)
 * Bấm "Reset bộ đếm" gọi POST /visitor-gadget/reset (chỉ đặt raw về 0, không
 * đụng offset), rồi cập nhật lại bảng ngay từ response — không cần tải lại
 * trang. Không phải field lưu giá trị nên không tham gia Save.
 *
 * Backend: classes/VisitorGadgetApiController.php (stats() / reset()).
 * Auth/fetch pattern giống hệt admin-next/pages/simple-multi-language-site.js
 * (đọc access token từ localStorage, fallback về snapshot window.__GRAV_API_TOKEN).
 */

const TAG = window.__GRAV_FIELD_TAG;
const API_BASE = (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
const API_TOKEN_FALLBACK = window.__GRAV_API_TOKEN;

function currentAccessToken() {
    try {
        const keys = ['grav_admin_auth::/admin2', 'grav_admin_auth'];
        for (const key of keys) {
            const raw = localStorage.getItem(key);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed.accessToken === 'string' && parsed.accessToken) {
                    return parsed.accessToken;
                }
            }
        }
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.indexOf('grav_admin_auth') === 0) {
                const raw = localStorage.getItem(key);
                const parsed = raw ? JSON.parse(raw) : null;
                if (parsed && typeof parsed.accessToken === 'string' && parsed.accessToken) {
                    return parsed.accessToken;
                }
            }
        }
    } catch (e) {
        // localStorage unavailable -> fall back to the load-time snapshot.
    }
    return API_TOKEN_FALLBACK;
}

class VisitorGadgetResetField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = null;
        this._stats = null;   // { raw, offset, display } from last fetch
        this._loading = false;
        this._loadError = null;
    }

    set field(v) { this._field = v; this._render(); }
    get field() { return this._field; }

    // Không phải field lưu giá trị thật, nhưng vẫn khai báo getter/setter
    // theo đúng contract chung để host gán/đọc mà không lỗi.
    set value(v) { this._value = v; }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
        this._loadStats();
    }

    async _fetch(path, options = {}) {
        const token = currentAccessToken();
        const res = await fetch(API_BASE + path, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...(options.headers || {}),
            },
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(body?.detail || body?.error?.message || body?.message || `Request failed (${res.status})`);
        }
        return body.data ?? body;
    }

    async _loadStats() {
        this._loading = true;
        this._loadError = null;
        this._renderTable();

        try {
            this._stats = await this._fetch('/visitor-gadget/stats');
        } catch (err) {
            this._loadError = err.message || 'unknown';
        } finally {
            this._loading = false;
            this._renderTable();
        }
    }

    _render() {
        const label = this._field?.label || 'Reset bộ đếm';

        this.innerHTML = `
            <style>
                .vgr-wrap { display: flex; flex-direction: column; gap: 12px; font-family: inherit; }
                .vgr-table { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; overflow: hidden; font-size: 12.5px; }
                .vgr-row { display: grid; grid-template-columns: 1fr 110px 110px; gap: 0; }
                .vgr-row + .vgr-row { border-top: 1px solid var(--border, #e5e7eb); }
                .vgr-row > div { padding: 7px 10px; }
                .vgr-row.vgr-head { background: var(--muted, #f8fafc); color: var(--muted-foreground, #6b7280); font-weight: 600; }
                .vgr-row.vgr-total { background: var(--accent, #f1f5f9); font-weight: 600; }
                .vgr-row .vgr-num { text-align: right; font-variant-numeric: tabular-nums; }
                .vgr-hint { font-size: 12px; color: var(--muted-foreground, #6b7280); margin: 0; }
                .vgr-btn {
                    display: inline-flex; align-items: center; gap: 6px; width: fit-content;
                    border: 1px solid var(--border, #e5e7eb); background: var(--card, #fff);
                    border-radius: 6px; padding: 8px 14px; font-size: 13px; cursor: pointer;
                    color: var(--foreground, #1f2937);
                }
                .vgr-btn:hover:not(:disabled) { background: var(--accent, #f3f4f6); }
                .vgr-btn:disabled { opacity: .6; cursor: default; }
                .vgr-status { font-size: 12.5px; color: var(--muted-foreground, #6b7280); }
                .vgr-status.vgr-error { color: var(--destructive, #dc2626); }
            </style>
            <div class="vgr-wrap">
                <div class="vgr-table-slot"></div>
                <button type="button" class="vgr-btn" data-action="reset">
                    <i class="fa fa-refresh"></i> ${this._escape(label)}
                </button>
                <div class="vgr-status"></div>
            </div>
        `;

        this.querySelector('[data-action="reset"]')?.addEventListener('click', () => this._runReset());
        this._renderTable();
    }

    _renderTable() {
        const slot = this.querySelector('.vgr-table-slot');
        if (!slot) return;

        if (this._loading && !this._stats) {
            slot.innerHTML = `<p class="vgr-hint">Đang tải số liệu...</p>`;
            return;
        }
        if (this._loadError) {
            slot.innerHTML = `<p class="vgr-hint" style="color:var(--destructive,#dc2626)">Lỗi tải số liệu: ${this._escape(this._loadError)}</p>`;
            return;
        }
        if (!this._stats) {
            slot.innerHTML = '';
            return;
        }

        const raw = this._stats.raw || {};
        const offset = this._stats.offset || {};
        const display = this._stats.display || {};
        const n = (v) => Number(v ?? 0).toLocaleString('vi-VN');

        slot.innerHTML = `
            <div class="vgr-table">
                <div class="vgr-row vgr-head">
                    <div>Số liệu</div>
                    <div class="vgr-num">Lượt xem</div>
                    <div class="vgr-num">Khách ghé thăm</div>
                </div>
                <div class="vgr-row">
                    <div>Số thực (stats.json)</div>
                    <div class="vgr-num">${n(raw.page_views)}</div>
                    <div class="vgr-num">${n(raw.unique_visitors)}</div>
                </div>
                <div class="vgr-row">
                    <div>Số cộng thêm (cấu hình)</div>
                    <div class="vgr-num">${n(offset.page_views)}</div>
                    <div class="vgr-num">${n(offset.unique_visitors)}</div>
                </div>
                <div class="vgr-row vgr-total">
                    <div>Tổng — hiện ra ngoài widget</div>
                    <div class="vgr-num">${n(display.page_views)}</div>
                    <div class="vgr-num">${n(display.unique_visitors)}</div>
                </div>
            </div>
        `;
    }

    async _runReset() {
        const ok = window.confirm(
            'Đặt lại số đếm THỰC trong stats.json về 0?\n\n' +
            'Số "cộng thêm" (initial_page_views / initial_unique_visitors) ở trên sẽ KHÔNG đổi — ' +
            'nó vẫn được cộng vào số thực mỗi khi hiển thị ra ngoài trang.'
        );
        if (!ok) return;

        const btn = this.querySelector('[data-action="reset"]');
        const statusEl = this.querySelector('.vgr-status');
        btn.disabled = true;
        statusEl.classList.remove('vgr-error');
        statusEl.textContent = 'Đang đặt lại...';

        try {
            this._stats = await this._fetch('/visitor-gadget/reset', { method: 'POST', body: '{}' });
            this._renderTable();
            statusEl.textContent = 'Đã đặt lại số thực trong stats.json về 0.';
        } catch (err) {
            statusEl.classList.add('vgr-error');
            statusEl.textContent = 'Lỗi: ' + (err.message || 'unknown');
        } finally {
            btn.disabled = false;
        }
    }

    _escape(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }
}

customElements.define(TAG, VisitorGadgetResetField);
