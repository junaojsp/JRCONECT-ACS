"""Local browser tests with a mock API. No production login or CPE calls."""
from pathlib import Path
from copy import deepcopy
import json
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
css=(root/'assets/css/wifi-network-groups.css').read_text()
js=(root/'assets/js/wifi-network-groups.js').read_text()
fixture=json.loads((root/'tests/groups-fixture.json').read_text())
section='''<section class="acs-approved-wifi"><h3>REDES WI-FI</h3><div class="acs-wifi-reference-grid"><label>Rede individual</label><select id="legacy-select"><option value="24">2,4 GHz</option><option value="5">5 GHz</option></select><button id="legacy-edit">Gerenciar Wi-Fi</button><p>SSID e senha individuais preservados.</p></div></section>'''
html='''<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{background:#101827;color:#e7edf6;font-family:Arial;margin:0;padding:16px;box-sizing:border-box}*{box-sizing:border-box}.grid{display:grid;grid-template-columns:1fr 1.3fr 1fr;gap:14px}section{background:#182235;border:1px solid #2a3850;border-radius:12px;padding:16px;min-width:0}h3{font-size:14px;margin:0 0 18px}label{display:block}select{max-width:100%;padding:8px}button{cursor:pointer}.acs-approved-wifi .acs-wifi-reference-grid{display:block!important}@media(max-width:900px){.grid{grid-template-columns:1fr}}</style><style>'''+css+'''</style></head><body><h2>JR CONECT - testes de interface</h2><div id="overview-content" class="grid"><section><h3>INFORMACOES DO EQUIPAMENTO</h3><p>Dados preservados</p></section>'''+section+'''<section><h3>CREDENCIAIS</h3><p id="credentials-test">Permissoes preservadas</p></section></div><script>window.DEVICE_ID='TEST-ONLY';</script><script>'''+js+'''</script></body></html>'''
checks=0
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    for width in [1920,1280,768,390]:
        page=browser.new_page(viewport={'width':width,'height':1050})
        errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        mock = """<script>
        window.__groupsFixture=FIXTURE;
        window.__mockError=false;window.__requests=[];
        window.fetch=async (url,options)=>{
            window.__requests.push(options.method || 'GET');
            return {ok:!window.__mockError,json:async()=>window.__mockError?{success:false,message:'<img src=x onerror="window.bad=1"> failure'}:structuredClone(window.__groupsFixture)};
        };
        window.__downloadBlob=null;
        URL.createObjectURL=blob=>{window.__downloadBlob=blob;return 'blob:local-test';};
        URL.revokeObjectURL=()=>{};
        HTMLAnchorElement.prototype.click=function(){window.__downloadName=this.download;};
        </script>""".replace('FIXTURE',json.dumps(fixture))
        page.set_content(html.replace('<script>window.DEVICE_ID',mock+'<script>window.DEVICE_ID'))
        page.wait_for_selector('.jr-wng-nav')
        assert page.locator('.jr-wng-nav').count()==1;checks+=1
        assert page.locator('#legacy-select').is_visible() and not page.evaluate('window.__requests.length');checks+=1
        page.locator('#legacy-select').select_option('5')
        page.locator('[data-wng-mode="unified"]').click()
        page.wait_for_selector('.jr-wng-values')
        assert page.evaluate('window.__requests')==['GET'];checks+=1
        assert not page.locator('#legacy-select').is_visible();checks+=1
        assert page.locator('.jr-wng-heading strong').inner_text()=='Rede unificada';checks+=1
        assert 'Wi-Fi 6' in page.locator('.jr-wng-values').inner_text();checks+=1
        assert 'Wi-Fi 7' in page.locator('.jr-wng-values').inner_text();checks+=1
        assert page.get_by_role('button',name='Gerenciar rede unificada',exact=True).is_disabled();checks+=1
        assert page.locator('[data-wng-mode="mlo"]').is_visible();checks+=1
        assert 'N\u00e3o confirmado' in page.locator('.jr-wng-values').inner_text();checks+=1
        assert not page.evaluate('document.documentElement.scrollWidth>window.innerWidth');checks+=1
        panel=page.locator('.jr-wng-panel').bounding_box();card=page.locator('.acs-approved-wifi').bounding_box()
        assert panel['x']>=card['x'] and panel['x']+panel['width']<=card['x']+card['width']+1;checks+=1
        page.locator('[data-wng-mode="individual"]').click()
        assert page.locator('#legacy-select').input_value()=='5';checks+=1
        assert page.locator('#credentials-test').inner_text()=='Permissoes preservadas';checks+=1
        page.locator('[data-wng-mode="mlo"]').click()
        assert page.locator('.jr-wng-heading strong').inner_text()=='Rede MLO';checks+=1
        assert page.get_by_role('button',name='Gerenciar rede MLO',exact=True).is_disabled();checks+=1
        page.locator('[data-wng-mode="unified"]').click()
        page.evaluate('(html)=>{document.querySelector(".acs-approved-wifi").outerHTML=html;}',section)
        page.wait_for_selector('.jr-wng-values')
        assert page.locator('.jr-wng-nav').count()==1 and not page.locator('#legacy-select').is_visible();checks+=1
        assert page.evaluate('window.__requests')==['GET'];checks+=1
        page.locator('[data-wng-action="diagnostic"]').click()
        assert page.evaluate('window.__downloadName')=='diagnostico-rede-unificada.json';checks+=1
        exported=page.evaluate('window.__downloadBlob.text()')
        assert all(s not in exported for s in ['SECRET-SSID','SECRET-PASSWORD','PRIVATE-SERIAL','GROUP-SECRET','TEST-ONLY']);checks+=1
        assert 'candidate_count' in exported;checks+=1
        assert json.loads(exported)['schema']=='wifi-network-groups-diagnostic-v1';checks+=1
        page.evaluate('window.__mockError=true')
        page.locator('[data-wng-action="refresh"]').click()
        page.wait_for_selector('.jr-wng-error')
        assert not page.locator('.jr-wng-panel img').count() and not page.evaluate('window.bad || false');checks+=1
        assert 'Wi-Fi 6' in page.locator('.jr-wng-values').inner_text();checks+=1
        assert all(m=='GET' for m in page.evaluate('window.__requests'));checks+=1
        assert not errors,errors;checks+=1
        if width==1920:
            page.evaluate('window.__mockError=false')
            page.locator('[data-wng-action="refresh"]').click()
            page.wait_for_function('!document.querySelector(".jr-wng-error")')
            page.screenshot(path=str(root/'tests/unified-desktop.png'),full_page=True)
        page.close()
    # Wi-Fi 7 capability alone must not show a configured MLO network.
    saved=deepcopy(fixture)
    fixture['data']['mlo']['candidate_count']=0
    fixture['data']['unified']['candidate_count']=0
    page=browser.new_page(viewport={'width':1280,'height':900})
    mock=mock.replace(json.dumps(saved),json.dumps(fixture))
    page.set_content(html.replace('<script>window.DEVICE_ID',mock+'<script>window.DEVICE_ID'))
    page.locator('[data-wng-mode="unified"]').click()
    page.wait_for_selector('.jr-wng-values')
    assert page.locator('[data-wng-mode="mlo"]').count()==0;checks+=1
    assert 'N\u00e3o identificado' in page.locator('.jr-wng-status').inner_text();checks+=1
    assert page.get_by_role('button',name='Gerenciar rede unificada',exact=True).is_disabled();checks+=1
    page.close();browser.close()
print(f'PASS: {checks} UI assertions; 4 widths; mock API; no CPE actions')
