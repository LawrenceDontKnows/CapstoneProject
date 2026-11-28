(function () {
    "use strict";

    if (window.__gvNotifStarted) return;
    window.__gvNotifStarted = true;

    var POLL_MS = 12000;
    var endpoint = window.location.pathname + "?ajax_notifications=1";
    var baselineId = null;
    var seen = {};

    var style = document.createElement('style');
    style.textContent = '@keyframes gvslide{from{opacity:0;transform:translateX(24px)}to{opacity:1;transform:none}}';
    document.head.appendChild(style);

    function badge() { return document.getElementById('notif-count-badge'); }
    function listEl() { return document.getElementById('notification-list'); }
    function senderName(n) {
        var f = n.sender_first || '', l = n.sender_last || '';
        return (f + ' ' + l).trim() || 'System';
    }
    function fmtDate(s) {
        try {
            var d = new Date((s || '').replace(' ', 'T'));
            if (isNaN(d.getTime())) return s || '';
            return d.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
        } catch (e) { return s || ''; }
    }
    function setBadge(n) {
        var b = badge();
        if (!b) return;
        b.textContent = n;
        if (n > 0) b.classList.remove('d-none');
        else b.classList.add('d-none');
    }
    function prependToList(n) {
        var list = listEl();
        if (!list) return;
        var emptyMsg = list.querySelector('.text-muted.text-center');
        if (emptyMsg) emptyMsg.parentNode.removeChild(emptyMsg);
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.className = 'dropdown-item text-wrap py-2 notification-item bg-light';
        a.href = '#';
        a.setAttribute('data-id', n.id);
        var p = document.createElement('p');
        p.className = 'mb-1 small fw-bold';
        p.textContent = n.message;
        var small = document.createElement('small');
        small.className = 'text-xs text-muted';
        small.textContent = 'From: ' + senderName(n) + ' \u2022 ' + fmtDate(n.created_at);
        a.appendChild(p);
        a.appendChild(small);
        li.appendChild(a);
        list.insertBefore(li, list.firstChild);
    }
    function popup(n) {
        var container = document.getElementById('gv-notif-popups');
        if (!container) {
            container = document.createElement('div');
            container.id = 'gv-notif-popups';
            container.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:1080;display:flex;flex-direction:column;gap:.5rem;max-width:360px;';
            document.body.appendChild(container);
        }
        var card = document.createElement('div');
        card.className = 'card shadow border-0';
        card.style.cssText = 'position:relative;border-left:4px solid #0d6efd !important;animation:gvslide .3s ease;';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.style.cssText = 'position:absolute;top:.4rem;right:.4rem;font-size:.6rem;';
        close.setAttribute('aria-label', 'Close');
        close.onclick = function () { card.parentNode.removeChild(card); };
        var body = document.createElement('div');
        body.className = 'card-body py-2 px-3 pe-8';
        var title = document.createElement('div');
        title.className = 'fw-bold small text-primary mb-1';
        title.textContent = 'New notification from ' + senderName(n);
        var msg = document.createElement('div');
        msg.className = 'small';
        msg.style.cssText = 'white-space:pre-wrap;word-break:break-word;';
        msg.textContent = n.message;
        body.appendChild(title);
        body.appendChild(msg);
        card.appendChild(close);
        card.appendChild(body);
        container.appendChild(card);
        setTimeout(function () {
            card.style.transition = 'opacity .4s';
            card.style.opacity = '0';
            setTimeout(function () { if (card.parentNode) card.parentNode.removeChild(card); }, 400);
        }, 9000);
    }
    function poll() {
        fetch(endpoint, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.notifications) return;
                var maxId = baselineId === null ? 0 : baselineId;
                data.notifications.forEach(function (n) {
                    var id = parseInt(n.id, 10);
                    if (baselineId === null) {
                        if (id > maxId) maxId = id;
                        return;
                    }
                    if (id > baselineId && !seen[id] && parseInt(n.is_read, 10) === 0) {
                        seen[id] = true;
                        popup(n);
                        prependToList(n);
                    }
                    if (id > maxId) maxId = id;
                });
                baselineId = maxId;
                setBadge(parseInt(data.unread_count, 10) || 0);
            })
            .catch(function () {});
    }
    function start() {
        poll();
        setInterval(poll, POLL_MS);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();