'use strict';

/* Madeline web client — talks to the daemon through the relative "api/" URL,
   so it works under any YunoHost path. All untrusted text is inserted with
   textContent; nothing from Telegram is ever parsed as HTML. */
(() => {
  // ---------------------------------------------------------------- helpers
  const $ = (sel, root = document) => root.querySelector(sel);

  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
      if (value === null || value === undefined || value === false) continue;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
      else if (value === true) node.setAttribute(key, '');
      else node.setAttribute(key, String(value));
    }
    for (const child of children.flat()) {
      if (child === null || child === undefined || child === false) continue;
      node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
  }

  const ICONS = {
    attach: 'M16 7v9a4 4 0 0 1-8 0V6a3 3 0 0 1 6 0v9a1.5 1.5 0 0 1-3 0V8',
    file: 'M6 2h8l5 5v15H6z M14 2v5h5',
    pin: 'M9 3h6l-1 6 3 3H7l3-3z M12 12v9',
    lock: 'M6 11h12v10H6z M8 11V7a4 4 0 0 1 8 0v4',
  };

  function icon(name, size = 18) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    for (const [k, v] of Object.entries({ viewBox: '0 0 24 24', width: size, height: size, fill: 'none', stroke: 'currentColor',
      'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', class: 'icon' })) {
      svg.setAttribute(k, String(v));
    }
    const path = document.createElementNS(ns, 'path');
    path.setAttribute('d', ICONS[name]);
    svg.append(path);
    return svg;
  }

  // ------------------------------------------------------------------- i18n
  function pickLang() {
    const fromUrl = new URLSearchParams(location.search).get('lang');
    if (fromUrl && STRINGS[fromUrl]) {
      localStorage.setItem('madeline.lang', fromUrl);
      return fromUrl;
    }
    const saved = localStorage.getItem('madeline.lang');
    if (saved && STRINGS[saved]) return saved;
    for (const candidate of navigator.languages || [navigator.language]) {
      const code = String(candidate || '').slice(0, 2).toLowerCase();
      if (STRINGS[code]) return code;
    }
    return 'en';
  }

  const lang = pickLang();
  document.documentElement.lang = lang;

  function t(key, ...args) {
    const text = STRINGS[lang][key] ?? STRINGS.en[key] ?? key;
    return text.replace(/\{(\d)\}/g, (_, i) => String(args[Number(i)] ?? ''));
  }

  function langSwitcher() {
    const select = el('select', {
      class: 'lang', 'aria-label': t('language'),
      onchange: (e) => {
        localStorage.setItem('madeline.lang', e.target.value);
        const url = new URL(location.href);
        url.searchParams.delete('lang');
        location.replace(url);
      },
    }, Object.entries(LANGS).map(([code, name]) => el('option', { value: code, selected: code === lang, text: name })));
    return select;
  }

  // -------------------------------------------------------------------- API
  class ApiError extends Error {
    constructor(code, extra = {}) {
      super(code);
      this.code = code;
      this.extra = extra;
    }
  }

  async function api(path, { method = 'GET', body } = {}) {
    let res;
    try {
      res = await fetch('api/' + path, {
        method,
        credentials: 'same-origin',
        headers: method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-Madeline': '1' },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch {
      throw new ApiError('NETWORK');
    }
    if (!(res.headers.get('content-type') || '').includes('application/json')) {
      // The YunoHost session expired and the SSO answered instead: log in again
      location.reload();
      throw new ApiError('NETWORK');
    }
    const data = await res.json();
    if (data.error) {
      if (data.error === 'LOGGED_OUT' && state.view === 'main') loggedOut();
      if (data.error === 'LOCKED') lockedOut();
      throw new ApiError(data.error, data);
    }
    return data;
  }

  function errorText(error) {
    if (!(error instanceof ApiError)) return t('err_generic', (error && error.message) || error);
    const key = 'err_' + error.code;
    if (error.code === 'FLOOD_WAIT' || error.code === 'PASSCODE_FLOOD') return t(key, error.extra.seconds);
    if (error.code === 'FILE_TOO_LARGE') return t(key, Math.round((error.extra.max || 0) / 1048576));
    return key in STRINGS.en ? t(key) : t('err_generic', error.code);
  }

  // ------------------------------------------------------------------ state
  const PAGE = 40;
  const state = {
    view: 'loading',
    user: '',
    me: null,
    dialogs: [],
    dialogsNext: null,
    search: '',
    current: null,
    messages: new Map(), // chat id -> messages, oldest first
    hasOlder: new Map(),
    loadingOlder: false,
    events: null,
    eventsOpenedOnce: false,
    codeType: '',
    lock: { enabled: false, timeout: 15, locked: false },
  };
  const ui = {};

  // ------------------------------------------------------------- formatting
  const timeFmt = new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit' });
  const dayFmt = new Intl.DateTimeFormat(lang, { day: 'numeric', month: 'long', year: 'numeric' });
  const shortFmt = new Intl.DateTimeFormat(lang, { day: 'numeric', month: 'short' });
  const weekdayFmt = new Intl.DateTimeFormat(lang, { weekday: 'short' });

  const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();

  function listTime(unix) {
    if (!unix) return '';
    const date = new Date(unix * 1000);
    const days = (startOfDay(new Date()) - startOfDay(date)) / 86400000;
    if (days < 1) return timeFmt.format(date);
    if (days < 7) return weekdayFmt.format(date);
    return shortFmt.format(date);
  }

  function dayLabel(unix) {
    const date = new Date(unix * 1000);
    const days = Math.round((startOfDay(new Date()) - startOfDay(date)) / 86400000);
    if (days === 0) return t('today');
    if (days === 1) return t('yesterday');
    return dayFmt.format(date);
  }

  function fileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit++;
    }
    return value.toFixed(value < 10 ? 1 : 0) + ' ' + units[unit];
  }

  const titleOf = (d) => (d.kind === 'self' ? t('saved_messages') : d.title || t('deleted_account'));

  function preview(message) {
    if (!message) return '';
    if (message.service) return t('svc_' + message.service);
    const parts = [];
    if (message.media) parts.push(t('media_' + message.media.kind));
    if (message.text) parts.push(message.text);
    return parts.join(': ');
  }

  function avatar(id, title) {
    const initials = title.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => Array.from(w)[0]).join('').toUpperCase() || '#';
    const hue = Math.abs(Number(BigInt(id) % 360n));
    const node = el('span', { class: 'avatar', 'aria-hidden': 'true', text: initials });
    node.style.setProperty('--hue', String(hue));
    return node;
  }

  function linkify(text) {
    const nodes = [];
    const re = /\bhttps?:\/\/[^\s<>"']+[^\s<>"'.,;:!?)\]]/g;
    let last = 0;
    for (const match of text.matchAll(re)) {
      if (match.index > last) nodes.push(text.slice(last, match.index));
      nodes.push(el('a', { href: match[0], target: '_blank', rel: 'noopener noreferrer', text: match[0] }));
      last = match.index + match[0].length;
    }
    if (last < text.length) nodes.push(text.slice(last));
    return nodes;
  }

  // ------------------------------------------------------------------ login
  function renderLoading() {
    state.view = 'loading';
    const slow = el('p', { class: 'muted small', text: t('loading_slow'), hidden: true });
    $('#app').replaceChildren(el('div', { class: 'center-wrap' },
      el('div', { class: 'loading', role: 'status' }, el('span', { class: 'spinner', 'aria-hidden': 'true' }), el('p', { text: t('loading') }), slow)));
    setTimeout(() => { slow.hidden = false; }, 4000);
  }

  function renderLogin(status, error = '') {
    state.view = 'login';
    stopEvents();
    if (status.code_type) state.codeType = status.code_type;

    const errorBox = el('p', { class: 'error', role: 'alert', text: error, hidden: !error });
    const button = el('button', { type: 'submit', class: 'primary' });
    const form = el('form', { class: 'stack' });
    const card = el('div', { class: 'card login' }, el('h1', { text: t('login_title') }));

    let input = null;
    let submit = null;
    switch (status.state) {
      case 'code':
        card.append(el('h2', { text: t('code_title') }));
        card.append(el('p', {
          text: state.codeType === 'auth.sentCodeTypeApp' ? t('code_sent_app')
            : state.codeType === 'auth.sentCodeTypeSms' ? t('code_sent_sms') : t('code_sent_other'),
        }));
        input = el('input', { name: 'code', inputmode: 'numeric', autocomplete: 'one-time-code', required: true, maxlength: 16, 'aria-label': t('code_label') });
        form.append(el('label', { text: t('code_label') }, input));
        button.textContent = t('btn_sign_in');
        submit = () => api('login/code', { method: 'POST', body: { code: input.value.trim() } });
        break;
      case 'password':
        card.append(el('h2', { text: t('password_title') }), el('p', { text: t('password_intro') }));
        if (status.hint) card.append(el('p', { class: 'muted', text: t('password_hint', status.hint) }));
        input = el('input', { name: 'password', type: 'password', autocomplete: 'current-password', required: true, 'aria-label': t('password_label') });
        form.append(el('label', { text: t('password_label') }, input));
        button.textContent = t('btn_sign_in');
        submit = () => api('login/password', { method: 'POST', body: { password: input.value } });
        break;
      case 'signup':
        card.append(el('p', { text: t('signup_needed') }));
        break;
      default:
        card.append(el('p', { class: 'muted', text: t('login_intro', state.user) }));
        input = el('input', { name: 'phone', type: 'tel', autocomplete: 'tel', required: true, placeholder: t('phone_placeholder'), 'aria-label': t('phone_label') });
        form.append(el('label', { text: t('phone_label') }, input));
        button.textContent = t('btn_send_code');
        submit = () => api('login/phone', { method: 'POST', body: { phone: input.value } });
    }

    if (submit) {
      form.append(errorBox, button);
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;
        errorBox.hidden = true;
        try {
          const next = await submit();
          if (next.state === 'ready') startMain(next);
          else renderLogin(next);
        } catch (e) {
          errorBox.textContent = errorText(e);
          errorBox.hidden = false;
          button.disabled = false;
          if (e.code === 'SIGNUP_REQUIRED') renderLogin({ state: 'signup' });
          else if (e.code === 'PHONE_CODE_EXPIRED' || e.code === 'LOGIN_NOT_STARTED') renderLogin({ state: 'logged_out' }, errorText(e));
          else input.focus();
        }
      });
      card.append(form);
    } else {
      card.append(errorBox);
    }

    if (status.state !== 'logged_out') {
      card.append(el('button', {
        type: 'button', class: 'link', text: t('btn_start_over'),
        onclick: async () => {
          try {
            await api('logout', { method: 'POST' });
          } catch {
            // The session is gone either way
          }
          renderLogin({ state: 'logged_out' });
        },
      }));
    }

    card.append(el('div', { class: 'footer' }, langSwitcher()));
    $('#app').replaceChildren(el('div', { class: 'center-wrap' }, card));
    if (input) input.focus();
  }

  function loggedOut() {
    renderLogin({ state: 'logged_out' }, t('err_LOGGED_OUT'));
  }

  // Route to the right screen for a /status answer
  function show(status) {
    state.user = status.user || state.user;
    if (status.lock) state.lock = status.lock;
    if (status.state === 'locked') renderLock();
    else if (status.state === 'ready') startMain(status);
    else renderLogin(status);
  }

  // ------------------------------------------------------------------- lock
  // The daemon enforces the lock: while this browser is locked it answers
  // LOCKED to everything and closes the live stream. Here we only drop what
  // the page holds and show the lock screen.
  function lockedOut() {
    if (state.view === 'lock') return;
    state.lock.locked = true;
    renderLock();
  }

  function forget() {
    stopEvents();
    closeDialog();
    state.me = null;
    state.dialogs = [];
    state.dialogsNext = null;
    state.current = null;
    state.messages.clear();
    state.hasOlder.clear();
    document.title = t('app_title');
  }

  function renderLock(error = '') {
    forget();
    state.view = 'lock';
    const errorBox = el('p', { class: 'error', role: 'alert', text: error, hidden: !error });
    const input = el('input', { type: 'password', autocomplete: 'current-password', required: true, maxlength: 64, 'aria-label': t('passcode_label') });
    const button = el('button', { type: 'submit', class: 'primary', text: t('btn_unlock') });
    const form = el('form', { class: 'stack' }, usernameField(), el('label', { text: t('passcode_label') }, input), errorBox, button);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      button.disabled = true;
      errorBox.hidden = true;
      try {
        show(await api('lock/unlock', { method: 'POST', body: { passcode: input.value } }));
      } catch (e) {
        errorBox.textContent = errorText(e);
        errorBox.hidden = false;
        button.disabled = false;
        input.select();
      }
    });
    const card = el('div', { class: 'card login' },
      el('div', { class: 'lock-icon' }, icon('lock', 40)),
      el('h1', { text: t('lock_title') }),
      el('p', { class: 'muted', text: t('lock_intro') }),
      form,
      el('button', {
        type: 'button', class: 'link', text: t('forgot_passcode'),
        onclick: async () => {
          if (!confirm(t('confirm_forgot'))) return;
          try {
            await api('logout', { method: 'POST' });
          } catch {
            // The session is gone either way
          }
          renderLogin({ state: 'logged_out' });
        },
      }),
      el('div', { class: 'footer' }, langSwitcher()));
    $('#app').replaceChildren(el('div', { class: 'center-wrap' }, card));
    input.focus();
  }

  // Lets password managers tell this passcode apart from other accounts
  function usernameField() {
    return el('input', { type: 'text', name: 'username', autocomplete: 'username', value: `${state.user} (Telegram passcode)`, hidden: true, readonly: true, tabindex: -1, 'aria-hidden': 'true' });
  }

  async function lockNow() {
    try {
      await api('lock/lock', { method: 'POST' });
    } catch {
      // Locked either way
    }
    lockedOut();
  }

  // Tell the daemon this browser is in use, at most every 30 s (or a third of
  // the timeout), and only for real interaction in a visible tab: an idle or
  // background tab lets the lock kick in.
  let lastActivity = 0;
  function activity() {
    if (state.view !== 'main' || !state.lock.enabled || document.visibilityState !== 'visible') return;
    const every = Math.min(30000, (state.lock.timeout * 60000) / 3);
    if (Date.now() - lastActivity < every) return;
    lastActivity = Date.now();
    api('lock/activity', { method: 'POST' }).then((r) => { if (r.locked) lockedOut(); }).catch(() => {});
  }
  // Only real input counts. Not "scroll" (also fired by the page scrolling
  // itself to new messages), and pointer moves only if the pointer really
  // moved: Chrome sends synthetic mousemoves when the page changes under a
  // still cursor, which would keep an unattended tab unlocked.
  for (const type of ['pointerdown', 'keydown', 'wheel', 'touchstart']) {
    document.addEventListener(type, (e) => { if (e.isTrusted) activity(); }, { capture: true, passive: true });
  }
  let pointer = null;
  document.addEventListener('pointermove', (e) => {
    if (!e.isTrusted) return;
    const moved = pointer === null || Math.abs(e.screenX - pointer[0]) + Math.abs(e.screenY - pointer[1]) >= 3;
    pointer = [e.screenX, e.screenY];
    if (moved) activity();
  }, { capture: true, passive: true });

  const TIMEOUTS = [1, 5, 15, 30, 60, 120, 240, 480, 1440];
  const timeoutLabel = (m) => (m < 60 ? t('minutes_n', m) : t('hours_n', m / 60));

  function closeDialog() {
    if (ui.dialog) {
      ui.dialog.remove();
      ui.dialog = null;
    }
  }

  function openLockSettings() {
    closeDialog();
    const enabled = state.lock.enabled;
    const errorBox = el('p', { class: 'error', role: 'alert', hidden: true });
    const timeout = el('select', { 'aria-label': t('lock_timeout') },
      [...new Set([...TIMEOUTS, state.lock.timeout])].sort((a, b) => a - b)
        .map((m) => el('option', { value: m, selected: m === state.lock.timeout, text: timeoutLabel(m) })));
    const passcode = el('input', { type: 'password', autocomplete: 'new-password', maxlength: 64, required: !enabled });
    const repeat = el('input', { type: 'password', autocomplete: 'new-password', maxlength: 64, required: !enabled });
    const current = el('input', { type: 'password', autocomplete: 'current-password', maxlength: 64, required: true });
    const save = el('button', { type: 'submit', class: 'primary', text: enabled ? t('btn_save') : t('btn_enable_lock') });

    const fail = (e) => {
      errorBox.textContent = typeof e === 'string' ? e : errorText(e);
      errorBox.hidden = false;
    };
    const done = (status) => {
      if (status.lock) state.lock = status.lock;
      lastActivity = Date.now();
      closeDialog();
      renderLockButtons();
    };

    const form = el('form', { class: 'stack' },
      usernameField(),
      el('p', { class: 'muted', text: enabled ? t('lock_settings_intro') : t('lock_settings_off') }),
      el('label', { text: t('lock_timeout') }, timeout),
      el('label', { text: enabled ? t('new_passcode_optional') : t('new_passcode') }, passcode),
      el('label', { text: t('repeat_passcode') }, repeat),
      enabled ? el('label', { text: t('current_passcode') }, current) : null,
      errorBox,
      el('div', { class: 'row-buttons' },
        save,
        el('button', { type: 'button', text: t('btn_close'), onclick: closeDialog })));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      errorBox.hidden = true;
      if (passcode.value !== repeat.value) return fail(t('err_PASSCODE_MISMATCH'));
      const body = { timeout: Number(timeout.value) };
      if (passcode.value) body.passcode = passcode.value;
      if (enabled) body.current = current.value;
      save.disabled = true;
      try {
        done(await api('lock/settings', { method: 'POST', body }));
      } catch (e) {
        save.disabled = false;
        if (e.code !== 'LOCKED') fail(e);
      }
    });

    const panel = el('div', { class: 'card dialog-card', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('lock_settings') },
      el('h2', { text: t('lock_settings') }), form);
    if (enabled) {
      panel.append(el('button', {
        type: 'button', class: 'link danger', text: t('btn_disable_lock'),
        onclick: async () => {
          errorBox.hidden = true;
          if (!current.value) return fail(t('err_PASSCODE_WRONG'));
          try {
            done(await api('lock/disable', { method: 'POST', body: { current: current.value } }));
          } catch (e) {
            if (e.code !== 'LOCKED') fail(e);
          }
        },
      }));
    }
    ui.dialog = el('div', { class: 'overlay', onclick: (e) => { if (e.target === ui.dialog) closeDialog(); } }, panel);
    ui.dialog.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDialog(); });
    document.body.append(ui.dialog);
    (enabled ? current : passcode).focus();
  }

  function renderLockButtons() {
    if (!ui.lockButtons) return;
    const buttons = [el('button', { type: 'button', class: 'ghost icon-button', title: t('lock_settings'), 'aria-label': t('lock_settings'), onclick: openLockSettings }, icon('lock', 18))];
    if (state.lock.enabled) buttons.push(el('button', { type: 'button', class: 'ghost', text: t('lock_now'), onclick: lockNow }));
    ui.lockButtons.replaceChildren(...buttons);
  }

  // ------------------------------------------------------------------- main
  function startMain(status) {
    state.view = 'main';
    lastActivity = Date.now();
    state.me = status.me || null;
    state.dialogs = [];
    state.dialogsNext = null;
    state.current = null;
    state.messages.clear();
    state.hasOlder.clear();
    buildLayout();
    loadDialogs();
    startEvents();
  }

  function buildLayout() {
    ui.search = el('input', {
      type: 'search', class: 'search', placeholder: t('search'), 'aria-label': t('search'),
      oninput: (e) => { state.search = e.target.value.trim().toLowerCase(); renderDialogs(); },
    });
    ui.dialogs = el('ul', { class: 'dialogs' });
    ui.more = el('button', { type: 'button', class: 'link more', text: t('load_more'), hidden: true, onclick: () => loadDialogs(true) });
    ui.reconnecting = el('div', { class: 'notice', text: t('reconnecting'), hidden: true });

    const sidebar = el('aside', { class: 'sidebar' },
      el('header', { class: 'bar' },
        el('div', { class: 'me' },
          el('strong', { text: t('app_title') }),
          el('span', { class: 'muted small', text: state.me ? t('signed_in_as', state.me.name || state.me.phone || '') : '' })),
        langSwitcher(),
        ui.lockButtons = el('span', { class: 'lock-buttons' }),
        el('button', {
          type: 'button', class: 'ghost', text: t('logout'),
          onclick: async () => {
            if (!confirm(t('confirm_logout'))) return;
            try {
              await api('logout', { method: 'POST' });
            } catch {
              // Already logged out
            }
            renderLogin({ state: 'logged_out' });
          },
        })),
      ui.reconnecting,
      el('div', { class: 'search-wrap' }, ui.search),
      ui.dialogs,
      ui.more);
    renderLockButtons();

    ui.chatTitle = el('strong', { class: 'ellipsis' });
    ui.chatSubtitle = el('span', { class: 'muted small' });
    ui.chatAvatar = el('span');
    ui.older = el('button', { type: 'button', class: 'link older', text: t('load_older'), hidden: true, onclick: loadOlder });
    ui.list = el('div', { class: 'list' });
    ui.messages = el('div', { class: 'messages', onscroll: () => { if (ui.messages.scrollTop < 80) loadOlder(); } }, ui.older, ui.list);
    ui.toast = el('div', { class: 'toast', role: 'alert', hidden: true });
    ui.upload = el('div', { class: 'upload-status', hidden: true });
    ui.file = el('input', { type: 'file', multiple: true, hidden: true, onchange: (e) => { sendFiles([...e.target.files]); e.target.value = ''; } });
    ui.text = el('textarea', {
      rows: 1, placeholder: t('composer_placeholder'), 'aria-label': t('composer_placeholder'), maxlength: 4096,
      oninput: autosize,
      onkeydown: (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
          e.preventDefault();
          sendText();
        }
      },
      onpaste: (e) => {
        const files = [...(e.clipboardData?.files || [])];
        if (files.length) {
          e.preventDefault();
          sendFiles(files);
        }
      },
    });
    ui.send = el('button', { type: 'submit', class: 'primary', text: t('btn_send') });
    ui.composer = el('form', { class: 'composer', onsubmit: (e) => { e.preventDefault(); sendText(); } },
      el('button', { type: 'button', class: 'ghost attach', title: t('btn_attach'), 'aria-label': t('btn_attach'), onclick: () => ui.file.click() }, icon('attach', 22)),
      ui.file, ui.text, ui.send);
    ui.drop = el('div', { class: 'dropzone', text: t('drop_here'), hidden: true });
    ui.empty = el('div', { class: 'empty muted', text: t('select_chat') });

    ui.chatPane = el('div', { class: 'chat-pane', hidden: true },
      el('header', { class: 'bar' },
        el('button', { type: 'button', class: 'ghost back', text: '←', 'aria-label': t('back'), onclick: () => { ui.layout.dataset.view = 'list'; } }),
        ui.chatAvatar,
        el('div', { class: 'chat-title' }, ui.chatTitle, ui.chatSubtitle)),
      ui.messages, ui.toast, ui.upload, ui.composer, ui.drop);

    const main = el('main', { class: 'chat' }, ui.empty, ui.chatPane);
    let dragDepth = 0;
    main.addEventListener('dragenter', (e) => { if (state.current && e.dataTransfer?.types.includes('Files')) { dragDepth++; ui.drop.hidden = false; } });
    main.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; ui.drop.hidden = true; } });
    main.addEventListener('dragover', (e) => { if (state.current) e.preventDefault(); });
    main.addEventListener('drop', (e) => {
      e.preventDefault();
      dragDepth = 0;
      ui.drop.hidden = true;
      if (state.current) sendFiles([...(e.dataTransfer?.files || [])]);
    });

    ui.layout = el('div', { class: 'layout', 'data-view': 'list' }, sidebar, main);
    $('#app').replaceChildren(ui.layout);
  }

  function autosize() {
    ui.text.style.height = 'auto';
    ui.text.style.height = Math.min(ui.text.scrollHeight + 2, 200) + 'px';
    ui.text.style.overflowY = ui.text.scrollHeight > 200 ? 'auto' : 'hidden';
  }

  function showToast(text) {
    ui.toast.textContent = text;
    ui.toast.hidden = false;
    clearTimeout(ui.toastTimer);
    ui.toastTimer = setTimeout(() => { ui.toast.hidden = true; }, 6000);
  }

  // ---------------------------------------------------------------- dialogs
  async function loadDialogs(more = false) {
    const next = more ? state.dialogsNext : null;
    const query = next ? `?offset_date=${next.date}&offset_id=${next.id}&offset_peer=${next.peer}` : '';
    ui.more.disabled = true;
    try {
      const page = await api('dialogs' + query);
      const known = new Set(state.dialogs.map((d) => d.id));
      if (!more) state.dialogs = [];
      for (const dialog of page.dialogs) {
        if (!more || !known.has(dialog.id)) state.dialogs.push(dialog);
      }
      state.dialogsNext = page.next;
      if (!more) sortDialogs();
      renderDialogs();
    } catch (e) {
      if (state.view === 'main') showToastOrList(errorText(e));
    } finally {
      ui.more.disabled = false;
    }
  }

  function showToastOrList(text) {
    if (state.current) showToast(text);
    else ui.dialogs.replaceChildren(el('li', { class: 'error', text }));
  }

  function sortDialogs() {
    state.dialogs.sort((a, b) => (Number(b.pinned) - Number(a.pinned)) || ((b.last?.date || 0) - (a.last?.date || 0)));
  }

  function renderDialogs() {
    const items = state.dialogs
      .filter((d) => !state.search || titleOf(d).toLowerCase().includes(state.search))
      .map((d) => {
        const title = titleOf(d);
        const kindLabel = { bot: t('kind_bot'), channel: t('kind_channel') }[d.kind];
        return el('li', {},
          el('button', {
            type: 'button', class: 'dialog' + (d.id === state.current ? ' active' : ''),
            onclick: () => openChat(d.id),
          },
          avatar(d.id, title),
          el('span', { class: 'dialog-main' },
            el('span', { class: 'dialog-top' },
              el('span', { class: 'ellipsis title' }, d.pinned ? icon('pin', 14) : null, title, kindLabel ? el('span', { class: 'muted small', text: ' · ' + kindLabel }) : null),
              el('span', { class: 'muted small', text: listTime(d.last?.date) })),
            el('span', { class: 'dialog-bottom' },
              el('span', { class: 'ellipsis muted', text: (d.last?.out ? '✓ ' : '') + preview(d.last) }),
              d.unread ? el('span', { class: 'badge', text: d.unread > 999 ? '999+' : String(d.unread) }) : null))));
      });
    ui.dialogs.replaceChildren(...(items.length ? items : [el('li', { class: 'muted center', text: t('no_chats') })]));
    ui.more.hidden = !state.dialogsNext || !!state.search;
    const unread = state.dialogs.reduce((sum, d) => sum + (d.unread || 0), 0);
    document.title = (unread ? `(${unread}) ` : '') + t('app_title');
  }

  function dialogFor(id) {
    return state.dialogs.find((d) => d.id === id);
  }

  // --------------------------------------------------------------- messages
  async function openChat(id) {
    state.current = id;
    const dialog = dialogFor(id) || { id, title: '', kind: 'user' };
    const title = titleOf(dialog);
    ui.chatTitle.textContent = title;
    ui.chatSubtitle.textContent = { bot: t('kind_bot'), channel: t('kind_channel'), group: t('kind_group') }[dialog.kind] || '';
    ui.chatAvatar.replaceWith(ui.chatAvatar = avatar(id, title));
    ui.empty.hidden = true;
    ui.chatPane.hidden = false;
    ui.layout.dataset.view = 'chat';
    ui.toast.hidden = true;
    renderDialogs();

    if (state.messages.has(id)) {
      renderMessages({ toBottom: true });
    } else {
      ui.older.hidden = true;
      ui.list.replaceChildren(el('p', { class: 'center muted', text: t('loading') }));
    }
    try {
      const { messages } = await api('history?chat=' + id);
      if (!state.messages.has(id)) state.hasOlder.set(id, messages.length >= PAGE);
      merge(id, messages);
      if (state.current === id) renderMessages({ toBottom: true });
      markRead(id);
    } catch (e) {
      if (state.current === id) {
        ui.list.replaceChildren(el('p', { class: 'center error', text: errorText(e) }));
      }
    }
    if (!matchMedia('(pointer: coarse)').matches) ui.text.focus();
  }

  function merge(chat, messages) {
    const byId = new Map((state.messages.get(chat) || []).map((m) => [m.id, m]));
    for (const message of messages) byId.set(message.id, message);
    state.messages.set(chat, [...byId.values()].sort((a, b) => a.id - b.id));
  }

  async function loadOlder() {
    const chat = state.current;
    const list = state.messages.get(chat);
    if (!chat || !list || !list.length || state.loadingOlder || !state.hasOlder.get(chat)) return;
    state.loadingOlder = true;
    ui.older.disabled = true;
    try {
      const { messages } = await api(`history?chat=${chat}&before=${list[0].id}`);
      state.hasOlder.set(chat, messages.length >= PAGE);
      merge(chat, messages);
      if (state.current === chat) {
        const fromBottom = ui.messages.scrollHeight - ui.messages.scrollTop;
        renderMessages();
        ui.messages.scrollTop = ui.messages.scrollHeight - fromBottom;
      }
    } catch (e) {
      showToast(errorText(e));
    } finally {
      state.loadingOlder = false;
      ui.older.disabled = false;
    }
  }

  const nearBottom = () => ui.messages.scrollHeight - ui.messages.scrollTop - ui.messages.clientHeight < 120;

  function renderMessages({ toBottom = false } = {}) {
    const chat = state.current;
    const messages = state.messages.get(chat) || [];
    const dialog = dialogFor(chat);
    const showNames = dialog && dialog.kind === 'group';
    const nodes = [];
    let lastDay = null;
    for (const m of messages) {
      const day = new Date(m.date * 1000).toDateString();
      if (day !== lastDay) {
        nodes.push(el('div', { class: 'day' }, el('span', { text: dayLabel(m.date) })));
        lastDay = day;
      }
      nodes.push(messageNode(m, showNames));
    }
    ui.older.hidden = !state.hasOlder.get(chat);
    ui.list.replaceChildren(...nodes);
    if (toBottom) ui.messages.scrollTop = ui.messages.scrollHeight;
  }

  function messageNode(m, showNames) {
    if (m.service) {
      return el('div', { class: 'service' }, el('span', { text: (m.from_name ? m.from_name + ': ' : '') + t('svc_' + m.service) }));
    }
    const bubble = el('div', { class: 'msg ' + (m.out ? 'out' : 'in'), 'data-id': m.id });
    if (showNames && !m.out && m.from_name) {
      const name = el('div', { class: 'from', text: m.from_name });
      name.style.setProperty('--hue', String(Math.abs(Number(BigInt(m.from || 0) % 360n))));
      bubble.append(name);
    }
    if (m.media) bubble.append(mediaNode(m));
    if (m.text) bubble.append(el('div', { class: 'text' }, linkify(m.text)));
    bubble.append(el('div', { class: 'meta muted small' }, m.edited ? t('edited') + ' · ' : '', timeFmt.format(new Date(m.date * 1000))));
    return el('div', { class: 'row ' + (m.out ? 'out' : 'in') }, bubble);
  }

  function mediaNode(m) {
    const media = m.media;
    const url = `api/media?chat=${m.chat}&msg=${m.id}`;
    const download = el('a', { class: 'small', href: url + '&download=1', text: t('download') });
    switch (media.kind) {
      case 'photo':
        return el('a', { class: 'media', href: url, target: '_blank', rel: 'noopener' },
          el('img', { src: url, loading: 'lazy', alt: t('media_photo') }));
      case 'sticker':
        return media.mime === 'image/webp'
          ? el('img', { class: 'sticker', src: url, loading: 'lazy', alt: t('media_sticker') })
          : el('div', { class: 'media-label', text: t('media_sticker') });
      case 'gif':
        return el('video', { class: 'media', src: url, autoplay: true, loop: true, muted: true, playsinline: true, preload: 'metadata' });
      case 'video':
      case 'round':
        return el('div', { class: 'media' }, el('video', { src: url, controls: true, preload: 'none', playsinline: true }), download);
      case 'voice':
      case 'audio':
        return el('div', { class: 'media' }, el('audio', { src: url, controls: true, preload: 'none' }), media.name ? el('div', { class: 'small', text: media.name }) : null);
      case 'file':
        return el('a', { class: 'file', href: url + '&download=1' },
          el('span', { class: 'file-icon' }, icon('file', 28)),
          el('span', {}, el('span', { class: 'ellipsis', text: media.name || t('media_file') }), el('span', { class: 'muted small', text: fileSize(media.size || 0) })));
      default:
        return el('div', { class: 'media-label', text: t('media_' + media.kind) + (media.name ? ': ' + media.name : '') + (media.phone ? ' ' + media.phone : '') });
    }
  }

  function markRead(chat) {
    const list = state.messages.get(chat);
    const dialog = dialogFor(chat);
    if (!list || !list.length || document.visibilityState !== 'visible') return;
    if (dialog) {
      dialog.unread = 0;
      renderDialogs();
    }
    api('read', { method: 'POST', body: { chat, max_id: list[list.length - 1].id } }).catch(() => {});
  }

  async function refreshLatest(chat) {
    try {
      const { messages } = await api('history?chat=' + chat);
      merge(chat, messages);
      if (state.current === chat) renderMessages({ toBottom: true });
      const list = state.messages.get(chat);
      const dialog = dialogFor(chat);
      if (dialog && list.length) {
        dialog.last = list[list.length - 1];
        sortDialogs();
        renderDialogs();
      }
    } catch {
      // The live stream or the next reload will catch up
    }
  }

  async function sendText() {
    const chat = state.current;
    const text = ui.text.value;
    if (!chat || !text.trim()) return;
    ui.send.disabled = true;
    try {
      await api('send', { method: 'POST', body: { chat, text } });
      ui.text.value = '';
      autosize();
      await refreshLatest(chat);
    } catch (e) {
      showToast(errorText(e));
    } finally {
      ui.send.disabled = false;
      ui.text.focus();
    }
  }

  async function sendFiles(files) {
    const chat = state.current;
    if (!chat || !files.length) return;
    let caption = ui.text.value.trim();
    ui.text.value = '';
    autosize();
    for (const file of files) {
      try {
        await upload(chat, file, caption);
      } catch (e) {
        showToast(`${file.name}: ${errorText(e)}`);
      }
      caption = '';
    }
    ui.upload.hidden = true;
    await refreshLatest(chat);
  }

  function upload(chat, file, caption) {
    return new Promise((resolve, reject) => {
      const params = new URLSearchParams({ chat: String(chat), name: file.name || 'file', caption });
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/upload?' + params);
      xhr.setRequestHeader('X-Madeline', '1');
      xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
      ui.upload.hidden = false;
      ui.upload.textContent = t('uploading', file.name);
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) ui.upload.textContent = `${t('uploading', file.name)} ${Math.round((e.loaded / e.total) * 100)}%`;
      };
      xhr.onerror = () => reject(new ApiError('NETWORK'));
      xhr.onload = () => {
        let data = null;
        try {
          data = JSON.parse(xhr.responseText);
        } catch {
          // nginx answers 413 with HTML when the file exceeds client_max_body_size
        }
        if (xhr.status === 413 && !data) reject(new ApiError('FILE_TOO_LARGE', { max: 0 }));
        else if (!data) reject(new ApiError('NETWORK'));
        else if (data.error) {
          if (data.error === 'LOCKED') lockedOut();
          reject(new ApiError(data.error, data));
        }
        else resolve(data);
      };
      xhr.send(file);
    });
  }

  // ------------------------------------------------------------ live events
  function startEvents() {
    stopEvents();
    const source = new EventSource('api/events');
    state.events = source;
    source.onopen = () => {
      ui.reconnecting.hidden = true;
      if (state.eventsOpenedOnce) {
        // Catch up with whatever happened while disconnected
        loadDialogs();
        if (state.current) refreshLatest(state.current);
      }
      state.eventsOpenedOnce = true;
    };
    source.onmessage = (e) => {
      try {
        handleEvent(JSON.parse(e.data));
      } catch (error) {
        console.error(error);
      }
    };
    source.onerror = () => {
      if (state.events !== source) return;
      ui.reconnecting.hidden = false;
      if (source.readyState === EventSource.CLOSED) {
        // The browser gave up (e.g. the SSO session expired): check why
        setTimeout(async () => {
          if (state.events !== source) return;
          try {
            const status = await api('status');
            if (status.state === 'ready') startEvents();
            else show(status);
          } catch {
            setTimeout(() => { if (state.events === source) startEvents(); }, 10000);
          }
        }, 3000);
      }
    };
  }

  function stopEvents() {
    if (state.events) {
      state.events.close();
      state.events = null;
    }
    state.eventsOpenedOnce = false;
  }

  function handleEvent(event) {
    switch (event.type) {
      case 'message': {
        const m = event.message;
        const open = state.current === m.chat;
        const wasNearBottom = open && nearBottom();
        if (state.messages.has(m.chat)) merge(m.chat, [m]);
        let dialog = dialogFor(m.chat);
        if (!dialog) {
          dialog = { id: m.chat, title: event.chat_title || '', kind: event.chat_kind || 'user', unread: 0, pinned: false, last: null };
          state.dialogs.push(dialog);
        }
        if (!dialog.last || m.id >= dialog.last.id) dialog.last = m;
        if (!m.out && !(open && document.visibilityState === 'visible')) dialog.unread = (dialog.unread || 0) + 1;
        sortDialogs();
        renderDialogs();
        if (open) {
          renderMessages({ toBottom: wasNearBottom || m.out });
          if (!m.out) markRead(m.chat);
        }
        break;
      }
      case 'edit': {
        const m = event.message;
        if (state.messages.has(m.chat)) merge(m.chat, [m]);
        const dialog = dialogFor(m.chat);
        if (dialog && dialog.last && dialog.last.id === m.id) {
          dialog.last = m;
          renderDialogs();
        }
        if (state.current === m.chat) renderMessages();
        break;
      }
      case 'delete': {
        const ids = new Set(event.ids);
        for (const [chat, list] of state.messages) {
          if (event.chat === null ? isPrivateOrBasicGroup(chat) : chat === event.chat) {
            state.messages.set(chat, list.filter((m) => !ids.has(m.id)));
          }
        }
        if (state.current) renderMessages();
        break;
      }
      case 'read': {
        const dialog = dialogFor(event.chat);
        if (dialog) {
          dialog.unread = event.unread;
          renderDialogs();
        }
        break;
      }
      case 'logged_out':
        loggedOut();
        break;
      case 'locked':
        // The daemon closed this stream: either this browser is locked, or the
        // stream predates this browser's current unlock cookie (lock just
        // enabled or passcode changed here). Ask with the current cookie.
        stopEvents();
        api('status').then((status) => {
          if (status.state === 'locked') lockedOut();
          else if (state.view === 'main') startEvents();
        }).catch(() => {});
        break;
    }
  }

  // Deletions without a chat id only concern private chats and basic groups,
  // whose message ids are shared (channel ids are <= -1000000000000).
  function isPrivateOrBasicGroup(chat) {
    return chat > -1000000000000;
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible' || state.view !== 'main') return;
    if (state.lock.enabled) {
      // Back from the background: the daemon may have locked us meanwhile
      api('status').then((status) => { if (status.state === 'locked') lockedOut(); else if (state.current) markRead(state.current); }).catch(() => {});
    } else if (state.current) {
      markRead(state.current);
    }
  });

  // ------------------------------------------------------------------ start
  async function boot() {
    renderLoading();
    try {
      show(await api('status'));
    } catch (e) {
      $('#app').replaceChildren(el('div', { class: 'center-wrap' },
        el('div', { class: 'card' },
          el('p', { class: 'error', text: errorText(e) }),
          el('button', { type: 'button', class: 'primary', text: '↻', onclick: boot }))));
    }
  }

  boot();
})();
