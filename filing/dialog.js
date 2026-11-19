rcmail.addEventListener('init', function () {
    const command = 'plugin.civicrm-file';
    const t = key => rcmail.gettext(key, 'civicrm_addressbook');
    const style = document.createElement('style');
    style.textContent = `
        .toolbar a.civicrm-filed-mark::before {
            content: "";
            display: block;
            height: 1.75rem;
            background: var(--civicrm-logo) center / 18px 18px no-repeat;
        }
    `;
    document.head.append(style);
    function badge(anchor, status, inside = false) {
        if (!anchor) return;
        const existing = inside ? anchor.firstElementChild : anchor.previousElementSibling;
        const url = status?.id && rcmail.env.civicrm_activity_url ? new URL(rcmail.env.civicrm_activity_url) : null;
        if (url) {
            url.searchParams.set('action', 'view');
            url.searchParams.set('id', status.id);
            url.searchParams.set('cid', status.cid);
            url.searchParams.set('context', 'activity');
        }
        if (existing?.classList.contains('civicrm-filed-mark')) {
            if (status && existing.dataset.url === (url?.href || '')) return;
            existing.remove();
        }
        if (!status) return;
        const mark = document.createElement(url ? 'a' : 'span');
        mark.className = 'civicrm-filed-mark';
        mark.dataset.url = url?.href || '';
        mark.title = url ? t('openactivity') + ' #' + status.id : t('filedmark');
        if (url) {
            mark.href = url.href;
            mark.target = '_blank';
            mark.rel = 'noopener noreferrer';
            for (const event of ['mousedown', 'mouseup', 'click', 'dblclick', 'keydown']) {
                mark.addEventListener(event, e => e.stopPropagation());
            }
        }
        const image = document.createElement('img');
        image.src = rcmail.env.civicrm_logo;
        image.alt = mark.title;
        image.width = image.height = 18;
        image.style.verticalAlign = 'middle';
        mark.style.marginInlineEnd = '.35em';
        mark.append(image);
        if (anchor.closest('.toolbar') && url) {
            const caption = document.createElement('span');
            caption.className = 'inner';
            caption.textContent = t('activitylink');
            mark.setAttribute('aria-label', mark.title);
            mark.style.setProperty('--civicrm-logo', 'url("' + image.src + '")');
            mark.style.maxWidth = 'none';
            mark.replaceChildren(caption);
        } else if (anchor.closest('.toolbar')) {
            mark.style.display = 'flex';
            mark.style.flexDirection = 'column';
            mark.style.alignItems = 'center';
            mark.style.justifyContent = 'center';
            mark.style.float = 'left';
            mark.style.minWidth = '2rem';
            mark.style.height = 'inherit';
        }
        if (inside) anchor.prepend(mark);
        else anchor.before(mark);
    }
    const confirmed = new Map();
    const key = m => JSON.stringify([m._mbox, String(m._uid)]);
    function marker(m) {
        return confirmed.get(key(m)) || rcmail.env.civicrm_filed_rows?.[m._mbox]?.[m._uid];
    }
    function markRow(uid) {
        const row = rcmail.message_list?.rows[uid]?.obj || document.getElementById('rcmrow' + rcmail.html_identifier(uid, true));
        const subject = row?.querySelector('.subject');
        const link = subject?.querySelector('a:not(.civicrm-filed-mark)');
        badge(link || subject, marker(rcmail.params_from_uid(uid)), !link);
    }
    function syncToolbar() {
        const uid = rcmail.get_single_uid();
        const filed = uid && (marker(rcmail.params_from_uid(uid)) || (rcmail.env.uid && rcmail.env.civicrm_mail_filed));
        for (const button of rcmail.buttons[command] || []) {
            const anchor = document.getElementById(button.id);
            if (anchor?.closest('.toolbar')) anchor.style.maxWidth = 'none';
            badge(anchor, filed);
        }
    }
    function redraw() {
        for (const uid of Object.keys(rcmail.message_list?.rows || {})) markRow(uid);
        syncToolbar();
    }
    function remember(message) {
        confirmed.set(key(message), message.marker);
        redraw();
    }
    rcmail.addEventListener('plugin.civicrm-filed', remember);
    rcmail.addEventListener('listupdate', redraw);
    redraw();
    rcmail.addEventListener('insertrow', function (event) {
        if (event.uid == null) return;
        markRow(event.uid);
    });
    async function request(values) {
        const response = await fetch(rcmail.url(command), {
            method: 'POST',
            headers: {'X-Roundcube-Request': rcmail.env.request_token},
            body: new URLSearchParams({...values, _token: rcmail.env.request_token}),
        });
        if (!response.ok) throw new Error(t('requestfailed'));
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        return data;
    }

    rcmail.register_command(command, function () {
        const uid = rcmail.get_single_uid();
        if (!uid) return;
        const message = rcmail.params_from_uid(uid);
        const form = $('<div>');
        const subject = $('<strong>').appendTo(form);
        $('<p>').text(t('scope')).appendTo(form);
        const input = $('<input type="search" class="form-control">')
            .appendTo($('<label>').css('display', 'block').text(t('searchcontact')).appendTo(form));
        const results = $('<div role="radiogroup">').attr('aria-label', t('matches'))
            .css({maxHeight: '32vh', overflowY: 'auto'}).appendTo(form);
        const status = $('<p role="status">').appendTo(form);
        let revision = 0, timer, saving = false, closed = false;
        input.on('input', function () {
            const current = ++revision, term = input.val().trim();
            clearTimeout(timer);
            results.empty();
            saveButton.prop('disabled', true);
            status.text(t(term.length < 3 ? 'minchars' : 'searching'));
            if (term.length < 3) return;
            timer = setTimeout(async function () {
                try {
                    const data = await request({op: 'search', term});
                    if (current !== revision || closed) return;
                    for (const c of data.contacts) {
                        const row = $('<label>').css({display: 'flex', alignItems: 'center', gap: '.75rem',
                            padding: '.45rem', margin: 0, cursor: 'pointer'}).appendTo(results);
                        $('<input type="radio" name="civicrm-contact">').val(c.ID).appendTo(row);
                        const info = $('<span>').css('overflow-wrap', 'anywhere').appendTo(row);
                        $('<strong>').text(c.name).appendTo(info);
                        $('<small>').css('display', 'block').text(c.email + ' · CiviCRM #' + c.ID).appendTo(info);
                    }
                    status.text(t(data.contacts.length ? 'choosecontact' : 'nomatches'));
                } catch (error) {
                    if (current === revision && !closed) status.text(error.message);
                }
            }, 250);
        });
        const dialog = rcmail.simple_dialog(form, t('filemail'), function () {
            if (saving) return false;
            const contact = results.find('input:checked').val();
            if (!contact) {
                status.text(t('choosecontact'));
                return false;
            }
            saving = true;
            input.add(results.find('input')).add(saveButton).prop('disabled', true);
            status.text(t('saving'));
            request({...message, op: 'file', contact}).then(data => {
                const filed = {...message, marker: data.marker};
                remember(filed);
                for (const other of new Set([window.parent, window.opener])) {
                    try {
                        if (other && other !== window && other.location.origin === location.origin) {
                            other.rcmail?.triggerEvent('plugin.civicrm-filed', filed);
                        }
                    } catch { /* The opener may have navigated to another origin. */ }
                }
                rcmail.display_message(t(data.already ? 'alreadyfiled' : 'filed') + ' #' + data.id, 'confirmation');
                dialog.dialog('close');
            }).catch(error => {
                status.text(error.message);
                saving = false;
                input.add(results.find('input')).add(saveButton).prop('disabled', false);
            });
            return false;
        }, {button: t('filebutton'), button_class: 'save', width: 530, height: 500});
        const saveButton = dialog.dialog('widget').find('.ui-dialog-buttonpane .mainaction').prop('disabled', true);
        results.on('change', 'input', () => saveButton.prop('disabled', false));
        dialog.on('dialogclose', () => { closed = true; clearTimeout(timer); });
        status.text(t('minchars'));
        input.prop('disabled', true);
        request({...message, op: 'preview'}).then(data => {
            if (closed) return;
            input.prop('disabled', false).trigger('focus');
            subject.text(data.subject || t('nosubject'));
            if (!revision && data.sender) input.val(data.sender).trigger('input');
        }).catch(error => status.text(error.message));
    }, !!rcmail.get_single_uid());
    if (rcmail.message_list) {
        rcmail.message_list.addEventListener('select', () => {
            rcmail.enable_command(command, !!rcmail.get_single_uid());
            syncToolbar();
        });
    }
});
