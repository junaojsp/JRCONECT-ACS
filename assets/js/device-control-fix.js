/* JR CONECT - reparo de leitura/edicao do CPE e monitoramento */
(() => {
    'use strict';

    const state = { csrf: '', wifi: [], accounts: [], permissions: {}, loading: false };
    const traffic = { sessionKey: null, last: null, samples: [], polling: false, lastPoll: 0 };

    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
    const val = value => (value === null || value === undefined || value === '' ? 'Não disponível' : String(value));

    function toast(message, type='info') {
        if (typeof window.showToast === 'function') window.showToast(message, type);
        else console[type === 'danger' ? 'error' : 'log']('[CPE]', message);
    }

    async function controlGet() {
        const r = await fetch('/api/device-control.php?device_id=' + encodeURIComponent(window.DEVICE_ID || ''), {
            credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
        });
        const raw = await r.text();
        let data;
        try { data = JSON.parse(raw); } catch (_) { throw new Error('Resposta inválida do controle do equipamento.'); }
        if (!r.ok || !data.success) throw new Error(data.message || 'Falha ao ler capacidades do equipamento.');
        return data;
    }

    async function controlPost(payload) {
        const r = await fetch('/api/device-control.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': state.csrf
            },
            body: JSON.stringify({ device_id: window.DEVICE_ID || '', ...payload })
        });
        const raw = await r.text();
        let data;
        try { data = JSON.parse(raw); } catch (_) { throw new Error('Resposta inválida do controle do equipamento.'); }
        if (!r.ok || !data.success) throw new Error(data.message || 'Operação não concluída.');
        return data;
    }

    function passwordLabel(item) {
        if (item.password_state === 'available') return '••••••••';
        if (item.password_state === 'concealed') return 'Oculta pelo equipamento';
        return 'Não disponível';
    }

    function wifiCard(item, index) {
        const writable = !!(item.ssid_writable || item.password_writable);
        const enabled = item.enabled === false ? 'DESABILITADA' : item.enabled === true ? 'HABILITADA' : 'STATUS N/D';
        const channel = item.auto_channel === true ? 'Automático' : val(item.channel);
        return `
            <div class="acs-wifi-band jr-wifi-v2" data-wifi-id="${esc(item.id)}">
                <div class="acs-wifi-band-title">
                    <i class="bi bi-wifi"></i>
                    <strong>${esc(item.label || ('Rede Wi-Fi ' + (index + 1)))}</strong>
                    <span class="${item.enabled === false ? 'off' : ''}">${enabled}</span>
                </div>
                <div class="acs-reference-list compact">
                    <div><span>SSID</span><strong>${esc(val(item.ssid))}</strong></div>
                    <div><span>Canal</span><strong>${esc(channel)}</strong></div>
                    <div><span>Segurança</span><strong>${esc(val(item.security))}</strong></div>
                    <div>
                        <span>Senha</span>
                        <strong class="jr-secret-row">
                            <span id="jr-wifi-pass-${index}">${esc(passwordLabel(item))}</span>
                            ${item.password_state === 'available' ? `<button type="button" class="acs-eye-btn" onclick="jrToggleWifiSecret(${index})"><i class="bi bi-eye"></i></button>` : ''}
                        </strong>
                    </div>
                </div>
                <div class="jr-control-actions">
                    ${writable && state.permissions.wifi ? `<button type="button" class="acs-soft-btn" onclick="jrEditWifi(${index})"><i class="bi bi-pencil"></i> Editar rede</button>` : '<span class="jr-readonly"><i class="bi bi-lock"></i> Somente leitura</span>'}
                </div>
            </div>`;
    }

    function renderWifi() {
        const container = document.querySelector('.acs-approved-wifi .acs-wifi-reference-grid');
        if (!container) return;
        if (!state.wifi.length) {
            container.innerHTML = '<div class="jr-empty-control"><i class="bi bi-wifi-off"></i><span>Nenhuma interface Wi-Fi foi coletada deste equipamento.</span></div>';
            return;
        }
        container.innerHTML = state.wifi.map(wifiCard).join('');
    }

    function accountRow(item, index) {
        const canEdit = state.permissions.admin && (item.username_writable || item.password_writable);
        return `
            <div class="jr-account-block">
                <div class="jr-account-head">
                    <strong>${esc(item.label || 'Conta do equipamento')}</strong>
                    ${canEdit ? `<button type="button" class="acs-soft-btn jr-mini" onclick="jrEditAccount(${index})"><i class="bi bi-pencil"></i> Editar</button>` : '<span class="jr-readonly"><i class="bi bi-lock"></i> Somente leitura</span>'}
                </div>
                <div><span>Usuário</span><strong>${esc(val(item.username))}</strong></div>
                <div>
                    <span>Senha</span>
                    <strong class="jr-secret-row">
                        <span id="jr-account-pass-${index}">${esc(passwordLabel(item))}</span>
                        ${item.password_state === 'available' ? `<button type="button" class="acs-eye-btn" onclick="jrToggleAccountSecret(${index})"><i class="bi bi-eye"></i></button>` : ''}
                    </strong>
                </div>
            </div>`;
    }

    function renderAccounts() {
        const container = document.querySelector('.acs-approved-admin .acs-reference-list.credentials');
        const button = document.getElementById('get-credentials-btn');
        if (!container) return;
        if (!state.permissions.admin) {
            container.innerHTML = '<div class="jr-empty-control"><i class="bi bi-shield-lock"></i><span>Credenciais administrativas disponíveis apenas para NOC/Admin.</span></div>';
        } else if (!state.accounts.length) {
            container.innerHTML = '<div class="jr-empty-control"><i class="bi bi-key"></i><span>O equipamento ainda não informou uma conta administrativa compatível.</span></div>';
        } else {
            container.innerHTML = state.accounts.map(accountRow).join('');
        }
        if (button) {
            button.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Atualizar leitura';
            button.onclick = () => window.jrRefreshControl('admin');
        }
    }

    async function enhanceControls() {
        if (state.loading || !window.DEVICE_ID) return;
        state.loading = true;
        try {
            const data = await controlGet();
            state.csrf = data.csrf || '';
            state.wifi = Array.isArray(data.wifi) ? data.wifi : [];
            state.accounts = Array.isArray(data.accounts) ? data.accounts : [];
            state.permissions = data.permissions || {};
            renderWifi();
            renderAccounts();
        } catch (e) {
            console.error('[CPE CONTROL]', e);
            const wifi = document.querySelector('.acs-approved-wifi .acs-wifi-reference-grid');
            if (wifi) wifi.innerHTML = '<div class="jr-empty-control danger"><i class="bi bi-exclamation-triangle"></i><span>' + esc(e.message) + '</span></div>';
        } finally {
            state.loading = false;
        }
    }

    function ensureModal() {
        if (document.getElementById('jrDeviceControlModal')) return;
        document.body.insertAdjacentHTML('beforeend', `
        <div class="modal fade jr-control-modal" id="jrDeviceControlModal" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><div><small id="jr-control-kicker">EQUIPAMENTO</small><h5 id="jr-control-title" class="modal-title">Editar</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <input type="hidden" id="jr-control-kind"><input type="hidden" id="jr-control-index">
              <div class="jr-field" id="jr-user-field"><label id="jr-user-label">Usuário / SSID</label><input id="jr-control-user" class="form-control" autocomplete="off"></div>
              <div class="jr-field"><label>Nova senha</label><div class="jr-password-input"><input id="jr-control-password" type="password" class="form-control" autocomplete="new-password" placeholder="Deixe em branco para manter"><button type="button" onclick="jrToggleModalPassword()"><i id="jr-modal-eye" class="bi bi-eye"></i></button></div><small>A senha atual nunca é reenviada automaticamente.</small></div>
              <div id="jr-control-note" class="jr-control-note"></div>
            </div>
            <div class="modal-footer"><button type="button" class="acs-soft-btn" data-bs-dismiss="modal">Cancelar</button><button type="button" class="acs-soft-btn primary" onclick="jrSaveDeviceControl()"><i class="bi bi-check2"></i> Salvar</button></div>
          </div></div>
        </div>`);
    }

    window.jrToggleWifiSecret = index => {
        const item = state.wifi[index], el = document.getElementById('jr-wifi-pass-' + index);
        if (!item || !el || item.password_state !== 'available') return;
        const showing = el.dataset.showing === '1';
        el.textContent = showing ? '••••••••' : item.password;
        el.dataset.showing = showing ? '0' : '1';
    };
    window.jrToggleAccountSecret = index => {
        const item = state.accounts[index], el = document.getElementById('jr-account-pass-' + index);
        if (!item || !el || item.password_state !== 'available') return;
        const showing = el.dataset.showing === '1';
        el.textContent = showing ? '••••••••' : item.password;
        el.dataset.showing = showing ? '0' : '1';
    };
    window.jrToggleModalPassword = () => {
        const input=document.getElementById('jr-control-password'), icon=document.getElementById('jr-modal-eye');
        if(!input)return; input.type=input.type==='password'?'text':'password';
        if(icon) icon.className=input.type==='password'?'bi bi-eye':'bi bi-eye-slash';
    };

    window.jrEditWifi = index => {
        ensureModal();
        const item=state.wifi[index]; if(!item)return;
        document.getElementById('jr-control-kind').value='wifi';
        document.getElementById('jr-control-index').value=String(index);
        document.getElementById('jr-control-title').textContent='Editar ' + (item.label || 'Wi-Fi');
        document.getElementById('jr-control-kicker').textContent=item.standard || 'WI-FI';
        document.getElementById('jr-user-label').textContent='SSID';
        const user=document.getElementById('jr-control-user');
        user.value=item.ssid || ''; user.disabled=!item.ssid_writable;
        const pass=document.getElementById('jr-control-password');
        pass.value=''; pass.disabled=!item.password_writable;
        document.getElementById('jr-control-note').textContent=item.password_writable?'Senha em branco mantém a senha atual.':'Este firmware não marcou a senha como editável.';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDeviceControlModal')).show();
    };

    window.jrEditAccount = index => {
        ensureModal();
        const item=state.accounts[index]; if(!item)return;
        document.getElementById('jr-control-kind').value='account';
        document.getElementById('jr-control-index').value=String(index);
        document.getElementById('jr-control-title').textContent='Editar credencial';
        document.getElementById('jr-control-kicker').textContent=item.label || 'ADMINISTRAÇÃO';
        document.getElementById('jr-user-label').textContent='Usuário';
        const user=document.getElementById('jr-control-user');
        user.value=item.username || ''; user.disabled=!item.username_writable;
        const pass=document.getElementById('jr-control-password');
        pass.value=''; pass.disabled=!item.password_writable;
        document.getElementById('jr-control-note').textContent='A alteração só é enviada quando o parâmetro é explicitamente gravável no CPE.';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDeviceControlModal')).show();
    };

    window.jrSaveDeviceControl = async () => {
        const kind=document.getElementById('jr-control-kind').value;
        const index=Number(document.getElementById('jr-control-index').value);
        const user=document.getElementById('jr-control-user').value;
        const password=document.getElementById('jr-control-password').value;
        const payload={action:kind==='wifi'?'update_wifi':'update_account'};
        if(kind==='wifi'){
            const item=state.wifi[index]; if(!item)return;
            payload.interface_id=item.id;
            if(item.ssid_writable && user !== String(item.ssid ?? '')) payload.ssid=user;
            if(item.password_writable && password) payload.password=password;
        } else {
            const item=state.accounts[index]; if(!item)return;
            payload.account_id=item.id;
            if(item.username_writable && user !== String(item.username ?? '')) payload.username=user;
            if(item.password_writable && password) payload.password=password;
        }
        if(!('ssid' in payload)&&!('username' in payload)&&!('password' in payload)){toast('Nenhuma alteração foi informada.','info');return;}
        try{
            const result=await controlPost(payload);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDeviceControlModal')).hide();
            toast(result.queued?'Alteração enfileirada; será aplicada na próxima comunicação.':result.message,'success');
            await window.jrRefreshControl(kind==='wifi'?'wifi':'admin', false);
            setTimeout(enhanceControls, 1800);
        }catch(e){toast(e.message,'danger');}
    };

    window.jrRefreshControl = async (kind='wifi', notify=true) => {
        try{
            const result=await controlPost({action:'refresh',kind});
            if(notify) toast(result.message,'info');
            setTimeout(enhanceControls, 1200);
        }catch(e){toast(e.message,'danger');}
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
