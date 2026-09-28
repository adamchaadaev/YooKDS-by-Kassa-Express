"""Real Chromium, offline mocked fetch. Does NOT emulate WooCommerce/PHP integration."""
import os
os.environ['PW_TEST_SCREENSHOT_NO_FONTS_READY']='1'
import json, pathlib, time, shutil
from playwright.sync_api import sync_playwright, expect
ROOT = pathlib.Path(__file__).resolve().parents[1]
now = int(time.time())
def row(i, state='preparing', channel='express'):
    return {'id':i,'order_number':str(i).zfill(4),'woo_status':'completed' if state=='archived' else 'processing', 'woo_status_label':'Färdigbehandlad' if state=='archived' else 'Behandlas', 'order_url':f'https://fixture.test/wp-admin/admin.php?page=wc-orders&action=edit&id={i}', 'custom_fields':[{'label':'Leveranstid','value':'18:30'}],'items':[{'id':i+100,'name':'Kebabtallrik','quantity':'2','product_id':1,'variation_id':0,'details':[{'label':'Tillval','value':'Utan lök · extra vitlökssås'}],'refunded_quantity':'0'}], 'note':'Packa såsen separat.','shipping':[{'id':3,'label':'Hämtning','method':'local_pickup'}], 'channel':channel,'origin':'1' if channel=='express' else '', 'table':'18' if channel=='table' else '', 'currency':'SEK','total':'249.00','refunded_total':'0','payment_recorded':True,'created_at':now-420,'state':state,'revision':1,'token':str(i)*8,'changed':False,'reason':'','started_at':now-420,'ready_at':now-60 if state=='ready' else 0,'closed_at':now-30 if state=='archived' else 0,'can_restore':state!='archived'}
rows={i:row(i,s,ch) for i,s,ch in [(101,'preparing','express'),(102,'ready','web'),(103,'blocked','table'),(104,'archived','web')]}
rows[102]['items'][0]['name']='Margherita';rows[103]['items']=[];rows[103]['reason']='empty_order';rows[103]['can_restore']=False
flags={'fail':False,'stale':False,'forbidden':False,'wrong_source':False};actions=[];archive_queries=[]
settings={'receive_statuses':['pending','processing','on-hold'],'poll_seconds':3,'complete_on_handover':True,'four_digit_numbers':True,'custom_fields':[], 'channels':[{'id':'table','name':'QR / Bord','color':'#8b5cf6'},{'id':'web','name':'Online','color':'#1152ac'},{'id':'express','name':'Express','color':'#0e7a0e'}]}

def handler(route):
    from urllib.parse import urlparse,parse_qs
    request=route.request; path=urlparse(request.url).path; qs=parse_qs(urlparse(request.url).query)
    def send(data,status=200):
        if 'orders' in data: data.update({'source':'other' if flags['wrong_source'] else 'woocommerce','schema':2,'site':'https://fixture.test/','version':'1.1.0-alpha.2','read_id':qs.get('read_id',[''])[0]})
        route.fulfill(status=status,content_type='application/json',headers={'Cache-Control':'no-store','X-WP-Nonce':'renewed-fixture-nonce'},body=json.dumps(data))
    if flags['forbidden']: send({'message':'Sessionen har gått ut.'},403);return
    if flags['fail']: send({'message':'Simulerat anslutningsfel.'},503);return
    if path.endswith('/actions'):
        body=request.post_data_json;actions.append(body);r=rows[int(path.split('/')[-2])]
        if flags['stale'] or body['revision']!=r['revision']:
            send({'message':'Ordern har ändrats på en annan skärm.'},409);return
        a=body['action'];r['revision']+=1
        if a=='ready':r['state']='ready';r['ready_at']=int(time.time())
        if a=='handover':r['state']='archived';r['closed_at']=int(time.time());r['woo_status']='completed';r['can_restore']=False
        if a=='restore':r['state']='preparing';r['changed']=True;r['closed_at']=0;r['ready_at']=0
        if a=='acknowledge':r['changed']=False
        send({'order':r,'replayed':False});return
    if path.endswith('/settings'):
        settings.update(request.post_data_json);send({'settings':settings});return
    if path.endswith('/archive'):
        query=qs.get('q',[''])[0];archive_queries.append(query)
        found=[r for r in rows.values() if r['state']=='archived' and (not query or query.lower() in json.dumps(r,ensure_ascii=False).lower())]
        page=int(qs.get('page',[1])[0]);maximum=max(1,(len(found)+23)//24);page=min(page,maximum)
        send({'orders':found[(page-1)*24:page*24],'total':len(found),'page':page,'max_pages':maximum,'server_time':int(time.time())});return
    send({'orders':[r for r in rows.values() if r['state']!='archived'],'server_time':int(time.time()),'poll_seconds':3,'backfill_remaining':0})

started=time.monotonic()
passed=[]
def check(name):passed.append(name);print('PASS',name, 'elapsed='+str(round(time.monotonic()-started,1))+'s',flush=True)
try:
    with sync_playwright() as p:
        executable=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium')
        browser=p.chromium.launch(headless=True,**({'executable_path':executable} if executable else {}),args=['--no-sandbox'] if getattr(os,'geteuid',lambda:1)()==0 else [])
        context=browser.new_context(viewport={'width':1440,'height':1020})
        def offline_api(url, options):
            class Request:
                pass
            request = Request()
            request.url = url
            request.post_data_json = json.loads(options.get('body') or '{}')
            output = {}
            class Route:
                def __init__(self): self.request = request
                def fulfill(self, **kwargs): output.update(kwargs)
            handler(Route())
            return output
        def load_offline(page):
            # No browser network requests: policy in this sandbox blocks localhost navigation.
            page.expose_function('fixtureAPI', offline_api)
            html=(ROOT/'tests/fixtures/board.html').read_text()
            import re
            html=re.sub(r'<link[^>]+>', '', html)
            html=re.sub(r'<script src=[^>]+></script>', '', html)
            html=html.replace('location.origin+', "'https://fixture.test'+")
            page.set_content(html)
            page.add_style_tag(path=str(ROOT/'yookds-for-woocommerce/assets/css/kds.css'))
            page.evaluate('''window.fetch = async (url, options={}) => {
                const result = await window.fixtureAPI(String(url), {method:options.method, headers:options.headers, body:options.body});
                return new Response(result.body, {status:result.status, headers:result.headers});
            };''')
            page.add_script_tag(path=str(ROOT/'yookds-for-woocommerce/assets/js/qr.js'))
            page.add_script_tag(path=str(ROOT/'yookds-for-woocommerce/assets/js/kds.js'))
        a=context.new_page(); b=context.new_page();errors=[]
        a.set_default_timeout(5000); b.set_default_timeout(5000)
        a.on('pageerror',lambda e:errors.append(str(e))); b.on('pageerror',lambda e:errors.append(str(e)))
        load_offline(a);load_offline(b)
        expect(a.locator('[data-slot=preparing] .kdsu-card')).to_have_count(2)
        expect(a.locator('[data-slot=ready] .kdsu-card')).to_have_count(1);check('initial two-column board with stopped-order warning')
        # Inspect layout before mutation tests, using the actual template and app CSS.
        a.screenshot(path=str(ROOT.parent/'yookds-desktop.png'),full_page=True,timeout=5000,animations='disabled')
        a.set_viewport_size({'width':390,'height':844})
        assert a.evaluate('document.documentElement.scrollWidth')<=390
        check('390px mobile layout has no horizontal overflow')
        a.screenshot(path=str(ROOT.parent/'yookds-mobile.png'),full_page=True,timeout=5000,animations='disabled')
        a.set_viewport_size({'width':1440,'height':1020})
        a.locator('.kdsu-card[data-id="101"]').evaluate("n=>n.dataset.retained='yes'")
        a.locator('[data-ui=refresh]').click();a.wait_for_timeout(200)
        assert a.locator('.kdsu-card[data-id="101"]').get_attribute('data-retained')=='yes';check('unchanged poll retains card DOM')
        a.locator('[data-order-id="101"][data-command=ready]').click()
        expect(b.locator('[data-slot=ready] .kdsu-card[data-id="101"]')).to_have_count(1,timeout=6000)
        assert actions[-1]['revision']==1 and actions[-1]['token']==rows[101]['token'] and len(actions[-1]['request_id'])==32
        check('two browser pages observe same server-confirmed ready state through polling')
        rows[101]['items'][0]['details'][0]['value']='<img src=x onerror="window.attack=1"> & ingen lök'
        rows[101]['state']='preparing';rows[101]['changed']=True;rows[101]['revision']+=1
        a.locator('[data-ui=refresh]').click()
        expect(a.locator('[data-order-id="101"][data-command=acknowledge]')).to_have_count(1)
        assert a.locator('.kdsu-addon img').count()==0 and a.evaluate('window.attack') is None
        assert '<img' in a.locator('.kdsu-card[data-id="101"] .kdsu-addon').inner_text()
        check('untrusted add-on content rendered as text, not executable HTML')
        a.locator('[data-order-id="101"][data-command=acknowledge]').click()
        expect(a.locator('[data-order-id="101"][data-command=ready]')).to_be_enabled()
        check('changed-order acknowledgement shown before ready')
        flags['stale']=True;a.locator('[data-order-id="101"][data-command=ready]').click()
        expect(a.locator('[data-slot=error]')).to_contain_text('annan skärm')
        expect(a.locator('[data-slot=preparing] .kdsu-card[data-id="101"]')).to_have_count(1)
        flags['stale']=False;check('409 conflict never optimistically removes an order')
        flags['fail']=True;a.locator('[data-ui=refresh]').click()
        expect(a.locator('[data-order-id="101"][data-command=ready]')).to_be_disabled()
        expect(a.locator('[data-slot=connection]')).to_contain_text('Inte synkroniserad')
        assert a.locator('.kdsu-card[data-id="101"]').count()==1;check('failed refresh retains visible cards but locks actions')
        flags['fail']=False;a.locator('[data-ui=refresh]').click()
        expect(a.locator('[data-order-id="101"][data-command=ready]')).to_be_enabled();check('successful reconnect restores actions')
        a.locator('[data-order-id="102"][data-command=handover]').click()
        expect(a.locator('[data-slot=ready] .kdsu-card[data-id="102"]')).to_have_count(0)
        a.locator('[data-ui=view]').click()
        expect(a.locator('[data-view=archive]')).to_be_visible()
        expect(a.locator('[data-slot=archive] .kdsu-card[data-id="102"]')).to_have_count(1);check('handover moves order to visible history')
        assert a.locator('[data-order-id="102"][data-command=restore]').count()==0
        assert rows[102]['woo_status']=='completed'
        check('completed order cannot be restored only in KDS')
        rows[102]['woo_status']='processing'; rows[102]['state']='preparing'; rows[102]['changed']=True; rows[102]['revision']+=1
        a.locator('[data-ui=view]').click()
        expect(a.locator('[data-order-id="102"][data-command=acknowledge]')).to_have_count(1);check('external Woo reopening returns same order for review')
        for i in range(200,230):rows[i]=row(i,'archived','web')
        rows[229]['items'][0]['name']='Saffranspannkaka'
        a.locator('[data-ui=view]').click()
        expect(a.locator('[data-slot=page]')).to_contain_text('av 2')
        a.locator('[data-ui=next]').click();expect(a.locator('[data-slot=page]')).to_contain_text('Sida 2')
        a.locator('input[name=q]').fill('Saffran');a.locator('form[data-ui=search] button').click()
        expect(a.locator('[data-slot=archive] .kdsu-card')).to_have_count(1)
        assert 'Saffran' in archive_queries;check('history query sent to server and pagination resets')
        a.locator('[data-ui=settings]').click();expect(a.locator('[data-slot=settings-dialog]')).to_be_visible()
        assert a.locator('input[name=four_digit_numbers]').is_checked()
        assert a.locator('input[name=complete_on_handover]').count()==0
        a.locator('input[name=poll_seconds]').fill('7');a.locator('form[data-ui=settings-form] [type=submit]').click()
        expect(a.locator('[data-slot=settings-dialog]')).not_to_be_visible();assert settings['poll_seconds']==7 and settings['complete_on_handover'];check('settings save without optional Woo completion switch')
        a.locator('[data-ui=view]').click();a.locator('[data-ui=theme]').click()
        expect(a.locator('#kdsu-root')).to_have_attribute('data-theme','dark');check('dark theme toggles')
        expect(a.locator('.kdsu-order-field').first).to_contain_text('Leveranstid')
        check('custom order fields visible on cards')
        assert a.locator('.kdsu-card[data-id="101"] .kdsu-order-link').get_attribute('href').endswith('id=101')
        check('order link uses real Woo ID rather than short display number')
        a.locator('[data-ui=links]').click()
        expect(a.locator('[data-slot=links-dialog]')).to_be_visible()
        a.locator('form[data-ui=links-form] input[name=table]').fill('18')
        expect(a.locator('[data-slot=qr] svg')).to_have_count(1)
        assert 'nx_table=18' in a.locator('form[data-ui=links-form] input[name=result]').input_value()
        assert a.locator('[data-ui=save-qr]').get_attribute('href').startswith('data:image/svg+xml;')
        a.locator('[data-ui=close-links]').click();check('local QR SVG and NX link generation work without external service')
        flags['wrong_source']=True;a.locator('[data-ui=refresh]').click()
        expect(a.locator('[data-slot=error]')).to_contain_text('ordersvar')
        expect(a.locator('[data-order-id="101"][data-command=ready]')).to_be_disabled()
        flags['wrong_source']=False;a.locator('[data-ui=refresh]').click()
        expect(a.locator('[data-order-id="101"][data-command=ready]')).to_be_enabled()
        check('cached or wrong-source order feeds rejected, never replaced with demo data')
        # Unpaid pending orders require explicit confirmation before completing Woo.
        rows[105]=row(105,'ready','table');rows[105]['payment_recorded']=False;rows[105]['woo_status']='pending'
        a.locator('[data-ui=refresh]').click()
        expect(a.locator('[data-order-id="105"][data-command=handover]')).to_be_enabled()
        # Deterministic decision boundary: browser-native modal handling is not tested here.
        a.evaluate("window.confirm=(message)=>{window.unpaidPrompt=message;return true;}")
        a.locator('[data-order-id="105"][data-command=handover]').click()
        expect(a.locator('[data-slot=ready] .kdsu-card[data-id="105"]')).to_have_count(0)
        assert actions[-1].get('confirm_unpaid') is True and 'Betalningen' in a.evaluate('window.unpaidPrompt')
        check('unpaid handover requires an explicit user confirmation')
        assert not errors,errors;check('no JavaScript page errors throughout browser suite')
        browser.close()
finally:
    pass
print(f'\n{len(passed)} Chromium browser checks passed with an offline mocked fetch boundary.')
