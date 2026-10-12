(() => {
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const POLL_MS = 4000;

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
    const listEl = $('#conv-list');
    const chatEl = $('#chat');
    const detailsEl = $('#details');
    const inboxEl = $('#inbox');

    const LABELS = JSON.parse(inboxEl.dataset.labels || '[]');
    const QUICK = JSON.parse(inboxEl.dataset.quickReplies || '[]');

    const state = {
        filter: '',
        label: '',
        page: '',
        q: '',
        conversations: [],
        activeId: null,
        active: null,
        counts: { total: 0, ai: 0 },
        lastMessageId: 0,
        chat: null,
        sending: false,
        detailsOpen: true,
        notesDirty: false,
    };

    // ---------- helpers ----------

    async function api(url, options = {}) {
        const res = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            ...options,
        });
        if (res.status === 401 || res.status === 419) {
            location.href = '/login';
            throw new Error('Session expired');
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || data.message || 'Request failed');
        return data;
    }

    const patch = body => api(`/inbox/conversations/${state.activeId}`, { method: 'PATCH', body: JSON.stringify(body) });

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function linkify(s) {
        return esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
    }

    function timeLabel(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        const now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
        }
        if (now - d < 6 * 864e5) return d.toLocaleDateString([], { weekday: 'short' });
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
    }

    function relative(iso) {
        if (!iso) return '–';
        const mins = Math.round((Date.now() - new Date(iso)) / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return `${mins} min ago`;
        if (mins < 1440) return `${Math.round(mins / 60)} h ago`;
        return `${Math.round(mins / 1440)} days ago`;
    }

    function hue(name) {
        let h = 0;
        for (const ch of String(name || '?')) h = (h * 31 + ch.codePointAt(0)) % 360;
        return h;
    }

    function initials(name) {
        const parts = String(name || '?').trim().split(/\s+/);
        return ((parts[0]?.[0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
    }

    function avatar(url, name, size = '') {
        const letter = `<span class="avatar avatar-letter ${size}" style="--h:${hue(name)}">${esc(initials(name))}</span>`;
        if (!url) return letter;
        return `<span class="avatar-stack ${size}">${letter}<img class="avatar ${size}" src="${esc(url)}" alt="" referrerpolicy="no-referrer" onerror="this.remove()"></span>`;
    }

    function setStats(stats) {
        for (const [key, value] of Object.entries(stats || {})) {
            $$(`[data-stat="${key}"]`).forEach(el => {
                el.textContent = value;
                if (el.classList.contains('badge') || el.classList.contains('count')) el.hidden = !value;
            });
        }
    }

    // ---------- conversation list ----------

    async function loadList() {
        const params = new URLSearchParams({ filter: state.filter, page: state.page, q: state.q, label: state.label });
        const data = await api(`/inbox/conversations?${params}`);
        state.conversations = data.conversations;
        setStats(data.stats);
        renderList();
    }

    function renderList() {
        if (!state.conversations.length) {
            listEl.innerHTML = `<li class="list-empty"><div class="empty-art small">📭</div><strong>No conversations</strong><span class="muted">${state.filter || state.label || state.q ? 'Try another filter.' : 'New messages will show up here.'}</span></li>`;
            return;
        }
        listEl.innerHTML = state.conversations.map(c => {
            const prefix = { customer: '', ai: '<span class="by-ai">AI:</span> ', agent: 'You: ', business_suite: 'You: ' }[c.last_message_from] ?? '';
            const tags = [
                c.needs_human ? '<span class="tag tag-human">Needs human</span>' : '',
                c.ad_id ? '<span class="tag tag-ad">Ad</span>' : '',
                ...(c.labels || []).map(l => `<span class="tag tag-label" data-label="${esc(l)}">${esc(l)}</span>`),
                !c.ai_active ? '<span class="tag tag-muted">AI off</span>' : '',
            ].join('');
            return `<li class="conv ${c.id === state.activeId ? 'active' : ''} ${c.unread_count ? 'unread' : ''}" data-id="${c.id}">
                <span class="avatar-wrap">${avatar(c.customer_pic, c.customer_name)}<span class="ch-badge ch-messenger"></span></span>
                <div class="conv-body">
                    <div class="conv-top"><strong>${esc(c.customer_name)}</strong><time>${timeLabel(c.last_message_at)}</time></div>
                    <div class="conv-text">${prefix}${esc(c.last_message_text || '')}</div>
                    <div class="conv-meta"><span class="page-name">${esc(c.page_name)}</span>${tags}</div>
                </div>
                ${c.unread_count ? `<span class="dot">${c.unread_count}</span>` : ''}
            </li>`;
        }).join('');
    }

    listEl.addEventListener('click', e => {
        const li = e.target.closest('.conv[data-id]');
        if (li) openConversation(Number(li.dataset.id));
    });

    $('#filters').addEventListener('click', e => {
        const chip = e.target.closest('button.chip');
        if (!chip) return;
        $$('#filters button.chip').forEach(c => c.classList.toggle('active', c === chip));
        state.filter = chip.dataset.filter;
        loadList();
    });

    $('#label-filter').addEventListener('change', e => {
        state.label = e.target.value;
        e.target.classList.toggle('active', !!state.label);
        loadList();
    });

    $('#page-filter').addEventListener('change', e => { state.page = e.target.value; loadList(); });

    $$('.channel[data-channel]').forEach(tab => tab.addEventListener('click', () => {
        $$('.channel[data-channel]').forEach(t => t.classList.toggle('active', t === tab));
    }));

    let searchTimer;
    $('#search').addEventListener('input', e => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { state.q = e.target.value; loadList(); }, 300);
    });

    // ---------- chat ----------

    async function openConversation(id) {
        if (state.notesDirty) await saveNotes();
        state.activeId = id;
        state.lastMessageId = 0;
        inboxEl.classList.add('show-chat');
        chatEl.innerHTML = '';
        chatEl.appendChild($('#chat-template').content.cloneNode(true));
        state.chat = {};
        $$('[data-ref]', chatEl).forEach(el => { state.chat[el.dataset.ref] = el; });
        bindChat();
        renderList();
        state.orders = [];
        history.replaceState(null, '', `#c${id}`);
        await loadMessages(true);
        loadOrders().catch(() => {});
        api(`/inbox/conversations/${id}/read`, { method: 'POST' }).then(loadList).catch(() => {});
        if (window.matchMedia('(min-width: 761px)').matches) state.chat.text.focus();
    }

    function bindChat() {
        const c = state.chat;
        c.ai.addEventListener('change', async () => {
            try {
                const data = await patch({ ai_enabled: c.ai.checked });
                renderConversation(data.conversation);
                loadList();
            } catch (err) { alert(err.message); }
        });
        c.composer.addEventListener('submit', e => { e.preventDefault(); send(); });
        c.text.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        });
        c.text.addEventListener('input', () => autosize(c.text));
        c.file.addEventListener('change', () => {
            if (c.file.files[0]) uploadFile(c.file.files[0]);
            c.file.value = '';
        });
        c.pickerSearch.addEventListener('input', renderPicker);
        state.products = null;
        c.quick.innerHTML = QUICK.map((q, i) => `<button type="button" class="quick" data-quick="${i}" title="${esc(q)}">${esc(q.length > 42 ? q.slice(0, 40) + '…' : q)}</button>`).join('');
        c.quick.hidden = !QUICK.length;
    }

    function autosize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    }

    // Bound once: the chat pane's content is replaced on every open.
    chatEl.addEventListener('click', async e => {
        const mediaBtn = e.target.closest('[data-send-media]');
        if (mediaBtn) return sendProductMedia([Number(mediaBtn.dataset.sendMedia)], mediaBtn);
        const allBtn = e.target.closest('[data-send-all]');
        if (allBtn) {
            const p = state.products.find(x => x.id === Number(allBtn.dataset.sendAll));
            return sendProductMedia(p.media.map(m => m.id), allBtn);
        }
        const detailsBtn = e.target.closest('[data-product-details]');
        if (detailsBtn) {
            const p = state.products.find(x => x.id === Number(detailsBtn.dataset.productDetails));
            const t = state.chat.text;
            t.value = [p.name + (p.price ? ` - ${p.price}` : ''), p.description].filter(Boolean).join('\n');
            autosize(t);
            t.focus();
            state.chat.picker.hidden = true;
            return;
        }
        const quick = e.target.closest('[data-quick]');
        if (quick) {
            const t = state.chat.text;
            t.value = QUICK[Number(quick.dataset.quick)];
            autosize(t);
            t.focus();
            return;
        }
        const action = e.target.closest('[data-action]')?.dataset.action;
        if (!action || !state.activeId) return;
        try {
            if (action === 'back') inboxEl.classList.remove('show-chat');
            if (action === 'details') toggleDetails();
            if (action === 'products') await togglePicker();
            if (action === 'new-order') await openOrderDialog();
            if (action === 'close-picker') state.chat.picker.hidden = true;
            if (action === 'done') {
                const data = await patch({ status: state.active.status === 'done' ? 'open' : 'done' });
                renderConversation(data.conversation);
                loadList();
            }
            if (action === 'flag' || action === 'resolve') {
                const data = await patch({ needs_human: action === 'flag' ? !state.active.needs_human : false });
                renderConversation(data.conversation);
                loadList();
            }
            if (action === 'suggest') await suggest(e.target.closest('button'));
        } catch (err) {
            alert(err.message);
        }
    });

    function toggleDetails(force) {
        state.detailsOpen = force ?? !state.detailsOpen;
        inboxEl.classList.toggle('details-closed', !state.detailsOpen);
        inboxEl.classList.toggle('details-open', state.detailsOpen);
    }

    function renderConversation(conv) {
        state.active = conv;
        const c = state.chat;
        c.name.textContent = conv.customer_name;
        c.page.textContent = `Messenger · ${conv.page_name}`;
        c.avatar.innerHTML = avatar(conv.customer_pic, conv.customer_name);
        c.ai.checked = conv.ai_active;
        c.switch.title = conv.ai_paused_until && !conv.ai_active
            ? `AI paused until ${new Date(conv.ai_paused_until).toLocaleString()} because a person replied`
            : 'Let the AI answer this customer automatically';
        c.switch.classList.toggle('paused', !!conv.ai_paused_until && !conv.ai_active);
        c.done.classList.toggle('on', conv.status === 'done');
        c.done.dataset.tip = conv.status === 'done' ? 'Reopen' : 'Mark as done';
        c.flag.classList.toggle('on-warn', conv.needs_human);
        c.human.hidden = !conv.needs_human;
        c.window.hidden = conv.within_window;
        if (conv.ad_id) {
            const ad = conv.ad_context || {};
            c.ad.hidden = false;
            c.ad.innerHTML = `${ad.photo_url ? `<img src="${esc(ad.photo_url)}" alt="" referrerpolicy="no-referrer">` : '<span class="ad-icon">📣</span>'}
                <div><strong>This chat contains a reply to an ad</strong><span class="muted">${esc(conv.ad_title || 'Ad ID ' + conv.ad_id)}</span></div>`;
        } else {
            c.ad.hidden = true;
        }
        renderDetails();
    }

    async function loadMessages(initial = false) {
        const id = state.activeId;
        const after = initial ? '' : `?after=${state.lastMessageId}`;
        const data = await api(`/inbox/conversations/${id}/messages${after}`);
        if (id !== state.activeId) return;
        state.counts = data.counts;
        renderConversation(data.conversation);
        appendMessages(data.messages, initial);
    }

    function appendMessages(messages, initial) {
        const box = state.chat.messages;
        const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
        if (initial) box.innerHTML = messages.length ? '' : '<div class="empty muted">No messages yet</div>';
        let lastDay = box.dataset.lastDay || '';
        let prev = box.dataset.prev || '';
        for (const m of messages) {
            if (m.id <= state.lastMessageId) continue;
            state.lastMessageId = m.id;
            const day = new Date(m.sent_at).toDateString();
            if (day !== lastDay) {
                box.insertAdjacentHTML('beforeend', `<div class="day"><span>${new Date(m.sent_at).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })}</span></div>`);
                lastDay = day;
                prev = '';
            }
            const key = m.direction + m.sender_type;
            box.insertAdjacentHTML('beforeend', messageHtml(m, key === prev));
            prev = key;
        }
        box.dataset.lastDay = lastDay;
        box.dataset.prev = prev;
        if (initial || atBottom) box.scrollTop = box.scrollHeight;
    }

    function messageHtml(m, grouped) {
        const out = m.direction === 'out';
        const who = {
            ai: '<span class="ai-chip">✨ AI</span>' + (m.ai_model ? ` <span class="muted">${esc(m.ai_model)}</span>` : ''),
            agent: `Sent by <strong>${esc(m.sender_name || 'agent')}</strong>`,
            business_suite: 'Sent from Business Suite',
        }[m.sender_type] || '';
        const atts = (m.attachments || []).map(a => {
            if (a.type === 'image' && a.url) return `<a href="${esc(a.url)}" target="_blank" rel="noopener"><img class="att-img" src="${esc(a.url)}" alt="image" referrerpolicy="no-referrer"></a>`;
            if (a.type === 'video' && a.url) return `<video class="att-img" src="${esc(a.url)}" controls preload="metadata"></video>`;
            if (a.url) return `<a class="att" href="${esc(a.url)}" target="_blank" rel="noopener">📎 ${esc(a.type)}</a>`;
            return `<span class="att">📎 ${esc(a.type)}</span>`;
        }).join('');
        const failed = m.status === 'failed';
        const pic = !out ? `<span class="msg-avatar">${grouped ? '' : avatar(state.active?.customer_pic, state.active?.customer_name, 'xs')}</span>` : '';
        return `<div class="msg ${out ? 'out' : 'in'} ${m.sender_type} ${failed ? 'failed' : ''} ${grouped ? 'grouped' : ''}">
            ${pic}
            <div class="msg-col">
                ${m.text ? `<div class="bubble" title="${esc(new Date(m.sent_at).toLocaleString())}">${linkify(m.text)}</div>` : ''}
                ${atts}
                <div class="meta">${who}${who ? ' · ' : ''}${timeLabel(m.sent_at)}${failed ? ` · <span class="error">⚠ ${esc(m.error || 'Not sent')}</span>` : ''}</div>
            </div>
        </div>`;
    }

    async function send() {
        const text = state.chat.text.value.trim();
        if (!text || state.sending) return;
        state.sending = true;
        state.chat.text.disabled = true;
        try {
            await api(`/inbox/conversations/${state.activeId}/reply`, { method: 'POST', body: JSON.stringify({ text }) });
            state.chat.text.value = '';
            autosize(state.chat.text);
            await loadMessages();
            loadList();
        } catch (err) {
            alert(err.message);
        } finally {
            state.sending = false;
            state.chat.text.disabled = false;
            state.chat.text.focus();
        }
    }

    async function suggest(btn) {
        const label = btn.textContent;
        btn.disabled = true;
        btn.classList.add('loading');
        btn.textContent = '✨ Writing…';
        try {
            const data = await api(`/inbox/conversations/${state.activeId}/suggest`, { method: 'POST' });
            state.chat.text.value = data.text;
            autosize(state.chat.text);
            state.chat.text.focus();
        } finally {
            btn.disabled = false;
            btn.classList.remove('loading');
            btn.textContent = label;
        }
    }

    // ---------- products & media ----------

    async function togglePicker() {
        const c = state.chat;
        c.picker.hidden = !c.picker.hidden;
        if (c.picker.hidden) return;
        if (!state.products) {
            c.pickerList.innerHTML = '<div class="picker-empty muted">Loading products…</div>';
            const data = await api(`/inbox/conversations/${state.activeId}/products`);
            state.products = data.products;
        }
        renderPicker();
        c.pickerSearch.focus();
    }

    function renderPicker() {
        const c = state.chat;
        const q = c.pickerSearch.value.trim().toLowerCase();
        const items = (state.products || []).filter(p => !q || p.name.toLowerCase().includes(q));
        if (!items.length) {
            c.pickerList.innerHTML = `<div class="picker-empty muted">${state.products?.length ? 'No match.' : 'No products for this page yet. Add them under Products.'}</div>`;
            return;
        }
        c.pickerList.innerHTML = items.map(p => `
            <div class="picker-item">
                <div class="picker-info">
                    <strong>${esc(p.name)}</strong>
                    ${p.price ? `<span class="price">${esc(p.price)}</span>` : ''}
                </div>
                <div class="picker-media">
                    ${p.media.map(m => `<button type="button" class="thumb" data-send-media="${m.id}" title="Send this ${m.type}">
                        ${m.type === 'video' ? `<video src="${esc(m.url)}#t=0.5" muted preload="metadata"></video><span class="play">▶</span>` : `<img src="${esc(m.url)}" alt="" loading="lazy">`}
                        <span class="thumb-send">Send</span>
                    </button>`).join('') || '<span class="muted small">No photos</span>'}
                </div>
                <div class="picker-actions">
                    ${p.media.length > 1 ? `<button type="button" class="btn btn-ghost btn-sm" data-send-all="${p.id}">Send all ${p.media.length}</button>` : ''}
                    <button type="button" class="btn btn-ghost btn-sm" data-product-details="${p.id}">Add details to reply</button>
                </div>
            </div>`).join('');
    }

    async function sendProductMedia(ids, btn) {
        if (!ids.length) return;
        btn.disabled = true;
        btn.classList.add('sending');
        try {
            for (const id of ids) {
                await api(`/inbox/conversations/${state.activeId}/product-media`, { method: 'POST', body: JSON.stringify({ media_id: id }) });
            }
            await loadMessages();
            loadList();
        } catch (err) {
            alert(err.message);
        } finally {
            btn.disabled = false;
            btn.classList.remove('sending');
        }
    }

    async function uploadFile(file) {
        if (file.size > 25 * 1048576) return alert('The file is larger than 25 MB, which Messenger does not accept.');
        const c = state.chat;
        c.uploading.hidden = false;
        c.uploading.textContent = `Uploading ${file.name}…`;
        const form = new FormData();
        form.append('file', file);
        try {
            const res = await fetch(`/inbox/conversations/${state.activeId}/upload`, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                credentials: 'same-origin',
                body: form,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || data.error || 'Upload failed');
            await loadMessages();
            loadList();
        } catch (err) {
            alert(err.message);
        } finally {
            c.uploading.hidden = true;
        }
    }

    // ---------- orders ----------

    const orderDialog = $('#order-dialog');
    const orderForm = $('#order-dialog-form');
    let orderItems = null;

    async function loadProducts() {
        if (!state.products) {
            const data = await api(`/inbox/conversations/${state.activeId}/products`);
            state.products = data.products;
        }
        return state.products;
    }

    async function loadOrders() {
        const id = state.activeId;
        const data = await api(`/inbox/conversations/${id}/orders`);
        if (id !== state.activeId) return;
        state.orders = data.orders;
        renderDetails(true);
    }

    /** Pull a phone number and address out of the team's notes, if they were written there. */
    function fromNotes(notes) {
        const text = String(notes || '');
        const phone = text.match(/(?:\+?977[-\s]?)?(9[678]\d{8}|0\d{6,9})/);
        const address = text.match(/address\s*[:\-]\s*(.+)/i);
        return { phone: phone ? phone[1] : '', address: address ? address[1].trim() : '' };
    }

    async function openOrderDialog() {
        const products = await loadProducts();
        if (!orderItems) {
            orderItems = OrderItems.mount(orderForm, {
                products: [],
                currency: window.ORDER_CURRENCY,
                pageId: () => state.active?.page_id,
            });
        }
        orderItems.setProducts(products);
        orderForm.reset();
        orderItems.reset();
        const conv = state.active;
        const found = fromNotes(conv.notes);
        orderForm.customer_name.value = conv.customer_name === 'Facebook user' ? '' : conv.customer_name;
        orderForm.phone.value = found.phone;
        orderForm.address.value = found.address;
        orderForm.send_confirmation.checked = conv.within_window;
        orderForm.send_confirmation.disabled = !conv.within_window;
        $('[data-order-error]', orderForm).hidden = true;
        orderItems.recalc();
        orderDialog.showModal();
        (orderForm.customer_name.value ? orderForm.phone : orderForm.customer_name).focus();
    }

    orderDialog.addEventListener('click', e => {
        if (e.target.closest('[data-close-dialog]') || e.target === orderDialog) orderDialog.close();
    });

    orderForm.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(orderForm);
        const body = { items: [] };
        for (const [key, value] of fd.entries()) {
            const m = key.match(/^items\[(\d+)\]\[(\w+)\]$/);
            if (m) {
                body.items[m[1]] ??= {};
                body.items[m[1]][m[2]] = value === '' ? null : value;
            } else {
                body[key] = value;
            }
        }
        body.items = body.items.filter(Boolean);
        body.send_confirmation = orderForm.send_confirmation.checked;

        const btn = $('[data-order-submit]', orderForm);
        const err = $('[data-order-error]', orderForm);
        btn.disabled = true;
        err.hidden = true;
        try {
            const res = await fetch(`/inbox/conversations/${state.activeId}/orders`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'Could not save the order');
            orderDialog.close();
            if (data.confirmation && data.confirmation.status !== 'sent') {
                alert(`Order ${data.order.number} saved, but the message to the customer was not sent:\n${data.confirmation.error}`);
            }
            await Promise.all([loadOrders(), loadMessages(), loadList()]);
        } catch (error) {
            err.textContent = error.message;
            err.hidden = false;
        } finally {
            btn.disabled = false;
        }
    });

    function ordersSection() {
        const orders = state.orders || [];
        const list = orders.map(o => `
            <a class="mini-order" href="${esc(o.url)}">
                <span><strong>${esc(o.number)}</strong> <span class="muted">${esc(o.items)}</span></span>
                <span class="mini-order-right"><strong>${esc(o.total)}</strong><span class="status-badge s-${esc(o.status)}"><span class="status-dot s-${esc(o.status)}"></span>${esc(o.status_label)}</span></span>
            </a>`).join('');
        return `
            <div class="d-section">
                <h4>Orders <span class="muted">${orders.length || ''}</span></h4>
                ${list || '<p class="muted small" style="margin-bottom:8px">No orders yet.</p>'}
                <button type="button" class="btn btn-soft btn-sm btn-block" data-details-order style="margin-top:8px">🧾 Create order</button>
            </div>`;
    }

    // ---------- details panel ----------

    function renderDetails() {
        const conv = state.active;
        if (!conv) return;
        detailsEl.hidden = false;
        // Don't re-render while the user is typing a note.
        if (detailsEl.contains(document.activeElement) && document.activeElement.tagName === 'TEXTAREA') {
            return;
        }
        const scroll = detailsEl.scrollTop;
        const ad = conv.ad_context || {};
        const aiState = conv.ai_active
            ? '<span class="state state-on">Answering automatically</span>'
            : (conv.ai_paused_until ? `<span class="state state-paused">Paused until ${new Date(conv.ai_paused_until).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>` : '<span class="state state-off">Off for this chat</span>');

        detailsEl.innerHTML = `
            <button class="icon-btn details-close" data-close-details aria-label="Close">✕</button>
            <div class="profile">
                ${avatar(conv.customer_pic, conv.customer_name, 'xl')}
                <h3>${esc(conv.customer_name)}</h3>
                <span class="muted"><span class="ch-icon ch-messenger"></span> Messenger customer</span>
            </div>

            <div class="d-section">
                <h4>Conversation</h4>
                <dl class="facts">
                    <dt>Page</dt><dd>${esc(conv.page_name)}</dd>
                    <dt>First message</dt><dd>${conv.created_at ? new Date(conv.created_at).toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' }) : '–'}</dd>
                    <dt>Last customer message</dt><dd>${relative(conv.last_customer_message_at ?? conv.last_message_at)}</dd>
                    <dt>Messages</dt><dd>${state.counts.total} <span class="muted">(${state.counts.ai} by AI)</span></dd>
                    <dt>Reply window</dt><dd>${conv.within_window ? '<span class="state state-on">Open</span>' : '<span class="state state-off">Closed (24 h)</span>'}</dd>
                </dl>
            </div>

            <div class="d-section">
                <h4>AI assistant</h4>
                <p>${aiState}</p>
            </div>

            ${conv.ad_id ? `
            <div class="d-section">
                <h4>Came from ad</h4>
                <div class="ad-card">
                    ${ad.photo_url ? `<img src="${esc(ad.photo_url)}" alt="" referrerpolicy="no-referrer">` : ''}
                    <div><strong>${esc(conv.ad_title || 'Facebook ad')}</strong><span class="muted">Ad ID ${esc(conv.ad_id)}</span></div>
                </div>
            </div>` : ''}

            ${ordersSection()}

            <div class="d-section">
                <h4>Labels</h4>
                <div class="label-picker">
                    ${LABELS.map(l => `<button type="button" class="label-toggle ${(conv.labels || []).includes(l) ? 'on' : ''}" data-label="${esc(l)}">${esc(l)}</button>`).join('')}
                </div>
            </div>

            <div class="d-section">
                <h4>Notes <span class="save-state muted" data-save-state></span></h4>
                <textarea class="notes" rows="5" placeholder="Phone, address, order details… (only your team sees this)">${esc(conv.notes || '')}</textarea>
            </div>`;
        detailsEl.scrollTop = scroll;
    }

    detailsEl.addEventListener('click', async e => {
        if (e.target.closest('[data-close-details]')) return toggleDetails(false);
        if (e.target.closest('[data-details-order]')) return openOrderDialog().catch(err => alert(err.message));
        const btn = e.target.closest('.label-toggle');
        if (!btn) return;
        btn.classList.toggle('on');
        const labels = $$('.label-toggle.on', detailsEl).map(b => b.dataset.label);
        try {
            const data = await patch({ labels });
            state.active = data.conversation;
            loadList();
        } catch (err) {
            btn.classList.toggle('on');
            alert(err.message);
        }
    });

    let notesTimer;
    detailsEl.addEventListener('input', e => {
        if (!e.target.classList.contains('notes')) return;
        state.notesDirty = true;
        $('[data-save-state]', detailsEl).textContent = 'Editing…';
        clearTimeout(notesTimer);
        notesTimer = setTimeout(saveNotes, 800);
    });
    detailsEl.addEventListener('focusout', e => { if (e.target.classList.contains('notes') && state.notesDirty) saveNotes(); });

    async function saveNotes() {
        clearTimeout(notesTimer);
        const area = $('.notes', detailsEl);
        if (!area || !state.notesDirty) return;
        state.notesDirty = false;
        const label = $('[data-save-state]', detailsEl);
        try {
            const data = await patch({ notes: area.value });
            state.active.notes = data.conversation.notes;
            if (label) label.textContent = 'Saved ✓';
        } catch (err) {
            if (label) label.textContent = 'Not saved';
        }
    }

    // ---------- polling ----------

    async function poll() {
        if (document.hidden) return;
        try {
            await loadList();
            if (state.activeId) await loadMessages();
        } catch (e) {
            console.warn(e);
        }
    }

    toggleDetails(window.matchMedia('(min-width: 1200px)').matches);
    // Links like /#c42 (e.g. "Open chat" on an order) open that conversation.
    const linked = location.hash.match(/^#c(\d+)$/);
    if (linked) openConversation(Number(linked[1])).catch(e => alert(e.message));
    loadList().catch(e => { listEl.innerHTML = `<li class="list-empty error">${esc(e.message)}</li>`; });
    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
