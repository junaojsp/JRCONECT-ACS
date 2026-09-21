/* JR CONECT - selected Wi-Fi and router account controls; monitoring preserved. */
(() => {
    'use strict';

    const state = { csrf: '', wifi: [], accounts: [], permissions: {}, loading: null, wifiId: '', accountId: '', refreshBusy: false, wifiBand: '', wifiByBand: {}, scanMessage: '' };
    const traffic = { sessionKey: null, last: null, samples: [], polling: false, lastPoll: 0, online: false, lastAccountingAt: null, latestSession: null, ixcLoginId: null, livePolling: false, liveLastPoll: 0, liveAvailable: false, liveDisabledUntil: 0, liveReason: null };
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
    function wifiCardForBand(band) {
        const rows=wifiRows(band);
        const item=rows.find(row=>row.id===state.wifiByBand[band]) || rows.find(row=>row.enabled===true) || rows[0] || null;
        const label=wifiBandNames[band] || band;

        if(!item) {
            return `<article class="jr-wifi-summary-row missing">
                <div class="jr-wifi-row-main">
                    <div class="jr-wifi-row-title">
                        <i class="bi bi-wifi"></i>
                        <div><strong>${esc(label)}</strong><span>Nenhuma interface identificada</span></div>
                    </div>
                </div>
                <div class="jr-wifi-row-actions">
                    <button type="button" class="acs-soft-btn" onclick="jrRefreshControl('wifi',true)" ${state.refreshBusy || !permitted('wifi')?'disabled':''}>
                        <i class="bi bi-arrow-repeat"></i> Detectar
                    </button>
                </div>
            </article>`;
        }

        const active=item.enabled===true;
        const channel=item.auto_channel===true?'Automático':val(item.channel);
        const clients=val(item.clients ?? item.associated_devices ?? item.total_associations ?? 'Não informado');

        return `<article class="jr-wifi-summary-row">
            <div class="jr-wifi-row-main">
                <div class="jr-wifi-row-title">
                    <i class="bi bi-wifi"></i>
                    <div>
                        <strong>${esc(label)}</strong>
                        <span class="${active?'on':'off'}">${esc(statusLabel(item).toUpperCase())}</span>
                    </div>
                </div>

                <div class="jr-wifi-row-data">
                    <div><span>SSID</span><strong>${esc(val(item.ssid))}</strong></div>
                    <div><span>Canal</span><strong>${esc(channel)}</strong></div>
                    <div><span>Segurança</span><strong>${esc(securityLabel(item.security))}</strong></div>
                    <div><span>Clientes</span><strong>${esc(clients)}</strong></div>
                </div>
            </div>

            <div class="jr-wifi-row-actions">
                <button type="button" class="acs-soft-btn primary" onclick="jrManageBand('${band}')" ${!permitted('wifi')?'disabled':''}>
                    <i class="bi bi-sliders"></i> Gerenciar
                </button>
            </div>
        </article>`;
    }

    function unifiedSummaryCard() {
        return `<article class="jr-wifi-summary-row unified">
            <div class="jr-wifi-row-main">
                <div class="jr-wifi-row-title">
                    <i class="bi bi-diagram-3"></i>
                    <div>
                        <strong>Rede Unificada</strong>
                        <span>SMART CONNECT</span>
                    </div>
                </div>

                <div class="jr-wifi-row-data">
                    <div><span>Tipo</span><strong>Band Steering</strong></div>
                    <div><span>Bandas</span><strong>Conforme equipamento</strong></div>
                    <div><span>Tecnologia</span><strong>Wi-Fi 6/7 se suportado</strong></div>
                    <div><span>Estado</span><strong>Consultar modem</strong></div>
                </div>
            </div>

            <div class="jr-wifi-row-actions">
                <button type="button" class="acs-soft-btn primary" onclick="jrOpenUnifiedWifi()">
                    <i class="bi bi-sliders"></i> Gerenciar
                </button>
            </div>
        </article>`;
    }

    window.jrManageBand=band => {
        if(!Object.prototype.hasOwnProperty.call(wifiBandNames,band)) return;
        state.wifiBand=band;
        const rows=wifiRows(band);
        const item=rows.find(row=>row.id===state.wifiByBand[band]) || rows.find(row=>row.enabled===true) || rows[0];
        if(!item){ toast('Nenhuma rede '+wifiBandNames[band]+' foi identificada.','danger'); return; }
        state.wifiId=item.id;
        state.wifiByBand[band]=item.id;
        window.jrOpenControl('wifi');
    };

    window.jrOpenUnifiedWifi=() => {
        const section=document.querySelector('.acs-approved-wifi');
        if(typeof window.jrShowUnifiedPanel==='function'){
            window.jrShowUnifiedPanel();
            section?.scrollIntoView({block:'center',behavior:'smooth'});
            return;
        }
        toast('A identificação da rede unificada ainda não carregou. Atualize a leitura e tente novamente.','info');
    };

    function renderWifi() {
        const box=document.querySelector('.acs-approved-wifi .acs-wifi-reference-grid');
        if(!box)return;
        syncWifiSelection();
        box.innerHTML=`<div class="jr-wifi-summary-shell">
            <div class="jr-wifi-summary-list">
                ${wifiCardForBand('2.4')}
                ${wifiCardForBand('5')}
                ${unifiedSummaryCard()}
            </div>
            <div class="jr-wifi-summary-footer">
                <div>
                    <button type="button" class="acs-soft-btn" id="jr-detect-wifi" onclick="jrRefreshControl('wifi',true)" ${state.refreshBusy || !permitted('wifi')?'disabled':''}>
                        <i class="bi bi-arrow-repeat"></i> ${state.refreshBusy?'Consultando...':'Detectar redes do modem'}
                    </button>
                    <button type="button" id="jr-wifi-diagnostic" class="acs-soft-btn" onclick="jrDownloadWifiDiagnostic()" ${!permitted('wifi')?'disabled':''}>
                        <i class="bi bi-file-earmark-pdf"></i> Diagnóstico PDF
                    </button>
                </div>
                <div class="jr-wifi-health-strip" aria-live="polite">
                    <span><i class="bi bi-wifi"></i> <strong>${state.wifi.length}</strong> rede(s) identificada(s)</span>
                    <span><i class="bi bi-shield-check"></i> Leitura via TR-069</span>
                    <span><i class="bi bi-${state.refreshBusy ? 'arrow-repeat' : 'clock-history'}"></i> ${state.refreshBusy ? 'Consulta em andamento' : 'Dados disponíveis para suporte'}</span>
                </div>
                <small>SSID e senha podem ser alterados quando o modem confirma escrita. Canal e segurança permanecem somente leitura até o parâmetro gravável ser validado.</small>
                <p class="jr-wifi-scan-status" role="status" aria-live="polite">${esc(state.scanMessage)}</p>
            </div>
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
            <div class="modal-footer"><button type="button" class="acs-soft-btn" data-bs-dismiss="modal"><i class="bi bi-arrow-left"></i> Voltar para Redes Wi-Fi</button><button id="jr-control-save" type="button" class="acs-soft-btn primary" onclick="jrSaveDeviceControl()"><i class="bi bi-check2"></i> Salvar nesta seleção</button></div>
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
    function wifiDiagnosticPdf(result) {
        const printable = value => String(value ?? '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
            .replace(/[\\()]/g, ch => '\\' + ch)
            .replace(/[^\x20-\x7E]/g,'?');
        const lines = ['JR CONECT TELECOM - DIAGNOSTICO WI-FI', 'Gerado em: ' + new Date().toLocaleString('pt-BR'), 'Origem: ACS / TR-069', ''];
        const add = (value, indent=0, label='') => {
            if (value === null || value === undefined || value === '') {
                lines.push(' '.repeat(indent) + label + 'Nao informado'); return;
            }
            if (Array.isArray(value)) {
                if (label) lines.push(' '.repeat(indent) + label);
                if (!value.length) lines.push(' '.repeat(indent + 2) + 'Sem registros');
                value.forEach((item,index) => add(item, indent + 2, '[' + (index + 1) + '] '));
                return;
            }
            if (typeof value === 'object') {
                if (label) lines.push(' '.repeat(indent) + label);
                Object.entries(value).forEach(([key,item]) => add(item, indent + 2, key + ': '));
                return;
            }
            const prefix = ' '.repeat(indent) + label;
            const text = prefix + String(value);
            for (let offset=0; offset<text.length; offset+=92) lines.push(text.slice(offset, offset+92));
        };
        add(result.diagnostic || {}, 0);
        const pageLines=46, pages=[];
        for(let i=0;i<lines.length;i+=pageLines) pages.push(lines.slice(i,i+pageLines));
        const objects=['<< /Type /Catalog /Pages 2 0 R >>',''];
        const pageIds=[];
        pages.forEach((page,index) => {
            const pageId=3 + index * 2, contentId=pageId + 1;
            pageIds.push(pageId + ' 0 R');
            const content=['BT','/F1 10 Tf','50 795 Td'];
            page.forEach((line,lineIndex) => {
                if(lineIndex) content.push('0 -15 Td');
                content.push('(' + printable(line) + ') Tj');
            });
            content.push('ET');
            const stream=content.join('\n');
            objects[pageId-1]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents ' + contentId + ' 0 R >>';
            objects[contentId-1]='<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream';
        });
        objects[1]='<< /Type /Pages /Kids [' + pageIds.join(' ') + '] /Count ' + pages.length + ' >>';
        let pdf='%PDF-1.4\n', offsets=[0];
        objects.forEach((object,index) => { offsets[index+1]=pdf.length; pdf+=(index+1)+' 0 obj\n'+object+'\nendobj\n'; });
        const xref=pdf.length;
        pdf+='xref\n0 '+(objects.length+1)+'\n0000000000 65535 f \n';
        offsets.slice(1).forEach(offset => { pdf+=String(offset).padStart(10,'0')+' 00000 n \n'; });
        pdf+='trailer\n<< /Size '+(objects.length+1)+' /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF';
        return new Blob([pdf], {type:'application/pdf'});
    }
    window.jrDownloadWifiDiagnostic=async () => {
        if(!permitted('wifi')) return;
        const button=document.getElementById('jr-wifi-diagnostic');
        if(button) button.disabled=true;
        try {
            const result=await request({action:'wifi_diagnostics',kind:'wifi'});
            const blob=wifiDiagnosticPdf(result);
            const url=URL.createObjectURL(blob), anchor=document.createElement('a');
            anchor.href=url; anchor.download='diagnostico-wifi-jrconect.pdf';
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
    function monitorCustomerPlanName() {
        try {
            return typeof cachedCustomerSummary!=='undefined'
                ? String(cachedCustomerSummary?.contract?.plan || '')
                : '';
        } catch(_) { return ''; }
    }
    function monitorPlanMbps() {
        const plan=monitorCustomerPlanName().trim();
        if(!plan)return null;
        const m=plan.match(/(\d+(?:[.,]\d+)?)\s*(GIGA|GB|G|MEGA|MB|M)?/i);
        if(!m)return null;
        let value=Number(String(m[1]).replace(',','.'));
        if(!Number.isFinite(value)||value<=0)return null;
        const unit=String(m[2]||'M').toUpperCase();
        if(unit==='GIGA'||unit==='GB'||unit==='G')value*=1000;
        return value;
    }
    function monitorPlanLabel() {
        const mbps=monitorPlanMbps();
        const name=monitorCustomerPlanName();
        if(mbps!==null)return mbps.toLocaleString('pt-BR',{maximumFractionDigits:1})+' Mbps';
        return name || 'Não identificado';
    }
    function parseRadiusTime(value) {
        if(!value)return null;
        const t=new Date(String(value).trim().replace(' ','T')).getTime();
        return Number.isFinite(t)?t:null;
    }
    function monitorStats() {
        const samples=traffic.samples.filter(s=>Number.isFinite(s.down)&&Number.isFinite(s.up));
        if(!samples.length)return {latest:null,peakDown:null,peakUp:null,avgDown:null,avgUp:null};
        const latest=samples[samples.length-1];
        const peakDown=Math.max(...samples.map(s=>s.down));
        const peakUp=Math.max(...samples.map(s=>s.up));
        const avgDown=samples.reduce((a,s)=>a+s.down,0)/samples.length;
        const avgUp=samples.reduce((a,s)=>a+s.up,0)/samples.length;
        return {latest,peakDown,peakUp,avgDown,avgUp};
    }
    function fmtMbps(value) {
        const n=Number(value);
        return Number.isFinite(n)?n.toFixed(n>=100?1:2):'--';
    }
    function fmtAccountingAge(ms) {
        if(!Number.isFinite(ms)||ms<0)return 'N/D';
        const seconds=Math.floor(ms/1000);
        if(seconds<60)return seconds+'s atrás';
        const minutes=Math.floor(seconds/60);
        if(minutes<60)return minutes+'min atrás';
        return Math.floor(minutes/60)+'h atrás';
    }
    function setMonitorText(id,value) {
        const el=document.getElementById(id);
        if(el)el.textContent=value??'N/D';
    }
    function monitorDiagnosis() {
        const stats=monitorStats();
        const plan=monitorPlanMbps();
        const usage=plan&&stats.latest?Math.max(0,(stats.latest.down/plan)*100):null;
        const age=traffic.lastAccountingAt?Date.now()-traffic.lastAccountingAt:null;
        let level='collecting',title='Coletando dados',text='Aguardando contabilizações consecutivas do IXC/RADIUS.';

        if(!traffic.online){
            level='danger'; title='Sem sessão PPPoE'; text='Nenhuma sessão RADIUS ativa foi confirmada para este cliente.';
        } else if(stats.latest){
            if(age!==null && age>180000){
                level='warning'; title='Contabilização atrasada';
                text='A sessão está ativa, mas a última contabilização do RADIUS está há '+fmtAccountingAge(age)+'.';
            } else if(usage!==null && usage>=90){
                level='warning'; title='Uso elevado do plano';
                text='O download atual está usando aproximadamente '+Math.round(usage)+'% da velocidade nominal do plano.';
            } else {
                level='ok'; title='Sessão normal';
                text=usage===null
                    ? 'Sessão ativa e contabilização sendo recebida. O plano não foi identificado para calcular o percentual de uso.'
                    : 'Sessão ativa, contabilização atualizada e uso atual em aproximadamente '+Math.round(usage)+'% do plano.';
            }
        }

        const box=document.getElementById('jr-monitor-diagnosis');
        if(box){
            box.classList.remove('ok','warning','danger','collecting');
            box.classList.add(level);
        }
        setMonitorText('jr-monitor-diagnosis-title',title);
        setMonitorText('jr-monitor-diagnosis-text',text);
        return {usage,age};
    }
    function updateMonitoringInsights() {
        const stats=monitorStats();
        const plan=monitorPlanMbps();
        const diagnosis=monitorDiagnosis();

        setMonitorText('jr-monitor-plan',monitorPlanLabel());
        setMonitorText('jr-monitor-plan-use',diagnosis.usage===null?'Uso atual: aguardando amostra':'Uso atual: '+Math.min(999,diagnosis.usage).toFixed(0)+'%');
        setMonitorText('jr-monitor-peak',stats.peakDown===null?'--':fmtMbps(stats.peakDown)+' ↓ / '+fmtMbps(stats.peakUp)+' ↑');
        setMonitorText('jr-monitor-average',stats.avgDown===null?'--':fmtMbps(stats.avgDown)+' ↓ / '+fmtMbps(stats.avgUp)+' ↑');
        setMonitorText('jr-monitor-last-accounting',traffic.lastAccountingAt?new Date(traffic.lastAccountingAt).toLocaleString('pt-BR'):'N/D');
        setMonitorText('jr-monitor-last-accounting-text',traffic.lastAccountingAt?new Date(traffic.lastAccountingAt).toLocaleString('pt-BR'):'N/D');
        setMonitorText('jr-monitor-accounting-age',traffic.lastAccountingAt?fmtAccountingAge(Date.now()-traffic.lastAccountingAt):'Aguardando');
        setMonitorText('jr-monitor-source','IXC/RADIUS');
        setMonitorText('jr-monitor-bras',traffic.latestSession?.bras || 'N/D');

        const usageBar=document.getElementById('jr-monitor-plan-bar');
        if(usageBar){
            const pct=diagnosis.usage===null?0:Math.max(0,Math.min(100,diagnosis.usage));
            usageBar.style.width=pct+'%';
            usageBar.classList.toggle('high',pct>=90);
        }

        // Latência/perda/jitter só serão preenchidos quando houver diagnóstico CPE real.
        setMonitorText('jr-monitor-latency','Não coletada');
        setMonitorText('jr-monitor-loss','Não coletada');
        setMonitorText('jr-monitor-jitter','Não coletado');
    }
    function chartHtml(samples) {
        if(samples.length<1)return '<div class="jr-monitor-wait"><i class="bi bi-activity"></i><span>Aguardando uma nova contabilização da sessão...</span></div>';
        const plan=monitorPlanMbps();
        const sampleMax=Math.max(1,...samples.flatMap(s=>[s.down,s.up]));
        const max=Math.max(sampleMax,plan||0,1);
        const pts=field=>samples.map((s,i)=>{
            const x=samples.length===1?0:i/(samples.length-1)*100;
            const y=94-(s[field]/max)*86;
            return x.toFixed(2)+','+Math.max(4,Math.min(94,y)).toFixed(2);
        }).join(' ');
        const first=new Date(samples[0].at).toLocaleTimeString('pt-BR');
        const last=new Date(samples[samples.length-1].at).toLocaleTimeString('pt-BR');
        let planLine='';
        if(plan){
            const y=94-(plan/max)*86;
            planLine='<line x1="0" x2="100" y1="'+Math.max(4,Math.min(94,y)).toFixed(2)+'" y2="'+Math.max(4,Math.min(94,y)).toFixed(2)+'" class="plan"/>';
        }
        return '<div class="jr-monitor-chart-inner">'+
          '<div class="jr-monitor-scale"><span>'+max.toFixed(1)+' Mbps</span><span>'+(max/2).toFixed(1)+' Mbps</span><span>0 Mbps</span></div>'+
          '<div class="jr-monitor-plot"><svg viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0 8H100M0 50H100M0 94H100" class="grid"/>'+planLine+'<polyline points="'+pts('down')+'" class="down"/><polyline points="'+pts('up')+'" class="up"/></svg><div class="jr-monitor-times"><span>'+first+'</span><span>'+last+'</span></div></div>'+
          '</div><div class="jr-monitor-chart-legend"><span><i class="down"></i>Download</span><span><i class="up"></i>Upload</span>'+(plan?'<span><i class="plan"></i>Plano '+plan.toLocaleString('pt-BR')+' Mbps</span>':'')+'</div>';
    }

    window.renderMonitoringTab = function(device) {
        const wan = typeof getPrimaryWAN === 'function' ? getPrimaryWAN(device) : null;
        return '<div class="jr-monitor-v2">'+
          '<div class="jr-monitor-head">'+
            '<div><span class="acs-kicker"><i class="bi bi-graph-up-arrow"></i> MONITORAMENTO · IXC/RADIUS</span><h4>Uso da conexão em tempo real</h4><p>Velocidade calculada somente quando uma nova contabilização da sessão PPPoE é recebida.</p></div>'+
            '<div class="jr-monitor-head-actions"><span id="jr-radius-status" class="jr-monitor-badge">AGUARDANDO</span><button type="button" class="jr-monitor-refresh" onclick="window.updateRadiusBandwidthSample(true)"><i class="bi bi-arrow-clockwise"></i> Atualizar agora</button></div>'+
          '</div>'+
          '<div class="jr-monitor-kpis">'+
            '<div class="download"><span><i class="bi bi-arrow-down-circle"></i> Download</span><strong id="live-rx-mbps">--</strong><small>Mbps agora</small></div>'+
            '<div class="upload"><span><i class="bi bi-arrow-up-circle"></i> Upload</span><strong id="live-tx-mbps">--</strong><small>Mbps agora</small></div>'+
            '<div><span><i class="bi bi-database-down"></i> Baixado na sessão</span><strong id="live-rx-total">--</strong><small>IXC/RADIUS</small></div>'+
            '<div><span><i class="bi bi-database-up"></i> Enviado na sessão</span><strong id="live-tx-total">--</strong><small>IXC/RADIUS</small></div>'+
          '</div>'+
          '<div class="jr-monitor-insights">'+
            '<div><span>Plano contratado</span><strong id="jr-monitor-plan">'+esc(monitorPlanLabel())+'</strong><small id="jr-monitor-plan-use">Uso atual: aguardando amostra</small><div class="jr-monitor-plan-track"><i id="jr-monitor-plan-bar"></i></div></div>'+
            '<div><span>Pico desde abertura</span><strong id="jr-monitor-peak">--</strong><small>Download ↓ / Upload ↑ em Mbps</small></div>'+
            '<div><span>Média desde abertura</span><strong id="jr-monitor-average">--</strong><small>Download ↓ / Upload ↑ em Mbps</small></div>'+
            '<div><span>Última contabilização</span><strong id="jr-monitor-accounting-age">Aguardando</strong><small id="jr-monitor-last-accounting-text">N/D</small></div>'+
          '</div>'+
          '<div id="jr-monitor-diagnosis" class="jr-monitor-diagnosis collecting">'+
            '<div><span>Diagnóstico automático da sessão</span><strong id="jr-monitor-diagnosis-title">Coletando dados</strong><p id="jr-monitor-diagnosis-text">Aguardando contabilizações consecutivas do IXC/RADIUS.</p></div>'+
            '<div class="jr-monitor-quality"><div><span>Latência</span><strong id="jr-monitor-latency">Não coletada</strong></div><div><span>Perda</span><strong id="jr-monitor-loss">Não coletada</strong></div><div><span>Jitter</span><strong id="jr-monitor-jitter">Não coletado</strong></div></div>'+
          '</div>'+
          '<div class="jr-monitor-chart-card"><div class="jr-monitor-section-title"><strong>Tráfego da sessão</strong><span id="bandwidth-sample-status">Abra esta aba para iniciar as amostras.</span></div><div id="bandwidth-bars" class="jr-monitor-chart"></div></div>'+
          '<div class="jr-monitor-report"><div class="jr-monitor-section-title"><strong>Relatório da conexão</strong><span>Sessão atual</span></div>'+
            '<div class="jr-monitor-report-grid">'+
              '<div><span>Usuário PPPoE</span><strong id="jr-radius-user">'+esc(wan?.username || 'N/D')+'</strong></div>'+
              '<div><span>IP WAN</span><strong id="jr-radius-ip">'+esc(wan?.external_ip || 'N/D')+'</strong></div>'+
              '<div><span>Interface</span><strong id="jr-radius-interface">'+esc(wan?.name || 'N/D')+'</strong></div>'+
              '<div><span>Tempo de sessão</span><strong id="jr-radius-uptime">'+esc(wan?.uptime ? fmtDuration(wan.uptime) : 'N/D')+'</strong></div>'+
              '<div><span>Plano</span><strong id="jr-radius-plan">'+esc(monitorPlanLabel())+'</strong></div>'+
              '<div><span>BRAS / Concentrador</span><strong id="jr-monitor-bras">N/D</strong></div>'+
              '<div><span>Fonte</span><strong id="jr-monitor-source">IXC/RADIUS</strong></div>'+
              '<div><span>Contabilização</span><strong id="jr-monitor-last-accounting">N/D</strong></div>'+
            '</div>'+
          '</div>'+
        '</div>';
    };

    window.updateIxcLiveTraffic = async function(force=false) {
        if(!monitoringActive() || traffic.livePolling || !traffic.ixcLoginId) return;

        const now=Date.now();
        if(!force && now-traffic.liveLastPoll<1800) return;
        if(!force && traffic.liveDisabledUntil>now) return;

        traffic.liveLastPoll=now;
        traffic.livePolling=true;

        try{
            const r=await fetch(
                '/api/get-ixc-live-traffic.php?login_id='+encodeURIComponent(traffic.ixcLoginId),
                {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}
            );
            const data=await r.json();

            if(!data?.success || !data?.available || !data?.live){
                traffic.liveAvailable=false;
                traffic.liveReason=data?.reason || 'unavailable';

                if(data?.reason==='web_session_required'){
                    traffic.liveDisabledUntil=Date.now()+60000;
                } else {
                    traffic.liveDisabledUntil=Date.now()+15000;
                }

                const source=document.getElementById('jr-monitor-source');
                if(source && source.textContent!=='IXC/RADIUS') source.textContent='IXC/RADIUS';

                return;
            }

            traffic.liveAvailable=true;
            traffic.liveReason=null;
            traffic.liveDisabledUntil=0;

            const down=Number(data.live.download_mbps);
            const up=Number(data.live.upload_mbps);
            if(!Number.isFinite(down)||!Number.isFinite(up)) return;

            const at=parseRadiusTime(data.live.sample_time) || Date.now();

            traffic.samples.push({at,down,up,source:'ixc-live'});
            if(traffic.samples.length>90) traffic.samples.shift();

            setMonitorText('live-rx-mbps',fmtMbps(down));
            setMonitorText('live-tx-mbps',fmtMbps(up));
            setMonitorText('jr-monitor-source','IXC / Concentrador ao vivo');

            const status=document.getElementById('bandwidth-sample-status');
            if(status){
                status.textContent='AO VIVO IXC • '+new Date(at).toLocaleTimeString('pt-BR')+' • atualização pelo concentrador';
            }

            const chart=document.getElementById('bandwidth-bars');
            if(chart) chart.innerHTML=chartHtml(traffic.samples);

            updateMonitoringInsights();
        }catch(e){
            traffic.liveAvailable=false;
            traffic.liveReason='request_failed';
            traffic.liveDisabledUntil=Date.now()+15000;
        }finally{
            traffic.livePolling=false;
        }
    };

    window.updateRadiusBandwidthSample = async function(force=false) {
        if (!monitoringActive() || traffic.polling || !window.DEVICE_ID) return;
        const now=Date.now();
        if(!force && now-traffic.lastPoll<4500)return;
        traffic.lastPoll=now; traffic.polling=true;
        try{
            const r=await fetch('/api/get-radius-session.php?device_id='+encodeURIComponent(window.DEVICE_ID),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
            const data=await r.json();
            const badge=document.getElementById('jr-radius-status'), status=document.getElementById('bandwidth-sample-status');
            if(!data?.success||!data?.online||!data?.session){
                traffic.online=false; traffic.latestSession=null;
                if(badge){badge.textContent='SEM SESSÃO';badge.classList.remove('online');}
                setMonitorText('live-rx-mbps','--');
                setMonitorText('live-tx-mbps','--');
                setMonitorText('live-rx-total','--');
                setMonitorText('live-tx-total','--');
                if(status)status.textContent=data?.message||'Nenhuma sessão PPPoE ativa.';
                updateMonitoringInsights();
                return;
            }

            const s=data.session;
            traffic.online=true; traffic.latestSession=s;
            traffic.ixcLoginId=data.ixc_login_id || traffic.ixcLoginId || null;
            if(badge){badge.textContent='ONLINE';badge.classList.add('online');}

            // RADIUS: output = download do assinante; input = upload do assinante.
            const down=Number(s.download_bytes), up=Number(s.upload_bytes), sec=Number(s.seconds);
            const key=String(s.session_id ?? '')+'|'+String(s.started_at ?? '')+'|'+String(s.username ?? '');
            if(traffic.sessionKey!==key){
                traffic.sessionKey=key; traffic.last=null; traffic.samples=[]; traffic.lastAccountingAt=null;
            }

            const accountAt=parseRadiusTime(s.sample_time) || now;
            traffic.lastAccountingAt=accountAt;

            const rxTotal=document.getElementById('live-rx-total'), txTotal=document.getElementById('live-tx-total');
            if(rxTotal)rxTotal.textContent=fmtBytes(down);
            if(txTotal)txTotal.textContent=fmtBytes(up);

            setMonitorText('jr-radius-user',s.username);
            setMonitorText('jr-radius-ip',s.ip);
            setMonitorText('jr-radius-interface',s.interface);
            setMonitorText('jr-radius-uptime',fmtDuration(sec));
            setMonitorText('jr-radius-plan',monitorPlanLabel());
            setMonitorText('jr-monitor-bras',s.bras || 'N/D');

            let newSample=false;
            if(Number.isFinite(down)&&Number.isFinite(up)&&Number.isFinite(sec)){
                if(traffic.last && sec>traffic.last.sec && down>=traffic.last.down && up>=traffic.last.up){
                    const dt=sec-traffic.last.sec;
                    const downMbps=((down-traffic.last.down)*8)/(dt*1000000);
                    const upMbps=((up-traffic.last.up)*8)/(dt*1000000);
                    if(Number.isFinite(downMbps)&&Number.isFinite(upMbps)){
                        if(!traffic.liveAvailable){
                            traffic.samples.push({at:accountAt,down:downMbps,up:upMbps,source:'radius'});
                            if(traffic.samples.length>60)traffic.samples.shift();
                            setMonitorText('live-rx-mbps',fmtMbps(downMbps));
                            setMonitorText('live-tx-mbps',fmtMbps(upMbps));
                            if(status)status.textContent='Fallback RADIUS • nova contabilização às '+new Date(accountAt).toLocaleTimeString('pt-BR')+' • intervalo '+dt+'s';
                            newSample=true;
                        }
                    }
                } else if(!traffic.last && status) {
                    status.textContent='Primeira contabilização recebida; aguardando a próxima.';
                }
                traffic.last={down,up,sec,accountAt};
            }

            if(!newSample && traffic.last && status && traffic.samples.length){
                status.textContent='Última contabilização '+fmtAccountingAge(Date.now()-accountAt)+' • aguardando a próxima.';
            }

            const chart=document.getElementById('bandwidth-bars');
            if(chart)chart.innerHTML=chartHtml(traffic.samples);
            updateMonitoringInsights();
            window.updateIxcLiveTraffic(true);
        }catch(e){
            const status=document.getElementById('bandwidth-sample-status');
            if(status)status.textContent='Falha ao consultar a sessão: '+e.message;
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
        window.setInterval(() => {
            if (typeof window.updateIxcLiveTraffic === 'function') {
                window.updateIxcLiveTraffic();
            }
        }, 2000);
        document.getElementById('monitoring-tab')?.addEventListener('shown.bs.tab', () => {
            traffic.last=null; traffic.samples=[]; traffic.sessionKey=null;
            traffic.ixcLoginId=null; traffic.liveAvailable=false; traffic.liveReason=null; traffic.liveDisabledUntil=0;
            window.updateRadiusBandwidthSample(true);
        });
    });
})();