/* JR CONECT - selected Wi-Fi and router account controls; monitoring preserved. */
(() => {
    'use strict';

    const state = { csrf: '', wifi: [], accounts: [], permissions: {}, loading: null, wifiId: '', accountId: '', refreshBusy: false, wifiBand: '', wifiByBand: {}, scanMessage: '' };
    const traffic = { sessionKey: null, last: null, samples: [], polling: false, lastPoll: 0 };
    let dialog = null;
    const secretTimers = new Map();
    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
    const val = value => value === null || value === undefined || value === '' ? 'Não informado' : String(value);
    const statusLabel = item => item.enabled === true ? 'Habilitada' : item.enabled === false ? 'Desabilitada' : 'Estado não informado';
    const shortId = item => item.instance_label || (item.id || '').split('.').slice(-4).join('.');
    const rowById = (kind, id) => (kind === 'wifi' ? state.wifi : state.accounts).find(row => row.id === id);
    const selected = kind => rowById(kind, kind === 'wifi' ? state.wifiId : state.accountId);
    const permitted = kind => !!state.permissions[kind === 'wifi' ? 'wifi' : 'admin'];

    function toast(message, type='info') {
        if (typeof window.showToast === 'function') window.showToast(message, type);
        else console[type === 'danger' ? 'error' : 'log']('[CPE]', message);
    }
    async function request(payload=null) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 25000);
        const options = { credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{ Accept:'application/json' } };
        let url = '/api/device-control.php';
        if (payload) {
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = state.csrf;
            options.body = JSON.stringify({ device_id:window.DEVICE_ID || '', ...payload });
        } else url += '?device_id=' + encodeURIComponent(window.DEVICE_ID || '');
        try {
            const r = await fetch(url, options);
            let data;
            try { data = await r.json(); } catch (_) { throw new Error('Resposta inválida. Confirme sua sessão e atualize a página.'); }
            if (!r.ok || !data.success) throw new Error(data.message || 'Operação não confirmada.');
            return data;
        } catch (e) {
            if (e.name === 'AbortError') throw new Error('Tempo limite. Se estava salvando, confira a leitura antes de reenviar.');
            throw e;
        } finally { clearTimeout(timer); }
    }
    function passwordLabel(item) {
        if (item.password_state === 'available') return '••••••••';
        if (item.password_state === 'concealed') return 'Oculta pelo equipamento';
        if (item.password_state === 'not_collected') return 'Ainda não coletada';
        return 'Não informada pelo equipamento';
    }
    function securityLabel(raw) {
        return ({WPAand11i:'WPA/WPA2', '11i':'WPA2', WPA:'WPA', None:'Sem segurança informada', Basic:'Basic (informado pelo modem)'})[raw] || val(raw);
    }
    function dateLabel(raw) {
        if (!raw) return 'Horário da leitura não informado';
        const d = new Date(raw);
        return Number.isNaN(d.getTime()) ? 'Horário da leitura não informado' : 'Última leitura: ' + d.toLocaleString('pt-BR');
    }

    const wifiBandNames = {'2.4':'2,4 GHz','5':'5 GHz (5,8)','6':'6 GHz','unknown':'Banda não informada'};
    const bandOf = item => ['2.4','5','6'].includes(item.band) ? item.band : 'unknown';
    function wifiRows(band) { return state.wifi.filter(row => bandOf(row) === band); }
    function syncWifiSelection() {
        if (!state.wifiBand) {
            state.wifiBand=['2.4','5','6','unknown'].find(band=>wifiRows(band).length) || '2.4';
        }
        const rows=wifiRows(state.wifiBand);
        const current=selected('wifi');
        if (current && bandOf(current)===state.wifiBand) return;
        const saved=rows.find(row=>row.id===state.wifiByBand[state.wifiBand]);
        state.wifiId=(saved || rows.find(row=>row.enabled===true) || rows[0])?.id || '';
        if(state.wifiId) state.wifiByBand[state.wifiBand]=state.wifiId;
    }
    function bandControls() {
        const bands=['2.4','5'];
        if(wifiRows('6').length) bands.push('6');
        if(wifiRows('unknown').length) bands.push('unknown');
        return `<div class="jr-wifi-band-switch" role="group" aria-label="Banda Wi-Fi">
            ${bands.map(band=>{
                const count=wifiRows(band).length;
                const selectedBand=state.wifiBand===band;
                return `<button type="button" class="acs-soft-btn ${selectedBand?'primary':''}"
                    data-band="${band}" aria-pressed="${selectedBand}" onclick="jrSelectWifiBand('${band}')">
                    <i class="bi bi-wifi"></i><span>${wifiBandNames[band]}</span>
                    <small>${count?count+' SSID'+(count>1?'s':''):'Não coletada'}</small>
                </button>`;
            }).join('')}
        </div>`;
    }
    window.jrSelectWifiBand=band => {
        if(!Object.prototype.hasOwnProperty.call(wifiBandNames,band)) return;
        state.wifiBand=band;
        const saved=state.wifiByBand[band];
        state.wifiId=saved || '';
        syncWifiSelection();
        renderWifi();
    };

    function manager(kind, item, rows) {
        const wifi = kind === 'wifi';
        const options = rows.map(row => {
            const title = wifi ? `${val(row.ssid)} · ${row.label} · ${statusLabel(row)} · ${shortId(row)}` : `${val(row.username)} · ${row.label} · ${shortId(row)}`;
            return `<option value="${esc(row.id)}" ${row.id === item.id ? 'selected' : ''}>${esc(title)}</option>`;
        }).join('');
        const canEdit = permitted(kind) && (item[wifi ? 'ssid_writable' : 'username_writable'] || item.password_writable);
        return `<div class="jr-manager" data-kind="${kind}">
            <label for="jr-select-${kind}">${wifi ? 'Selecione a rede Wi-Fi' : 'Selecione a conta do roteador'}</label>
            <select id="jr-select-${kind}" class="form-select" onchange="jrSelectControl('${kind}',this.value)">${options}</select>
            <div class="jr-selection-meta"><span>${esc(item.label)}</span>${wifi ? `<span class="jr-selection-badge ${item.enabled === true ? 'on' : ''}">${esc(statusLabel(item))}</span>` : ''}</div>
            <dl class="jr-manager-values">
                <div><dt>${wifi ? 'SSID atual' : 'Usuário atual'}</dt><dd>${esc(val(item[wifi ? 'ssid' : 'username']))}</dd></div>
                ${wifi ? `<div><dt>Canal</dt><dd>${esc(item.auto_channel === true ? 'Automático' : val(item.channel))}</dd></div><div><dt>Segurança</dt><dd>${esc(securityLabel(item.security))}</dd></div>` : ''}
                <div><dt>Senha atual</dt><dd class="jr-manager-secret"><span id="jr-secret-${kind}">${esc(passwordLabel(item))}</span><button type="button" class="acs-eye-btn" aria-label="Mostrar senha atual" onclick="jrRevealControl('${kind}')" ${item.password_state !== 'available' ? 'disabled' : ''}><i class="bi bi-eye"></i></button></dd></div>
            </dl>
            <p class="jr-manager-note">${esc(dateLabel(item.password_reported_at))}. ${wifi ? 'A edição afeta somente a rede selecionada.' : 'A senha de acesso ao roteador é diferente da senha do Wi-Fi.'}</p>
            <div class="jr-manager-actions">
                <button type="button" class="acs-soft-btn" onclick="jrRefreshControl('${kind}')" ${state.refreshBusy ? 'disabled' : ''}><i class="bi bi-arrow-clockwise"></i> Atualizar leitura</button>
                <button type="button" class="acs-soft-btn primary" onclick="jrOpenControl('${kind}')" ${!(wifi ? permitted(kind) : canEdit) ? 'disabled' : ''}><i class="bi bi-sliders"></i> ${wifi ? 'Gerenciar Wi-Fi ' + esc(wifiBandNames[bandOf(item)]) : 'Alterar acesso'}</button>
            </div>
            ${!canEdit ? '<p class="jr-manager-note">O equipamento não confirmou escrita para esta seleção.</p>' : ''}
        </div>`;
    }
    function renderWifi() {
        const box = document.querySelector('.acs-approved-wifi .acs-wifi-reference-grid');
        if (!box) return;
        syncWifiSelection();
        const rows=wifiRows(state.wifiBand), item=selected('wifi');
        const missing=`<div class="jr-empty-control"><strong>${esc(wifiBandNames[state.wifiBand])}</strong>
            <p>Nenhuma interface desta banda foi identificada na leitura atual.</p>
            <p>Use “Detectar redes do modem” para buscar todas as interfaces. Isso não significa que a banda esteja desabilitada.</p>
            ${wifiRows('unknown').length?'<p>Há redes em “Banda não informada”; elas não foram classificadas como 5 GHz por suposição.</p>':''}
        </div>`;
        box.innerHTML=`<div class="jr-wifi-bands-shell">
            ${bandControls()}
            <div class="jr-wifi-selected">${item ? manager('wifi',item,rows) : missing}</div>
            <div class="jr-wifi-discover">
                <button type="button" class="acs-soft-btn" id="jr-detect-wifi"
                    onclick="jrRefreshControl('wifi',true)" ${state.refreshBusy || !permitted('wifi')?'disabled':''}>
                    <i class="bi bi-arrow-repeat"></i> ${state.refreshBusy?'Consultando...':'Detectar redes do modem'}
                </button>
                <small>Busca todas as bandas e SSIDs. Não altera senhas nem habilita redes.</small>
            </div>
            <button type="button" id="jr-wifi-diagnostic" class="acs-soft-btn"
                onclick="jrDownloadWifiDiagnostic()" ${!permitted('wifi')?'disabled':''}>Baixar diagnóstico Wi-Fi</button>
            <p class="jr-wifi-scan-status" role="status" aria-live="polite">${esc(state.scanMessage)}</p>
        </div>`;
    }
    function renderAccounts() {
        const box = document.querySelector('.acs-approved-admin .acs-reference-list.credentials');
        const oldButton = document.getElementById('get-credentials-btn');
        if (oldButton) { oldButton.style.display='none'; oldButton.onclick=null; }
        if (!box) return;
        if (!state.permissions.admin) {
            box.innerHTML=`<div class="jr-manager jr-permission-notice"><i class="bi bi-shield-lock"></i><p>Credenciais de acesso restritas a NOC/Admin.</p><small>Perfil verificado: ${esc(state.permissions.role || 'não informado')}. Solicite a conferência do perfil ao administrador.</small></div>`;
            return;
        }
        const item = selected('account');
        box.innerHTML = item ? manager('account',item,state.accounts) : `<div class="jr-manager"><p>O modem ainda não informou uma conta local compatível.</p><p class="jr-manager-note">A leitura depende dos parâmetros disponibilizados pelo firmware.</p><button type="button" class="acs-soft-btn" onclick="jrRefreshControl('account')" ${state.refreshBusy ? 'disabled' : ''}>Atualizar leitura</button></div>`;
    }
    async function enhanceControls() {
        if (!window.DEVICE_ID) return;
        if (state.loading) return state.loading;
        state.loading=(async () => {
            try {
                const data=await request();
                state.csrf=data.csrf || '';
                state.permissions=data.permissions || {};
                // Remove duplicate IDs, not distinct SSIDs on the same band.
                state.wifi=Array.isArray(data.wifi)
                    ? [...new Map(data.wifi.filter(row=>row && typeof row.id==='string').map(row=>[row.id,row])).values()]
                    : [];
                state.accounts=Array.isArray(data.accounts)?data.accounts:[];
                // A stable parameter ID survives sorting, polling and insertion/removal of other SSIDs.
                syncWifiSelection();
                if (!selected('account')) state.accountId=state.accounts[0]?.id || '';
                renderWifi(); renderAccounts();
            } catch (e) {
                for (const selector of ['.acs-approved-wifi .acs-wifi-reference-grid','.acs-approved-admin .acs-reference-list.credentials']) {
                    const box=document.querySelector(selector);
                    if (box) box.innerHTML=`<div class="jr-empty-control danger">${esc(e.message)}</div>`;
                }
            } finally { state.loading=null; }
        })();
        return state.loading;
    }
    window.jrSelectControl=(kind,id) => {
        if (!rowById(kind,id)) return;
        if (kind==='wifi') {
            state.wifiId=id;
            state.wifiBand=bandOf(rowById('wifi',id));
            state.wifiByBand[state.wifiBand]=id;
        } else state.accountId=id;
        renderWifi(); renderAccounts();
    };
    function secretPayload(kind,item) {
        return { action:'reveal_secret', kind, [kind==='wifi'?'interface_id':'account_id']:item.id };
    }
    window.jrRevealControl=async kind => {
        const item=selected(kind), el=document.getElementById('jr-secret-'+kind);
        if (!item || !el || item.password_state!=='available' || !permitted(kind)) return;
        if (el.dataset.showing==='1') { el.textContent=passwordLabel(item); el.dataset.showing='0'; return; }
        const button=el.parentElement.querySelector('button'); button.disabled=true;
        try {
            const result=await request(secretPayload(kind,item));
            if (!el.isConnected || selected(kind)?.id!==item.id) return;
            el.textContent=result.password; el.dataset.showing='1';
            clearTimeout(secretTimers.get(kind));
            secretTimers.set(kind,setTimeout(()=>{if(el.isConnected){el.textContent=passwordLabel(item);el.dataset.showing='0';}},30000));
        } catch(e) { toast(e.message,'danger'); }
        finally { if(button.isConnected)button.disabled=false; }
    };
    function note(text,error=false) {
        const el=document.getElementById('jr-control-note');
        if(el){el.textContent=text;el.classList.toggle('danger',error);}
    }
    function ensureModal() {
        if(document.getElementById('jrDeviceControlModal')) return;
        document.body.insertAdjacentHTML('beforeend',`
        <div class="modal fade jr-control-modal" id="jrDeviceControlModal" tabindex="-1" aria-labelledby="jr-control-title">
          <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><div><small id="jr-control-kicker">EQUIPAMENTO</small><h5 id="jr-control-title" class="modal-title">Gerenciar</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
              <div class="jr-field" id="jr-editor-wifi-field" hidden>
                <label for="jr-editor-wifi">Qual rede deseja alterar?</label>
                <select id="jr-editor-wifi" class="form-control" onchange="jrChangeEditorWifi(this.value)"></select>
              </div>
              <p id="jr-control-target" class="jr-target"></p>
              <div class="jr-field"><label id="jr-user-label" for="jr-control-user">SSID</label><input id="jr-control-user" class="form-control" autocomplete="off"></div>
              <div class="jr-field"><label for="jr-control-current">Senha atual informada pelo equipamento</label><div class="jr-password-input"><input id="jr-control-current" class="form-control" type="password" readonly autocomplete="off"><button id="jr-show-current" type="button" onclick="jrRevealModalCurrent()" aria-label="Mostrar senha atual"><i class="bi bi-eye"></i></button></div></div>
              <div class="jr-field"><label for="jr-control-password">Nova senha</label><div class="jr-password-input"><input id="jr-control-password" type="password" class="form-control" autocomplete="new-password" placeholder="Em branco mantém a senha atual"><button type="button" onclick="jrToggleModalPassword()" aria-label="Mostrar nova senha"><i class="bi bi-eye"></i></button></div></div>
              <div class="jr-field"><label for="jr-control-confirm">Confirmar nova senha</label><input id="jr-control-confirm" class="form-control" type="password" autocomplete="new-password"></div>
              <div class="jr-control-note" id="jr-control-note" role="status" aria-live="polite"></div>
            </div>
            <div class="modal-footer"><button type="button" class="acs-soft-btn" data-bs-dismiss="modal">Cancelar</button><button id="jr-control-save" type="button" class="acs-soft-btn primary" onclick="jrSaveDeviceControl()"><i class="bi bi-check2"></i> Salvar nesta seleção</button></div>
          </div></div>
        </div>`);
        const modal=document.getElementById('jrDeviceControlModal');
        modal.addEventListener('hide.bs.modal',event=>{if(dialog?.saving)event.preventDefault();});
        modal.addEventListener('hidden.bs.modal',()=>{
            dialog=null;
            for(const id of ['jr-control-current','jr-control-password','jr-control-confirm']) document.getElementById(id).value='';
        });
    }
    function renderEditorWifiOptions(id) {
        const select=document.getElementById('jr-editor-wifi');
        const bands=['2.4','5',...['6','unknown'].filter(b=>wifiRows(b).length)];
        select.innerHTML=bands.map(band=>{
            const rows=wifiRows(band);
            const options=rows.length ? rows.map(row=>
                `<option value="${esc(row.id)}">${esc(val(row.ssid)+' · '+statusLabel(row)+' · '+shortId(row))}</option>`).join('')
                : '<option value="" disabled>Não coletada — use Detectar redes do modem</option>';
            return `<optgroup label="${esc(wifiBandNames[band])}">${options}</optgroup>`;
        }).join('');
        select.value=id;
        select.disabled=!!dialog?.saving;
    }
    window.jrChangeEditorWifi=id => {
        const d=dialog, row=rowById('wifi',id);
        const select=document.getElementById('jr-editor-wifi');
        if(!d || d.kind!=='wifi' || d.saving || !row) {
            if(d && select) select.value=d.item.id;
            return;
        }
        if(row.id===d.item.id) return;
        const dirty=document.getElementById('jr-control-user').value!==String(d.item.ssid??'')
            || document.getElementById('jr-control-password').value!=='' || document.getElementById('jr-control-confirm').value!=='';
        if(dirty && !window.confirm('Descartar os campos ainda não salvos e escolher outra rede?')) {
            select.value=d.item.id;
            return;
        }
        // jrOpenControl creates a new immutable target snapshot. Polling cannot change it.
        window.jrSelectControl('wifi',row.id);
        window.jrOpenControl('wifi');
    };
    window.jrDownloadWifiDiagnostic=async () => {
        if(!permitted('wifi')) return;
        const button=document.getElementById('jr-wifi-diagnostic');
        if(button) button.disabled=true;
        try {
            const result=await request({action:'wifi_diagnostics',kind:'wifi'});
            const blob=new Blob([JSON.stringify(result.diagnostic,null,2)],{type:'application/json'});
            const url=URL.createObjectURL(blob), anchor=document.createElement('a');
            anchor.href=url; anchor.download='diagnostico-edicao-wifi.json';
            document.body.appendChild(anchor); anchor.click(); anchor.remove();
            setTimeout(()=>URL.revokeObjectURL(url),1000);
        } catch(e) {toast(e.message,'danger');}
        finally {if(button?.isConnected)button.disabled=false;}
    };
    window.jrOpenControl=kind => {
        const item=selected(kind);
        if(!item || !permitted(kind)) return;
        if(dialog?.saving) return;
        if(kind!=='wifi' && !item.username_writable && !item.password_writable) return;
        ensureModal();
        dialog={kind,item:{...item},saving:false,currentShown:false};
        const wifi=kind==='wifi';
        const choiceField=document.getElementById('jr-editor-wifi-field');
        choiceField.hidden=!wifi;
        if(wifi) renderEditorWifiOptions(item.id);
        document.getElementById('jr-control-title').textContent=wifi?'Gerenciar Wi-Fi '+wifiBandNames[bandOf(item)]:'Alterar acesso ao roteador';
        document.getElementById('jr-control-kicker').textContent=item.label || 'EQUIPAMENTO';
        document.getElementById('jr-control-target').textContent=wifi?wifiBandNames[bandOf(item)]+' · '+val(item.ssid)+' · '+shortId(item):val(item.username)+' · '+shortId(item);
        document.getElementById('jr-user-label').textContent=wifi?'Nome da rede (SSID)':'Usuário de acesso';
        const user=document.getElementById('jr-control-user');
        user.value=item[wifi?'ssid':'username'] || ''; user.disabled=!item[wifi?'ssid_writable':'username_writable'];
        user.maxLength=wifi?32:64;
        const current=document.getElementById('jr-control-current');
        current.type=item.password_state==='available'?'password':'text'; current.value=passwordLabel(item);
        document.getElementById('jr-show-current').disabled=item.password_state!=='available';
        for(const id of ['jr-control-password','jr-control-confirm']) {
            const input=document.getElementById(id); input.value=''; input.type='password'; input.disabled=!item.password_writable; input.maxLength=wifi?63:64;
        }
        note(wifi?'Somente o SSID selecionado será alterado. Canal e segurança permanecem como estão. Trocar SSID ou senha pode desconectar os clientes dessa rede.':'A alteração afeta o acesso ao roteador, não o Wi-Fi. Garanta um acesso local alternativo antes de trocar a credencial.');
        const editable=item[wifi?'ssid_writable':'username_writable'] || item.password_writable;
        if(!editable) note('Esta interface foi identificada, mas o modem não confirmou escrita. Atualize a leitura; não será utilizada outra banda como substituta.');
        document.getElementById('jr-control-save').disabled=!editable;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDeviceControlModal')).show();
    };
    window.jrRevealModalCurrent=async () => {
        const d=dialog;
        if(!d || d.saving || d.item.password_state!=='available') return;
        const input=document.getElementById('jr-control-current'), button=document.getElementById('jr-show-current');
        if(d.currentShown){input.value=passwordLabel(d.item);input.type='password';d.currentShown=false;return;}
        button.disabled=true;
        try {
            const result=await request(secretPayload(d.kind,d.item));
            if(dialog!==d) return;
            input.type='text'; input.value=result.password; d.currentShown=true;
            setTimeout(()=>{if(dialog===d){input.type='password';input.value=passwordLabel(d.item);d.currentShown=false;}},30000);
        } catch(e) { note(e.message,true); }
        finally { if(dialog===d)button.disabled=false; }
    };
    window.jrToggleModalPassword=() => {
        const input=document.getElementById('jr-control-password');
        if(input) input.type=input.type==='password'?'text':'password';
    };
    function setSaving(flag) {
        if(!dialog) return;
        dialog.saving=flag;
        const choice=document.getElementById('jr-editor-wifi');
        if(choice) choice.disabled=flag;
        for(const button of document.querySelectorAll('#jrDeviceControlModal .modal-footer button, #jrDeviceControlModal .btn-close')) button.disabled=flag;
    }
    window.jrSaveDeviceControl=async () => {
        const d=dialog;
        if(!d || d.saving) return;
        const wifi=d.kind==='wifi', item=d.item;
        if(!permitted(d.kind) || (!item[wifi?'ssid_writable':'username_writable'] && !item.password_writable)) return;
        const user=document.getElementById('jr-control-user').value;
        const password=document.getElementById('jr-control-password').value;
        const confirm=document.getElementById('jr-control-confirm').value;
        if(password!==confirm){note('A confirmação da nova senha não confere.',true);return;}
        const payload={action:wifi?'update_wifi':'update_account',revision:item.revision,[wifi?'interface_id':'account_id']:item.id};
        if(wifi) payload.wifi_band=bandOf(item);
        if(item[wifi?'ssid_writable':'username_writable'] && user!==String(item[wifi?'ssid':'username']??'')) payload[wifi?'ssid':'username']=user;
        if(item.password_writable && password) payload.password=password;
        if(!('ssid' in payload)&&!('username' in payload)&&!('password' in payload)){note('Nenhuma alteração foi informada.');return;}
        setSaving(true); note('Enviando alteração somente para a seleção indicada...');
        try {
            const result=await request(payload);
            if(dialog!==d)return;
            setSaving(false);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDeviceControlModal')).hide();
            toast(result.message,result.queued?'info':'success');
            setTimeout(enhanceControls,1000); // GET only; no automatic extra tasks or retries.
        } catch(e) {if(dialog===d)note(e.message,true);}
        finally {if(dialog===d)setSaving(false);}
    };
    window.jrRefreshControl=async (kind='wifi', allWifi=false) => {
        if(kind==='admin')kind='account';
        if(state.refreshBusy || !permitted(kind))return;
        const item=selected(kind);
        const scanAll=kind==='wifi' && allWifi===true;
        if(scanAll) state.scanMessage='Buscando as interfaces Wi-Fi do modem...';
        state.refreshBusy=true; renderWifi(); renderAccounts();
        try {
            const payload={action:'refresh',kind};
            if(scanAll) payload.scope='all';
            else if(item)payload[kind==='wifi'?'interface_id':'account_id']=item.id;
            const result=await request(payload);
            if(scanAll) state.scanMessage=result.message;
            toast(result.message,result.partial?'warning':'info');
            await enhanceControls();
            // Read cache again after asynchronous refresh; never submit duplicate tasks.
            if(scanAll) {
                setTimeout(enhanceControls,3000);
                setTimeout(enhanceControls,8000);
            }
        } catch(e) {
            if(scanAll) state.scanMessage=e.message;
            toast(e.message,'danger');
        }
        finally {state.refreshBusy=false;renderWifi();renderAccounts();}
    };

    /* ---------- Monitoramento ---------- */
    function fmtBytes(bytes) {
        let n=Number(bytes); if(!Number.isFinite(n)||n<0)return 'Sem leitura';
        const u=['B','KB','MB','GB','TB']; let i=0; while(n>=1024&&i<u.length-1){n/=1024;i++;}
        return (i===0?n.toFixed(0):n.toFixed(n>=100?0:n>=10?1:2))+' '+u[i];
    }
    function fmtDuration(seconds) {
        const n=Number(seconds); if(!Number.isFinite(n)||n<0)return 'N/D';
        const d=Math.floor(n/86400),h=Math.floor(n%86400/3600),m=Math.floor(n%3600/60);
        return [d?d+'d':'',h?h+'h':'',m+'min'].filter(Boolean).join(' ');
    }
    function monitoringActive() {
        const pane=document.getElementById('monitoring');
        return !!pane && pane.classList.contains('active') && pane.classList.contains('show');
    }
    function chartHtml(samples) {
        if(samples.length<1)return '<div class="jr-monitor-wait"><i class="bi bi-activity"></i><span>Aguardando uma nova contabilização da sessão...</span></div>';
        const max=Math.max(1,...samples.flatMap(s=>[s.down,s.up]));
        const pts=field=>samples.map((s,i)=>{
            const x=samples.length===1?0:i/(samples.length-1)*100;
            const y=94-(s[field]/max)*86;
            return x.toFixed(2)+','+Math.max(4,Math.min(94,y)).toFixed(2);
        }).join(' ');
        const first=new Date(samples[0].at).toLocaleTimeString('pt-BR');
        const last=new Date(samples[samples.length-1].at).toLocaleTimeString('pt-BR');
        return `<div class="jr-monitor-chart-inner">
          <div class="jr-monitor-scale"><span>${max.toFixed(2)} Mbps</span><span>${(max/2).toFixed(2)} Mbps</span><span>0 Mbps</span></div>
          <div class="jr-monitor-plot"><svg viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0 8H100M0 50H100M0 94H100" class="grid"/><polyline points="${pts('down')}" class="down"/><polyline points="${pts('up')}" class="up"/></svg><div class="jr-monitor-times"><span>${first}</span><span>${last}</span></div></div>
        </div>`;
    }

    window.renderMonitoringTab = function(device) {
        const wan = typeof getPrimaryWAN === 'function' ? getPrimaryWAN(device) : null;
        return `
        <div class="jr-monitor-v2">
          <div class="jr-monitor-head">
            <div><span class="acs-kicker"><i class="bi bi-graph-up-arrow"></i> MONITORAMENTO</span><h4>Uso da conexão em tempo real</h4><p>Velocidade calculada entre contabilizações consecutivas da sessão PPPoE.</p></div>
            <span id="jr-radius-status" class="jr-monitor-badge">AGUARDANDO</span>
          </div>
          <div class="jr-monitor-kpis">
            <div class="download"><span><i class="bi bi-arrow-down-circle"></i> Download</span><strong id="live-rx-mbps">--</strong><small>Mbps</small></div>
            <div class="upload"><span><i class="bi bi-arrow-up-circle"></i> Upload</span><strong id="live-tx-mbps">--</strong><small>Mbps</small></div>
            <div><span><i class="bi bi-database-down"></i> Baixado na sessão</span><strong id="live-rx-total">--</strong><small>RADIUS</small></div>
            <div><span><i class="bi bi-database-up"></i> Enviado na sessão</span><strong id="live-tx-total">--</strong><small>RADIUS</small></div>
          </div>
          <div class="jr-monitor-chart-card"><div class="jr-monitor-section-title"><strong>Tráfego da sessão</strong><span id="bandwidth-sample-status">Abra esta aba para iniciar as amostras.</span></div><div id="bandwidth-bars" class="jr-monitor-chart"></div></div>
          <div class="jr-monitor-report"><div class="jr-monitor-section-title"><strong>Relatório da conexão</strong><span>Sessão atual</span></div>
            <div class="jr-monitor-report-grid">
              <div><span>Usuário PPPoE</span><strong id="jr-radius-user">${esc(wan?.username || 'N/D')}</strong></div>
              <div><span>IP WAN</span><strong id="jr-radius-ip">${esc(wan?.external_ip || 'N/D')}</strong></div>
              <div><span>Interface</span><strong id="jr-radius-interface">${esc(wan?.name || 'N/D')}</strong></div>
              <div><span>Tempo de sessão</span><strong id="jr-radius-uptime">${esc(wan?.uptime ? fmtDuration(wan.uptime) : 'N/D')}</strong></div>
            </div>
          </div>
        </div>`;
    };

    window.updateRadiusBandwidthSample = async function() {
        if (!monitoringActive() || traffic.polling || !window.DEVICE_ID) return;
        const now=Date.now(); if(now-traffic.lastPoll<1800)return;
        traffic.lastPoll=now; traffic.polling=true;
        try{
            const r=await fetch('/api/get-radius-session.php?device_id='+encodeURIComponent(window.DEVICE_ID),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
            const data=await r.json();
            const badge=document.getElementById('jr-radius-status'), status=document.getElementById('bandwidth-sample-status');
            if(!data?.success||!data?.online||!data?.session){
                if(badge){badge.textContent='SEM SESSÃO';badge.classList.remove('online');}
                if(status)status.textContent=data?.message||'Nenhuma sessão PPPoE ativa.';
                return;
            }
            const s=data.session;
            if(badge){badge.textContent='ONLINE';badge.classList.add('online');}
            const down=Number(s.download_bytes), up=Number(s.upload_bytes), sec=Number(s.seconds);
            const key=String(s.session_id ?? '')+'|'+String(s.started_at ?? '')+'|'+String(s.username ?? '');
            if(traffic.sessionKey!==key){traffic.sessionKey=key;traffic.last=null;traffic.samples=[];}
            document.getElementById('live-rx-total').textContent=fmtBytes(down);
            document.getElementById('live-tx-total').textContent=fmtBytes(up);
            const set=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v??'N/D';};
            set('jr-radius-user',s.username); set('jr-radius-ip',s.ip); set('jr-radius-interface',s.interface); set('jr-radius-uptime',fmtDuration(sec));

            if(Number.isFinite(down)&&Number.isFinite(up)&&Number.isFinite(sec)){
                if(traffic.last && sec>traffic.last.sec && down>=traffic.last.down && up>=traffic.last.up){
                    const dt=sec-traffic.last.sec;
                    const downMbps=((down-traffic.last.down)*8)/(dt*1000000);
                    const upMbps=((up-traffic.last.up)*8)/(dt*1000000);
                    if(Number.isFinite(downMbps)&&Number.isFinite(upMbps)){
                        traffic.samples.push({at:Date.now(),down:downMbps,up:upMbps});
                        if(traffic.samples.length>60)traffic.samples.shift();
                        set('live-rx-mbps',downMbps.toFixed(2)); set('live-tx-mbps',upMbps.toFixed(2));
                        if(status)status.textContent='Atualizado às '+new Date().toLocaleTimeString('pt-BR')+' • intervalo RADIUS '+dt+'s';
                    }
                } else if(!traffic.last && status) status.textContent='Primeira contabilização recebida; aguardando a próxima.';
                traffic.last={down,up,sec};
            }
            const chart=document.getElementById('bandwidth-bars'); if(chart)chart.innerHTML=chartHtml(traffic.samples);
        }catch(e){
            const status=document.getElementById('bandwidth-sample-status'); if(status)status.textContent='Falha ao consultar a sessão: '+e.message;
        }finally{traffic.polling=false;}
    };

    const originalLoad = window.loadDeviceDetail;
    if (typeof originalLoad === 'function') {
        window.loadDeviceDetail = async function(...args) {
            const result = await originalLoad.apply(this,args);
            await enhanceControls();
            return result;
        };
        try { loadDeviceDetail = window.loadDeviceDetail; } catch (_) {}
    }

    document.addEventListener('DOMContentLoaded', () => {
        ensureModal();
        setTimeout(enhanceControls, 500);
        document.getElementById('monitoring-tab')?.addEventListener('shown.bs.tab', () => {
            traffic.last=null; traffic.samples=[]; traffic.sessionKey=null;
            window.updateRadiusBandwidthSample();
        });
    });
})();