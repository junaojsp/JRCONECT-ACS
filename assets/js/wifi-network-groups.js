/* Unified networks are a separate management domain, not a frequency band.
 * Read-only identification release: no speculative vendor writes or task calls. */
(() => {
    'use strict';
    if (window.__JR_WIFI_NETWORK_GROUPS_V1__) return;
    window.__JR_WIFI_NETWORK_GROUPS_V1__ = true;
    const state = { mode:'individual', data:null, observedAt:null, loading:false, error:'', diagnosticBusy:false };
    let mountedSection = null;
    let scheduled = false;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const bandLabels = {'2.4':'2,4 GHz','5':'5 GHz','6':'6 GHz'};
    const modeLabels = {unified:'Rede unificada', individual:'Redes individuais', mlo:'Rede MLO'};
    const shortTech = values => {
        const a = Array.isArray(values) ? values : [];
        const names = [['be','Wi-Fi 7 (802.11be)'],['ax','Wi-Fi 6 (802.11ax)'],['ac','Wi-Fi 5 (802.11ac)'],['n','Wi-Fi 4 (802.11n)']];
        return names.filter(([key]) => a.includes(key)).map(([,name]) => name).join(' / ') || 'N\u00e3o informado';
    };
    function hasMlo() { return Number(state.data?.mlo?.candidate_count || 0) > 0; }
    function drawNavigation() {
        const nav = mountedSection?.querySelector('.jr-wng-nav');
        if (!nav) return;
        if (state.mode === 'mlo' && !hasMlo()) state.mode = 'unified';
        const modes = ['unified','individual'];
        if (hasMlo()) modes.push('mlo');
        nav.innerHTML = modes.map(mode => `<button type="button" class="acs-soft-btn ${state.mode===mode?'primary':''}" data-wng-mode="${mode}" aria-pressed="${state.mode===mode}"><i class="bi ${mode==='individual'?'bi-wifi':'bi-diagram-3'}"></i>${modeLabels[mode]}</button>`).join('');
    }
    function drawPanel() {
        const panel = mountedSection?.querySelector('.jr-wng-panel');
        if (!panel) return;
        if (state.loading && !state.data) {
            panel.innerHTML = '<div class="jr-wng-notice" role="status">Consultando os recursos informados pelo equipamento...</div>';
            return;
        }
        const d=state.data, feature=state.mode==='mlo'?'mlo':'unified';
        const info=d?.[feature];
        const detected=Number(info?.candidate_count || 0)>0;
        const name=modeLabels[feature];
        const status=!d?'Leitura indispon\u00edvel':detected?'Par\u00e2metros encontrados; mapeamento pendente':'N\u00e3o identificado na leitura atual';
        const explanation=feature==='unified'
            ? 'Band Steering / Smart Connect tem controle pr\u00f3prio. SSIDs iguais nas bandas n\u00e3o confirmam uma rede unificada.'
            : 'MLO tem configura\u00e7\u00e3o pr\u00f3pria. Os par\u00e2metros encontrados ainda precisam ser associados aos links e \u00e0 rede correta.';
        const bands=(d?.bands_reported || []).map(b=>bandLabels[b]).filter(Boolean).join(' / ') || 'N\u00e3o informadas';
        const stamp=state.observedAt ? new Date(state.observedAt) : null;
        const queried=stamp && !Number.isNaN(stamp.getTime()) ? stamp.toLocaleString('pt-BR') : '\u2014';
        panel.innerHTML=`
          <div class="jr-wng-heading"><strong>${name}</strong><span>IDENTIFICA\u00c7\u00c3O</span></div>
          ${state.error?`<p class="jr-wng-error" role="alert">${esc(state.error)}${d?' Os dados anteriores foram mantidos.':''}</p>`:''}
          <div class="jr-wng-status"><i class="bi bi-info-circle"></i><span>${esc(status)}</span></div>
          <dl class="jr-wng-values">
            <div><dt>Tecnologias suportadas informadas</dt><dd>${esc(shortTech(d?.technology?.supported))}</dd></div>
            <div><dt>Padr\u00f5es configurados informados</dt><dd>${esc(shortTech(d?.technology?.configured))}</dd></div>
            <div><dt>Bandas informadas pelo modem</dt><dd>${esc(bands)}</dd></div>
            <div><dt>Estado da ${feature==='mlo'?'rede MLO':'unifica\u00e7\u00e3o'}</dt><dd>N\u00e3o confirmado</dd></div>
            <div><dt>Bandas vinculadas a esta rede</dt><dd>Aguardando mapeamento</dd></div>
            <div><dt>SSID compartilhado</dt><dd>Aguardando mapeamento</dd></div>
            <div><dt>Senha desta rede</dt><dd>Leitura ainda n\u00e3o mapeada</dd></div>
          </dl>
          <p class="jr-wng-note">${esc(explanation)} Wi-Fi 6/7 identifica a tecnologia, n\u00e3o uma terceira banda.</p>
          <p class="jr-wng-note">Os controles de nome, senha e ativa\u00e7\u00e3o ser\u00e3o liberados ap\u00f3s validar os par\u00e2metros do modelo e firmware. Nenhum dado de 5 GHz ser\u00e1 copiado para preencher outra rede.</p>
          ${d?.incomplete?'<p class="jr-wng-error">A leitura foi limitada. O diagn\u00f3stico pode estar incompleto.</p>':''}
          <div class="jr-wng-actions">
            <button type="button" class="acs-soft-btn" data-wng-action="refresh" ${state.loading?'disabled':''}><i class="bi bi-arrow-clockwise"></i>${state.loading?'Consultando...':'Reconsultar identifica\u00e7\u00e3o'}</button>
            <button type="button" class="acs-soft-btn" data-wng-action="diagnostic" ${!d || state.loading?'disabled':''}><i class="bi bi-download"></i>Baixar diagn\u00f3stico</button>
            <button type="button" class="acs-soft-btn primary" disabled title="O comando do fabricante ainda precisa ser validado">Gerenciar ${feature==='mlo'?'rede MLO':'rede unificada'}</button>
          </div>
          <p class="jr-wng-note jr-wng-footnote">Consulta do cache: ${esc(queried)}. Este campo n\u00e3o ativa ou desativa redes e n\u00e3o altera senhas. Para atualizar os dados do modem, use Detectar redes do modem em Redes individuais.</p>`;
    }
    function applyMode() {
        if (!mountedSection?.isConnected) return;
        const legacy=mountedSection.querySelector('.acs-wifi-reference-grid');
        const panel=mountedSection.querySelector('.jr-wng-panel');
        if (legacy) legacy.classList.toggle('jr-wng-hidden',state.mode!=='individual');
        if (panel) panel.hidden=state.mode==='individual';
        drawNavigation();
        if(state.mode!=='individual') drawPanel();
    }
    async function load() {
        if(state.loading || !window.DEVICE_ID) return;
        state.loading=true;state.error='';applyMode();
        const controller=new AbortController();
        const timer=setTimeout(()=>controller.abort(),16000);
        try {
            const r=await fetch('/api/wifi-network-groups.php?device_id='+encodeURIComponent(window.DEVICE_ID),{
                credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}
            });
            let body;
            try{body=await r.json();}catch(_){throw new Error('Resposta inv\u00e1lida. Confirme a sess\u00e3o do painel.');}
            if(!r.ok || !body.success) throw new Error(body.message || 'N\u00e3o foi poss\u00edvel consultar os recursos.');
            if(body.data?.schema!=='wifi-network-groups-v1' || body.data?.read_only!==true) throw new Error('Vers\u00e3o de identifica\u00e7\u00e3o incompat\u00edvel.');
            state.data=body.data;state.observedAt=body.observed_at || null;
        } catch(e) {
            state.error=e.name==='AbortError'?'Tempo limite de consulta; nenhuma configura\u00e7\u00e3o foi alterada.':e.message;
        } finally {
            clearTimeout(timer);state.loading=false;applyMode();
        }
    }
    function diagnostic() {
        if(!state.data) return;
        const file={...state.data,schema:'wifi-network-groups-diagnostic-v1',observed_at:state.observedAt};
        const blob=new Blob([JSON.stringify(file,null,2)],{type:'application/json'});
        const url=URL.createObjectURL(blob),a=document.createElement('a');
        a.href=url;a.download='diagnostico-rede-unificada.json';document.body.appendChild(a);a.click();a.remove();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
    }
    function ensureMounted() {
        const section=document.querySelector('.acs-approved-wifi');
        const grid=section?.querySelector('.acs-wifi-reference-grid');
        if(!section || !grid) return;
        if(mountedSection===section && section.querySelector('.jr-wng-nav') && section.querySelector('.jr-wng-panel')) return;
        mountedSection=section;
        const nav=document.createElement('div');nav.className='jr-wng-nav';nav.setAttribute('role','group');nav.setAttribute('aria-label','Tipo de rede Wi-Fi');
        const panel=document.createElement('div');panel.className='jr-wng-panel';panel.hidden=true;
        section.insertBefore(nav,grid);section.insertBefore(panel,grid);
        nav.addEventListener('click',event=>{
            const button=event.target.closest('[data-wng-mode]');if(!button)return;
            const mode=button.dataset.wngMode;
            if(!Object.hasOwn(modeLabels,mode) || (mode==='mlo'&&!hasMlo()))return;
            state.mode=mode;applyMode();
            if(mode!=='individual'&&!state.data&&!state.loading&&!state.error)load();
        });
        panel.addEventListener('click',event=>{
            const button=event.target.closest('[data-wng-action]');if(!button || button.disabled)return;
            if(button.dataset.wngAction==='refresh')load();
            else if(button.dataset.wngAction==='diagnostic')diagnostic();
        });
        applyMode();
    }
    function start() {
        const root=document.getElementById('overview-content');
        if(!root || !window.DEVICE_ID)return;
        ensureMounted();
        const observer=new MutationObserver(()=>{
            if(scheduled)return;scheduled=true;
            queueMicrotask(()=>{scheduled=false;ensureMounted();});
        });
        observer.observe(root,{subtree:true,childList:true});
        window.addEventListener('pagehide',()=>observer.disconnect());
        window.addEventListener('pageshow',()=>{observer.observe(root,{subtree:true,childList:true});ensureMounted();});
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
    else start();
})();
