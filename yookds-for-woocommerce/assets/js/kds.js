/* YooKDS: server-confirmed state; no customer data or pending writes in localStorage. */
(function () {
    'use strict';
    const printedOrders = new Set();
    function endpoint(base, path, params = {}) {
        const url = new URL(base);
        if (url.searchParams.has('rest_route')) {
            url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + '/' + path);
        } else {
            url.pathname = url.pathname.replace(/\/$/, '') + '/' + path;
        }
        for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value));
        return url.toString();
    }
    function validateFeed(data, boot, readId, active) {
        if (!data || data.source!=='woocommerce' || data.schema!==2 || data.site!==boot.site || data.version!==boot.version || data.read_id!==readId) {
            throw new Error('Fel eller inaktuellt ordersvar. Kontrollera att du öppnat YooKDS i rätt WordPress-butik och rensa sidcachen.');
        }
        if (!Array.isArray(data.orders) || !Number.isFinite(data.server_time)) throw new Error('Ofullständigt WooCommerce-svar.');
        const ids=new Set();
        for (const row of data.orders) {
            if (!row || !Number.isSafeInteger(row.id) || (row.id===0 || (row.id<0 && (!['wolt','foodora'].includes(row.provider) || row.kind!=='external'))) || ids.has(row.id) ||
                !Array.isArray(row.items) || !Array.isArray(row.shipping) || typeof row.order_number!=='string' ||
                !Number.isInteger(row.revision) || typeof row.token!=='string') throw new Error('Ogiltig eller duplicerad WooCommerce-order. Inga åtgärder tillåts.');
            ids.add(row.id);
            if (active && (!(row.kind==='external' ? row.woo_status==='processing' : boot.settings.receive_statuses.includes(row.woo_status)) || !['preparing','ready','blocked'].includes(row.state))) {
                throw new Error('Servern försökte visa en stängd order i den aktiva vyn. Ladda om köksskärmen.');
            }
            for (const item of row.items) if (!Array.isArray(item.details)) throw new Error('Orderns tillval kunde inte läsas.');
        }
        return data.orders;
    }
    function parseLines(text, keys) {
        return String(text || '').split(/\r?\n/).map(line=>line.trim()).filter(Boolean).map(line=>{
            const values=line.split('|').map(value=>value.trim());
            if (values.length!==keys.length || values.some(value=>!value)) throw new Error('Varje rad ska innehålla '+keys.length+' värden, separerade med |.');
            return Object.fromEntries(keys.map((key,index)=>[key,values[index]]));
        });
    }
    function nxLink(base, values) {
        const url=new URL(base);
        if (!['https:','http:'].includes(url.protocol)) throw new Error('Ange en vanlig webbadress som börjar med https://.');
        ['channel','station','table','mode'].forEach(key=>{
            const value=String(values[key] || '').trim();
            if (value) url.searchParams.set('nx_'+key,value); else url.searchParams.delete('nx_'+key);
        });
        if (values.table && !values.channel) url.searchParams.set('nx_channel','table');
        return url.toString();
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { endpoint, validateFeed, parseLines, nxLink };
    if (typeof document === 'undefined') return;

    function start() {
        const root = document.getElementById('kdsu-root');
        const boot = window.YooKDSBoot;
        if (!root || !boot || root.dataset.mounted) return;
        root.dataset.mounted = '1';
        const slot = name => root.querySelector(`[data-slot="${name}"]`);
        const control = name => root.querySelector(`[data-ui="${name}"]`);
        const S = {
            view: root.dataset.initial === 'archive' ? 'archive' : 'board',
            channel: '', origin: '', rows: new Map(), page: 1, max: 1, range: 'today', q: '',
            connected: false, lastSync: 0, serverDelta: 0, poll: boot.settings.poll_seconds,
            generation: 0, reading: null, mutating: false, timer: null, failures: 0, stopped: false,
            readError: false, settings: boot.settings, sound: false, audio: null, seen: null, incomplete: false,
        };
        function el(tag, className, text) {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = String(text);
            return node;
        }
        function error(message) { slot('error').textContent = message; slot('error').hidden = !message; }
        function channelLabel(value) {
            const configured=S.settings.channels?.find(channel=>channel.id===value);
            if (configured) return configured.name;
            return ({ foodora: 'foodora', wolt: 'Wolt', ubereats: 'Uber Eats', web: 'Online', express: 'Express', table: 'QR / Bord', woocommerce: 'WooCommerce' })[value] || value || 'WooCommerce';
        }
        function clock(epoch) {
            if (!epoch) return '—';
            try { return new Date(epoch * 1000).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit', timeZone: boot.timezone }); }
            catch (_) { return new Date(epoch * 1000).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' }); }
        }
        function stale() { return !S.connected || Date.now() - S.lastSync > Math.max(15000, S.poll * 3000); }
        function connectivity() {
            const bad = stale();
            slot('connection').textContent = bad ? (S.stopped ? 'Sessionen har gått ut — ladda om och logga in' : 'Inte synkroniserad — åtgärder är låsta') : (S.incomplete ? 'Delvis synkroniserad — vissa ordrar behöver kontrolleras' : 'Köksvyn är uppdaterad');
            slot('connection').dataset.ok = String(!bad && !S.incomplete);
            slot('last-sync').textContent = S.lastSync ? 'Senast bekräftat ' + clock((S.lastSync + S.serverDelta) / 1000) : '';
            root.querySelectorAll('[data-command], [data-print]').forEach(button => { button.disabled = bad || S.mutating || button.dataset.locked==='true'; });
        }
        async function request(path, params = {}, body = null, signal = undefined) {
            const response = await fetch(endpoint(boot.rest, path, params), {
                method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal,
                headers: { 'X-WP-Nonce': boot.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
                ...(body ? { body: JSON.stringify(body) } : {}),
            });
            const nonce = response.headers.get('X-WP-Nonce');
            if (nonce) boot.nonce = nonce;
            let data;
            try { data = await response.json(); }
            catch (_) { throw new Error('Servern gav inte ett giltigt svar. Vyn kan vara inaktuell.'); }
            if (!response.ok) {
                if ([401, 403].includes(response.status)) S.stopped = true;
                const err = new Error(typeof data.message === 'string' ? data.message : 'Servern kunde inte bekräfta åtgärden.');
                err.status = response.status;
                throw err;
            }
            return data;
        }
        function action(label, command, row, secondary = false) {
            const button = el('button', secondary ? 'secondary' : 'primary', label);
            button.type = 'button'; button.dataset.command = command; button.dataset.orderId = row.id;
            return button;
        }
        function card(row) {
            const node = el('article', 'kdsu-card ' + (row.state === 'blocked' ? 'is-blocked' : ''));
            node.dataset.id = row.id;
            const channel=S.settings.channels?.find(channel=>channel.id===row.channel);
            if (channel && /^#[0-9a-f]{6}$/i.test(channel.color)) node.style.setProperty('--order-accent',channel.color);
            if (row.kind==='external' && ['foodora','wolt'].includes(row.provider)) {
                node.dataset.provider=row.provider;
                const source=el('div','kdsu-source');source.append(el('strong','',channelLabel(row.provider)),el('span','','EXTERN BESTÄLLNING'));node.append(source);
            }
            const badges = el('div', 'kdsu-badges');
            badges.append(el('span', 'kdsu-badge', channelLabel(row.channel)));
            if (row.origin) badges.append(el('span', 'kdsu-badge secondary', 'Kassa ' + row.origin));
            if (row.table) badges.append(el('span', 'kdsu-badge secondary', 'Bord ' + row.table));
            for (const shipping of row.shipping) badges.append(el('span', 'kdsu-badge secondary', shipping.label));
            node.append(badges);
            const title = el('div', 'kdsu-card-title');
            title.append(el('h3', '', '#' + row.order_number), el('time', '', clock(row.created_at)));
            node.append(title);
            if (row.state!=='archived') {const age=el('div','kdsu-order-age');age.dataset.created=row.created_at;node.append(age);}
            if (row.due_at) node.append(el('p','kdsu-due','Hämtning '+clock(row.due_at)));
            if (row.kind==='external' && !row.accepted && row.state!=='archived') node.append(el('p','kdsu-alert','Acceptera ordern i '+channelLabel(row.provider)+'s handlarapp.'));
            if (row.integration_error || row.integration_pending) node.append(el('p','kdsu-alert','Inväntar uppdatering från leveranstjänsten. Åtgärder är tillfälligt låsta.'));
            if (row.state === 'blocked') node.append(el('p', 'kdsu-alert', row.reason==='unreadable_fields' ? 'STOPPAD · Tillval kunde inte läsas fullständigt. Kontrollera WooCommerce-ordern.' : 'STOPPAD · Ordern saknar läsbara orderrader. Kontrollera WooCommerce.'));
            if (row.kind!=='external' && !row.payment_recorded && row.state!=='archived') node.append(el('p','kdsu-payment','Betalning inte bekräftad i WooCommerce'));
            if (row.changed && row.state !== 'blocked') node.append(el('p', 'kdsu-alert', 'Ändrad eller återställd order — kontrollera hela innehållet.'));
            if (Number(row.refunded_total) > 0) node.append(el('p', 'kdsu-alert', 'Återbetalt: ' + row.refunded_total + ' ' + row.currency + '. Kontrollera vad som ska tillagas.'));
            const items = el('ul', 'kdsu-items');
            for (const item of row.items) {
                const li = el('li', '');
                li.append(el('strong', '', item.quantity + ' × ' + item.name));
                for (const warning of item.field_warnings || []) li.append(el('p','kdsu-alert',warning));
                for (const detail of item.details) li.append(el('div', 'kdsu-addon', detail.label + ': ' + detail.value));
                if (Number(item.refunded_quantity) > 0) li.append(el('div', 'kdsu-alert', 'Återbetalat antal: ' + item.refunded_quantity));
                items.append(li);
            }
            node.append(items);
            for (const detail of row.custom_fields || []) node.append(el('div','kdsu-order-field',detail.label+': '+detail.value));
            if (row.courier_notice) node.append(el('p','kdsu-alert',row.courier_notice));
            if (row.note) node.append(el('div', 'kdsu-note', row.note));
            const actions = el('div', 'kdsu-actions');
            if (row.state === 'blocked') actions.append(el('small','','Åtgärda eller avbryt ordern i WooCommerce.'));
            else if (row.state === 'preparing') actions.append(action(row.changed ? 'Bekräfta ändring' : 'Klar', row.changed ? 'acknowledge' : 'ready', row));
            else if (row.state === 'ready') {
                actions.append(action(row.kind==='external'?'Utlämnad i KDS':'Utlämnad', 'handover', row));
                if(row.kind!=='external') actions.append(action('Tillbaka', 'restore', row, true));
            } else if (row.state === 'archived' && row.can_restore) actions.append(action('Återställ', 'restore', row, true));
            else if (row.state === 'archived') actions.append(el('small', '', 'Återöppna i WooCommerce för att återställa till köket.'));
            const print = el('button', 'kdsu-print secondary', 'Skriv ut');
            print.type = 'button'; print.dataset.print = row.id;
            actions.append(print); node.append(actions);
            const times = [(row.kind==='external' ? channelLabel(row.provider)+': ' : 'Woo: ') + (row.woo_status_label || row.woo_status), (row.kind==='external' ? 'Externt ID '+row.external_id : 'ID '+row.id)];
            if (row.ready_at) times.push('Klar ' + clock(row.ready_at));
            if (row.closed_at) times.push('Arkiv ' + clock(row.closed_at));
            node.append(el('div', 'kdsu-times', times.join(' · ')));
            try {
                const url=new URL(row.order_url);
                if (url.origin===new URL(boot.site).origin && ['https:','http:'].includes(url.protocol)) {
                    const link=el('a','kdsu-order-link','Öppna WooCommerce-order');
                    link.href=url.href; link.target='_blank'; link.rel='noopener noreferrer'; node.append(link);
                }
            } catch (_) { /* Missing link never creates a guessed order URL. */ }
            if(row.integration_error || row.integration_pending) node.querySelectorAll('[data-command], [data-print]').forEach(b=>{b.dataset.locked='true';b.disabled=true;});
            if(row.kind==='external' && !row.accepted) node.querySelectorAll('[data-command="ready"]').forEach(b=>{b.dataset.locked='true';b.disabled=true;});
            return node;
        }
        function ages() {
            root.querySelectorAll('[data-created]').forEach(node=>{
                const minutes=Math.max(0,Math.floor(((Date.now()+S.serverDelta)/1000-Number(node.dataset.created))/60));
                node.textContent=minutes<1?'Ny beställning':minutes+' min i köket';node.dataset.late=String(minutes>=20);
            });
        }
        // Reuse unchanged DOM nodes: no flashing cards, lost focus or scroll reset on each poll.
        const rendered = new WeakMap();
        function renderList(container, rows) {
            const cache = rendered.get(container) || new Map();
            const wanted = new Set(rows.map(row => row.id));
            for (const [id, saved] of cache) if (!wanted.has(id)) { saved.node.remove(); cache.delete(id); }
            container.querySelectorAll('.kdsu-empty').forEach(node => node.remove());
            rows.forEach((row, index) => {
                const signature = JSON.stringify([row, S.settings.channels]);
                let saved = cache.get(row.id);
                if (!saved || saved.signature !== signature) {
                    const node = card(row);
                    if (saved) saved.node.replaceWith(node);
                    saved = { signature, node }; cache.set(row.id, saved);
                }
                if (container.children[index] !== saved.node) container.insertBefore(saved.node, container.children[index] || null);
            });
            if (!rows.length) container.append(el('p', 'kdsu-empty', 'Inga beställningar i den här vyn.'));
            rendered.set(container, cache);
        }
        function renderBoard(rows) {
            const prep = rows.filter(row => ['preparing', 'blocked'].includes(row.state));
            const ready = rows.filter(row => row.state === 'ready');
            const stats = slot('stats'); stats.replaceChildren();
            for (const [label, value] of [['Aktiva', rows.length], ['Klara', ready.length], ['Stoppade', rows.filter(r => r.state === 'blocked').length]]) {
                const box = el('div', 'kdsu-stat'); box.append(el('span', '', label), el('strong', '', value)); stats.append(box);
            }
            const channels = [...new Set(rows.map(row => row.channel))];
            const filters = slot('filters');
            const signature = JSON.stringify([channels, S.channel, rows.map(r => r.channel), S.settings.channels]);
            if (filters.dataset.signature !== signature) {
                filters.replaceChildren();
                for (const ch of ['', ...channels]) {
                    const count = ch ? rows.filter(r => r.channel === ch).length : rows.length;
                    const button = el('button', 'kdsu-filter' + (ch === S.channel ? ' selected' : ''), (ch ? channelLabel(ch) : 'Alla') + ' · ' + count);
                    button.type = 'button'; button.dataset.channel = ch; button.setAttribute('aria-pressed', String(ch === S.channel)); filters.append(button);
                }
                // Keep an explicitly selected missing channel so an empty filter is not mistaken for no orders.
                if (S.channel && !channels.includes(S.channel)) {
                    const button = el('button', 'kdsu-filter selected', channelLabel(S.channel) + ' · 0'); button.dataset.channel = S.channel; button.type = 'button'; filters.append(button);
                }
                filters.dataset.signature = signature;
            }
            const originBox=slot('origins');
            const origins=[...new Set(rows.map(row=>row.origin).filter(Boolean))];
            if (originBox) {
                const signature=JSON.stringify([origins,S.origin,rows.map(row=>row.origin)]);
                if (originBox.dataset.signature!==signature) {
                    originBox.replaceChildren();
                    if (origins.length || S.origin) {
                        originBox.append(el('span','kdsu-filter-label','Kassa / ursprung:'));
                        for (const origin of ['',...new Set([...origins,...(S.origin?[S.origin]:[])])]) {
                            const count=origin?rows.filter(row=>row.origin===origin).length:rows.length;
                            const b=el('button','kdsu-filter'+(S.origin===origin?' selected':''),(origin || 'Alla')+' · '+count);
                            b.type='button'; b.dataset.origin=origin; originBox.append(b);
                        }
                    }
                    originBox.dataset.signature=signature;
                }
            }
            const visible = list => list.filter(row => (!S.channel || row.channel === S.channel) && (!S.origin || row.origin === S.origin));
            slot('prep-count').textContent = visible(prep).length;
            slot('ready-count').textContent = visible(ready).length;
            renderList(slot('preparing'), visible(prep)); renderList(slot('ready'), visible(ready));
        }
        function schedule() {
            clearTimeout(S.timer);
            if (!S.stopped && !document.hidden) S.timer = setTimeout(refresh, Math.min(60000, S.poll * 1000 * 2 ** Math.min(S.failures, 4)));
        }
        async function refresh() {
            if (S.mutating || S.stopped) return;
            clearTimeout(S.timer);
            if (S.reading) S.reading.abort();
            const generation = ++S.generation;
            const controller = new AbortController(); S.reading = controller;
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const readId=requestId();
                const params={read_id:readId, _ts:Date.now(), ...(S.view==='archive'?{range:S.range,q:S.q,page:S.page}:{})};
                const data = await request(S.view === 'archive' ? 'archive' : 'orders', params, null, controller.signal);
                if (generation !== S.generation) return;
                validateFeed(data,boot,readId,S.view==='board');
                S.rows = new Map(data.orders.map(row => [row.id, row]));
                if (S.readError) { error(''); S.readError = false; }
                S.incomplete=data.complete===false; S.connected = true; S.lastSync = Date.now(); S.serverDelta = data.server_time * 1000 - S.lastSync; S.failures = 0;
                if (S.view === 'board') {
                    S.poll = data.poll_seconds || S.poll;
                    slot('backfill').hidden = !data.backfill_remaining && !data.issues?.length;
                    slot('backfill').textContent = data.issues?.length ? 'Vyn är ofullständig. '+data.issues.length+' orderproblem: '+data.issues.map(i=>(i.id>0?'Woo #'+i.id:i.id<0?'Extern #'+Math.abs(i.id):'Integration')+' ('+i.code+')').join(', ')+'. Läsbara ordrar visas nedan.' : `Läser in tidigare ordrar: ${data.backfill_remaining || 0} återstår. Vyn är ännu inte komplett.`;
                    renderBoard(data.orders);
                    const ids = new Set(data.orders.map(row => row.id));
                    if (S.sound && S.seen && [...ids].some(id => !S.seen.has(id))) beep();
                    S.seen = ids; ages();
                } else {
                    S.page = data.page; S.max = data.max_pages;
                    renderList(slot('archive'), data.orders);
                    if(slot('external-archive')) {
                        const external=await request('external/archive',{range:S.range,q:S.q});
                        if(generation!==S.generation)return;
                        renderList(slot('external-archive'),external.orders);
                        external.orders.forEach(row=>S.rows.set(row.id,row));
                        if(external.truncated)error('Extern historik visar de senaste 500 ordrarna. Välj en kortare period.');
                    }
                    slot('page').textContent = `Sida ${S.page} av ${S.max} · ${data.total} ordrar`;
                    control('prev').disabled = S.page <= 1; control('next').disabled = S.page >= S.max;
                }
            } catch (err) {
                if (generation !== S.generation) return;
                S.connected = false; S.failures++; S.readError = true;
                error(err.name === 'AbortError' ? 'Servern svarade inte i tid. Uppgifterna kan vara inaktuella.' : err.message);
            } finally {
                clearTimeout(timeout);
                if (generation === S.generation) { S.reading = null; connectivity(); schedule(); }
            }
        }
        function view() {
            root.querySelector('[data-view="board"]').hidden = S.view !== 'board';
            root.querySelector('[data-view="archive"]').hidden = S.view !== 'archive';
            if (slot('origins')) slot('origins').hidden=S.view!=='board';
            slot('stats').hidden = S.view !== 'board'; slot('filters').hidden = S.view !== 'board'; slot('backfill').hidden = true;
            control('view').textContent = S.view === 'board' ? 'Historik' : 'Översikt';
            refresh();
        }
        function requestId() {
            const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
            return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        }
        async function mutate(button) {
            if (stale() || S.mutating) return;
            const row = S.rows.get(Number(button.dataset.orderId));
            if (!row) return;
            const isHandover=button.dataset.command==='handover';
            let confirmUnpaid=false;
            if (isHandover && row.kind!=='external' && !row.payment_recorded) {
                confirmUnpaid=window.confirm('Betalningen är inte bekräftad i WooCommerce. Är ordern ändå betald eller godkänd för utlämning? Utlämnad sätter ordern till Färdigbehandlad men debiterar inte kunden.');
                if (!confirmUnpaid) return;
            }
            S.mutating = true; S.readError = false; S.generation++;
            if (S.reading) S.reading.abort(); clearTimeout(S.timer); connectivity(); error('');
            const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const data=await request(row.kind==='external'?`external/${Math.abs(row.id)}/actions`:`orders/${row.id}/actions`, {}, { action: button.dataset.command, request_id: requestId(), revision: row.revision, token: row.token, confirm_unpaid:confirmUnpaid }, controller.signal);
                if (data?.order?.id!==row.id || (isHandover && data.order.woo_status!=='completed')) throw new Error('Orderändringen kunde inte bekräftas i WooCommerce. Kontrollera ordern.');
            } catch (err) {
                error(err.name === 'AbortError' ? 'Ingen bekräftelse mottogs. Åtgärden kan ha sparats — kontrollera orderns aktuella läge innan du försöker igen.' : err.message);
            } finally {
                clearTimeout(timeout); S.mutating = false; S.connected = false; connectivity(); await refresh();
            }
        }
        function beep() {
            if (!S.audio) return;
            const osc = S.audio.createOscillator(); const gain = S.audio.createGain();
            osc.connect(gain); gain.connect(S.audio.destination); gain.gain.value = 0.05;
            osc.frequency.value = 660; osc.start(); osc.stop(S.audio.currentTime + 0.14);
        }
        root.addEventListener('click', async event => {
            const button = event.target.closest('button'); if (!button || !root.contains(button)) return;
            if (button.dataset.command) { await mutate(button); return; }
            if ('origin' in button.dataset) { S.origin=button.dataset.origin; renderBoard([...S.rows.values()]); connectivity(); return; }
            if ('channel' in button.dataset) { S.channel = button.dataset.channel; renderBoard([...S.rows.values()]); connectivity(); return; }
            if (button.dataset.print) {
                if(stale() || S.mutating || button.dataset.locked==='true')return;
                if(boot.printing?.enabled) {
                    const id=Number(button.dataset.print);
                    if(printedOrders.has(id) && !window.confirm('Ett utskriftsförsök har redan gjorts för detta kort. Kontrollera BizPrint. Vill du beställa en ny kopia?'))return;
                    printedOrders.add(id);S.mutating=true;connectivity();
                    try {
                        const result=await request('print',{}, {order_id:Number(button.dataset.print),request_id:requestId()});
                        const feedback=slot('feedback');if(feedback){feedback.textContent='Utskriften är mottagen av BizPrint · jobb '+result.job_id+'.';feedback.hidden=false;}
                    } catch(err){error(err.message);}
                    finally{S.mutating=false;connectivity();}
                    return;
                }
                root.querySelectorAll('.kdsu-print-target').forEach(n => n.classList.remove('kdsu-print-target'));
                button.closest('.kdsu-card').classList.add('kdsu-print-target');
                document.body.classList.add('yookds-printing'); window.print(); return;
            }
            switch (button.dataset.ui) {
                case 'view': S.view = S.view === 'board' ? 'archive' : 'board'; S.page = 1; error(''); view(); break;
                case 'refresh': error(''); if (S.stopped) location.reload(); else refresh(); break;
                case 'theme': root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark'; break;
                case 'fullscreen':
                    try { if (document.fullscreenElement) await document.exitFullscreen(); else await root.requestFullscreen(); }
                    catch (_) { error('Webbläsaren stöder inte fullskärm här. Använd den fristående köksvyn.'); } break;
                case 'sound':
                    try {
                        const Audio = window.AudioContext || window.webkitAudioContext;
                        S.audio = S.audio || new Audio(); await S.audio.resume(); S.sound = !S.sound;
                        button.textContent = S.sound ? 'Ljud på' : 'Ljud av'; button.setAttribute('aria-pressed', String(S.sound)); if (S.sound) beep();
                    } catch (_) { error('Ljud kunde inte aktiveras i webbläsaren.'); } break;
                case 'links': {
                    const form=control('links-form');
                    if (!form.elements.base.value) form.elements.base.value=boot.site;
                    const chosen=form.elements.channel.value;
                    form.elements.channel.replaceChildren();
                    const options=new Map([['table','QR / Bord'],['express','Express'],['web','Online']]);
                    for (const channel of S.settings.channels || []) options.set(channel.id,channel.name);
                    for (const [id,name] of options) { const option=el('option','',name); option.value=id; form.elements.channel.append(option); }
                    if (options.has(chosen)) form.elements.channel.value=chosen;
                    slot('links-dialog').showModal(); updateLink(); break;
                }
                case 'close-links': slot('links-dialog').close(); break;
                case 'copy-link':
                    try { await navigator.clipboard.writeText(control('links-form').elements.result.value); button.textContent='Kopierad'; setTimeout(()=>button.textContent='Kopiera',1200); }
                    catch (_) { slot('link-error').textContent='Markera länken och kopiera manuellt.'; } break;
                case 'settings': {
                    const form = control('settings-form');
                    form.elements.poll_seconds.value = S.settings.poll_seconds;
                    form.elements.four_digit_numbers.checked=S.settings.four_digit_numbers;
                    form.elements.custom_fields.value=(S.settings.custom_fields || []).map(f=>[f.scope,f.key,f.label].join('|')).join('\n');
                    form.elements.channels.value=(S.settings.channels || []).map(c=>[c.id,c.name,c.color].join('|')).join('\n');
                    slot('settings-error').hidden = true; slot('settings-dialog').showModal(); break;
                }
                case 'close-settings': slot('settings-dialog').close(); break;
                case 'prev': if (S.page > 1) { S.page--; refresh(); } break;
                case 'next': if (S.page < S.max) { S.page++; refresh(); } break;
            }
        });
        control('search').addEventListener('submit', event => {
            event.preventDefault(); const form = event.currentTarget;
            S.range = form.elements.range.value; S.q = form.elements.q.value.trim(); S.page = 1; error(''); refresh();
        });
        const settingsForm = control('settings-form');
        if (settingsForm) settingsForm.addEventListener('submit', async event => {
            event.preventDefault(); const form = event.currentTarget; const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
            const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const data = await request('settings', {}, {
                    poll_seconds: Number(form.elements.poll_seconds.value), four_digit_numbers:form.elements.four_digit_numbers.checked,
                    custom_fields:parseLines(form.elements.custom_fields.value,['scope','key','label']),
                    channels:parseLines(form.elements.channels.value,['id','name','color']),
                }, controller.signal);
                S.settings = data.settings; boot.settings=data.settings; S.poll = data.settings.poll_seconds; slot('settings-dialog').close(); refresh();
            } catch (err) { slot('settings-error').textContent = err.message; slot('settings-error').hidden = false; }
            finally { clearTimeout(timeout); submit.disabled = false; }
        });
        function updateLink() {
            const form=control('links-form'); if (!form) return;
            const values=Object.fromEntries(['channel','station','table','mode'].map(key=>[key,form.elements[key].value]));
            try {
                const url=nxLink(form.elements.base.value || boot.site,values);
                form.elements.result.value=url; slot('link-error').textContent='';
                const box=slot('qr'); box.replaceChildren();
                if (!window.YooKDSQR) throw new Error('QR-biblioteket kunde inte laddas. Ladda om sidan och kontrollera cacheinställningarna.');
                if (window.YooKDSQR) {
                    const svg=window.YooKDSQR.svg(url); box.innerHTML=svg; // Trusted local encoder outputs only path coordinates.
                    const save=control('save-qr'); save.href='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg); save.download='yookds-qr.svg'; save.hidden=false;
                }
            } catch (err) {
                form.elements.result.value=''; slot('qr').replaceChildren(); control('save-qr').hidden=true;
                slot('link-error').textContent=err.message;
            }
        }
        const linkForm=control('links-form');
        if (linkForm) {
            linkForm.addEventListener('submit',event=>event.preventDefault());
            linkForm.addEventListener('input',updateLink);
            linkForm.addEventListener('change',updateLink);
        }
        window.addEventListener('afterprint' , () => { document.body.classList.remove('yookds-printing'); });
        window.addEventListener('online', () => { S.failures = 0; refresh(); });
        window.addEventListener('offline', () => { S.connected = false; connectivity(); });
        document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(S.timer); else refresh(); });
        setInterval(()=>{connectivity();ages();}, 1000);
        view();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
