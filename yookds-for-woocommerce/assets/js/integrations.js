/* Integration management: manager-only API, blank secrets preserve stored values. */
(function () {
    'use strict';
    function start() {
        const root = document.getElementById('kdsu-root');
        const boot = window.YooKDSBoot;
        const dialog = root?.querySelector('[data-slot="integrations-dialog"]');
        if (!dialog || !boot) return;
        const slot = name => dialog.querySelector(`[data-slot="${name}"]`);
        const cards = slot('integration-cards');
        let busy = false;
        const definitions = {
            bizprint: { name: 'BizPrint', eyebrow: 'UTSKRIFTER', description: 'Från order till köksbiljett. Välj skrivare och testa anslutningen.', link: 'https://getbizprint.com/documentation/developer/', fields: [['public_key','Public Key'],['secret_key','Secret Key','password']] },
            foodora: { name: 'foodora', eyebrow: 'EXTERNA BESTÄLLNINGAR', description: 'Rosa orderkort med Foodoras ordernummer, varor och instruktioner.', link: 'https://developers.deliveryhero.com/documentation/pos.html', fields: [['username','API-användarnamn'],['password','API-lösenord','password'],['venue_id','Restaurangens Remote ID'],['webhook_secret','Secret från Foodora','password']] },
            wolt: { name: 'Wolt', eyebrow: 'EXTERNA BESTÄLLNINGAR', description: 'Wolt-beställningar direkt till köket, med tillval och hämtningstid.', link: 'https://developer.wolt.com/docs/authentication20', fields: [['client_id','Client ID'],['client_secret','Client Secret','password'],['refresh_token','Refresh token från Wolt-onboarding','password'],['venue_id','Venue ID'],['webhook_secret','Webhook-hemlighet','password']] },
        };
        function el(tag, cls, text) { const n=document.createElement(tag); if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n; }
        function button(text, action, provider) { const n=el('button','',text); n.type='button';n.dataset.int=action;if(provider)n.dataset.provider=provider;return n; }
        function message(text, bad=false) { const n=slot('integration-message');n.textContent=text;n.hidden=!text;n.dataset.error=String(bad); }
        function url(path) { const u=new URL(boot.rest); if(u.searchParams.has('rest_route'))u.searchParams.set('rest_route',u.searchParams.get('rest_route').replace(/\/$/,'')+'/'+path);else u.pathname=u.pathname.replace(/\/$/,'')+'/'+path;return u; }
        async function api(path, body) {
            const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),30000);
            try {
                const response=await fetch(url(path),{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-WP-Nonce':boot.nonce,...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});
                const nonce=response.headers.get('X-WP-Nonce');if(nonce)boot.nonce=nonce;
                const data=await response.json();if(!response.ok)throw new Error(data.message||'Anslutningen kunde inte verifieras.');return data;
            } finally {clearTimeout(timer);}
        }
        function field(form,key,label,type,value,placeholder='') {
            const wrap=el('label','kdsu-connection-field',label);const input=el('input');input.name=key;input.type=type||'text';input.value=value??'';input.placeholder=placeholder;input.autocomplete='off';input.spellcheck=false;wrap.append(input);form.append(wrap);return input;
        }
        function select(form,key,label,options,value) {
            const wrap=el('label','kdsu-connection-field',label);const input=el('select');input.name=key;
            options.forEach(([id,name])=>{const o=el('option','',name);o.value=id;input.append(o);});input.value=String(value);wrap.append(input);form.append(wrap);return input;
        }
        function checkbox(form,key,label,value) { const wrap=el('label','kdsu-connection-check');const input=el('input');input.type='checkbox';input.name=key;input.checked=!!value;wrap.append(input,document.createTextNode(label));form.append(wrap); }
        function connection(provider,c) {
            const d=definitions[provider];const card=el('section','kdsu-connection');card.dataset.provider=provider;
            const head=el('header','kdsu-connection-brand');head.append(el('span','kdsu-eyebrow',d.eyebrow),el('h3','',d.name));
            const state=c.check?.state==='order_received'?'Order mottagen':c.check?.state==='credentials_verified'?'API verifierat':c.enabled?'Väntar på verifiering':'Inte aktiverad';
            head.append(el('span','kdsu-connection-state',state));card.append(head,el('p','kdsu-connection-description',d.description));
            const form=el('form');form.dataset.provider=provider;
            d.fields.forEach(([key,label,type])=>field(form,key,label,type,type==='password'?'':c[key],type==='password'&&c[key+'_saved']?'Sparad · lämna tomt för att behålla':''));
            if(provider==='bizprint') {
                select(form,'printer_id','Köksskrivare',[[0,'Hämta skrivare med knappen nedan'],...(c.printer_id?[[c.printer_id,'Vald skrivare · '+c.printer_id]]:[])],c.printer_id);
                select(form,'paper_width','Pappersbredd',[[80,'80 mm'],[58,'58 mm']],c.paper_width);
                checkbox(form,'auto_print','Skriv ut nya beställningar automatiskt',c.auto_print);
                form.append(el('p','kdsu-connection-help','1. Spara API-nycklar. 2. Hämta skrivare. 3. Välj skrivare och aktivera. BizPrint-appen behöver vara igång vid skrivaren.'));
            } else {
                select(form,'environment','Miljö',[['sandbox','Testmiljö'],['production','Produktion']],c.environment);
                const webhook=field(form,'webhook_url',provider==='foodora'?'Basadress att registrera hos Foodora':'Webhook-adress att registrera','url',c.webhook_url);webhook.readOnly=true;
                form.append(button('Kopiera webhook-adress','copy',provider));
                form.append(el('p','kdsu-connection-help',provider==='foodora'?'Kräver Foodoras restaurangintegration (indirekt flöde). Registrera basadressen hos Foodora med samma Remote ID. Acceptera i Foodoras handlarapp. Använd snygga permalänkar och publik HTTPS. Foodora behöver aktivera anslutningen.':'Kräver Wolt Marketplace-partneråtkomst. Ange refresh token efter Wolt-onboarding; den förnyas automatiskt. Registrera samma webhook-hemlighet hos Wolt. Acceptera nya ordrar i handlarappen.'));
            }
            checkbox(form,'enabled','Aktivera '+d.name,c.enabled);
            const actions=el('div','kdsu-connection-actions');actions.append(button('Spara anslutning','save',provider),button(provider==='bizprint'?'Hämta skrivare':'Testa API','test',provider));
            if(provider==='bizprint')actions.append(button('Provutskrift','print-test',provider));
            form.append(actions);const link=el('a','kdsu-provider-docs','Guide och API-åtkomst ↗');link.href=d.link;link.target='_blank';link.rel='noopener noreferrer';form.append(link);const details=el('details','kdsu-connection-details');details.append(el('summary','','Konfigurera '+d.name));details.append(form);card.append(details);return card;
        }
        async function load(openProvider) {
            const data=await api('integrations');cards.replaceChildren();
            Object.entries(data.connections).forEach(([p,c])=>{const card=connection(p,c);if(p===openProvider)card.querySelector('details').open=true;cards.append(card);});
            const upcoming=el('section','kdsu-connection kdsu-coming-soon');upcoming.dataset.provider='ubereats';const head=el('header','kdsu-connection-brand');head.append(el('span','kdsu-eyebrow','EXTERNA BESTÄLLNINGAR'),el('h3','','Uber Eats'),el('span','kdsu-connection-state','Under utveckling'));upcoming.append(head,el('p','kdsu-connection-description','En plats i samma köksflöde. Anslutningen blir tillgänglig i en kommande version.'));cards.append(upcoming);
            boot.printing={enabled:!!data.connections.bizprint.enabled};
            const jobs=slot('print-jobs');jobs.replaceChildren();
            const names={accepted:'Mottaget av BizPrint',pending:'I kö',processing:'Skriver ut',done:'Utskrivet',failed:'Misslyckades',unknown:'Osäker status · kontrollera BizPrint',submitting:'Inväntar kvittens','connecting-to-printer':'Ansluter till skrivaren',archived:'Arkiverat'};
            for(const j of data.jobs){const row=el('div','kdsu-job');row.append(el('span','',`Försök ${j.id} · ${names[j.state]||j.state}`));if(Number(j.job_id)){const b=button('Kontrollera status','job');b.dataset.job=j.id;row.append(b);}jobs.append(row);}
            if(!data.jobs.length)jobs.append(el('p','','Inga utskrifter ännu. Börja med en provutskrift.'));
        }
        function values(form) { const out={};for(const input of form.elements){if(!input.name||input.name==='webhook_url')continue;out[input.name]=input.type==='checkbox'?input.checked:['printer_id','paper_width'].includes(input.name)?Number(input.value):input.value;}return out; }
        root.addEventListener('click',async event=>{
            const b=event.target.closest('[data-ui="integrations"], [data-int]');if(!b)return;
            if(b.dataset.int==='close'){dialog.close();return;}
            if(busy)return;busy=true;b.disabled=true;
            try {
                if(b.dataset.ui==='integrations'){dialog.showModal();message('Läser anslutningar…');await load();message('');return;}
                const p=b.dataset.provider;const form=p?cards.querySelector(`form[data-provider="${p}"]`):null;
                switch(b.dataset.int){
                    case 'reload':await load();message('Status uppdaterad.');break;
                    case 'copy':await navigator.clipboard.writeText(form.elements.webhook_url.value);message('Webhook-adressen är kopierad.');break;
                    case 'save':await api('integrations/'+p,values(form));await load(p);message('Anslutningen sparad.');break;
                    case 'test':{
                        await api('integrations/'+p,values(form));const result=await api('integrations/'+p+'/test',{});
                        if(result.printers){const s=form.elements.printer_id;const old=s.value;s.replaceChildren();result.printers.forEach(pr=>{const o=el('option','',pr.name+(pr.station?' · '+pr.station:''));o.value=pr.id;s.append(o);});if([...s.options].some(o=>o.value===old))s.value=old;message(result.printers.length?'Ansluten. Välj skrivare, aktivera och spara.':'Ansluten, men inga skrivare hittades i BizPrint.');}
                        else {message(result.message);}
                        for(const input of form.querySelectorAll('input[type=password]')){if(input.value){input.value='';input.placeholder='Sparad · lämna tomt för att behålla';}}break;
                    }
                    case 'print-test':{
                        await api('integrations/'+p,values(form));const r=await api('print/test',{request_id:crypto.randomUUID()});await load();message('Provutskrift mottagen av BizPrint · jobb '+r.job_id+'.');break;
                    }
                    case 'retry':{const r=await api('integrations/retry',{});message(r.queued+' ordrar köade för nytt synkförsök.');break;}
                    case 'job':{await api('print/jobs/'+b.dataset.job,{});await load();message('Utskriftsstatus uppdaterad.');break;}
                }
            } catch(e){message(e.name==='AbortError'?'Ingen kvittens i tid. Kontrollera status innan du försöker igen.':e.message,true);}
            finally{busy=false;b.disabled=false;}
        });
        dialog.addEventListener('submit',e=>e.preventDefault());
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
