/* JRC-ACS NOC 2.0: read-only dashboard; no device writes. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const fmt = value => Number(value ?? 0).toLocaleString('pt-BR');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const state = { busy:false };
  const status = (value, kind) => {
    const node = $('noc-health');
    node.textContent = value;
    node.className = 'noc-pill ' + kind;
  };
  function setText(id, value) { const el=$(id); if(el) el.textContent=value; }
  function dateLabel(value) {
    if (!value) return 'Não informado';
    const time = new Date(value);
    return Number.isNaN(time.getTime()) ? 'Não informado' : time.toLocaleString('pt-BR', {dateStyle:'short',timeStyle:'short'});
  }
  function issueClass(item) {
    if (item.reason === 'RX óptico crítico') return 'critical';
    if (item.reason === 'RX óptico em atenção') return 'attention';
    return '';
  }
  function detailLink(id) { return '/device-detail.php?id=' + encodeURIComponent(id); }
  function render(data) {
    const s=data.summary || {};
    const total=Number(s.total)||0, online=Number(s.online)||0, offline=Number(s.offline)||0;
    const good=Number(s.optical_good)||0, attention=Number(s.optical_attention)||0;
    const critical=Number(s.optical_critical)||0, unknown=Number(s.optical_unavailable)||0;
    const percent=total>0 ? 100*online/total : 0;
    setText('noc-total',fmt(total));setText('noc-online',fmt(online));setText('noc-offline',fmt(offline));
    setText('noc-critical',fmt(critical));
    const availability=total>0 ? percent.toLocaleString('pt-BR',{minimumFractionDigits:1,maximumFractionDigits:1})+'%' : 'N/D';
    setText('noc-availability',availability);setText('noc-availability-large',availability);
    setText('noc-legend-online',fmt(online));setText('noc-legend-offline',fmt(offline));
    const progress=$('noc-progress'); progress.setAttribute('aria-valuenow',String(Math.round(percent)));
    $('noc-progress-bar').style.width=Math.min(100,Math.max(0,percent))+'%';
    setText('noc-optical-good',fmt(good));setText('noc-optical-attention',fmt(attention));
    setText('noc-optical-critical',fmt(critical));setText('noc-optical-unknown',fmt(unknown));
    const valid=good+attention+critical;
    setText('noc-donut-total',fmt(valid));
    const opticalTotal=valid+unknown;
    if(opticalTotal>0){
      const g=100*good/opticalTotal,a=100*attention/opticalTotal,c=100*critical/opticalTotal;
      $('noc-donut').style.background='conic-gradient(#00d89a 0% '+g+'%, #ffac36 '+g+'% '+(g+a)+'%, #ff5563 '+(g+a)+'% '+(g+a+c)+'%, #72869c '+(g+a+c)+'% 100%)';
    } else {
      $('noc-donut').style.background='#24445c';
    }
    const items=Array.isArray(data.issues)?data.issues:[];
    const list=$('noc-issues'),table=$('noc-table-body');
    if(!items.length){
      list.innerHTML='<p class="noc-empty">Nenhuma ocorrência prioritária identificada neste snapshot.</p>';
      table.innerHTML='<tr><td colspan="6" class="noc-empty">Nenhum equipamento prioritário neste snapshot.</td></tr>';
    } else {
      list.innerHTML=items.slice(0,4).map(item=>{
        const cls=issueClass(item), id=String(item.device_id||'');
        return '<div class="noc-issue"><span class="noc-chip '+cls+'">'+esc(cls==='critical'?'Crítico':cls==='attention'?'Atenção':'Offline')+'</span><div><div class="noc-reason">'+esc(item.reason)+'</div><small>'+esc(item.serial)+' · '+esc(item.model)+'</small></div><a href="'+detailLink(id)+'">Detalhes <i class="bi bi-arrow-up-right"></i></a></div>';
      }).join('');
      table.innerHTML=items.map(item=>{
        const cls=issueClass(item),rx=typeof item.rx==='number'?item.rx.toFixed(2)+' dBm':'N/D';
        return '<tr><td>'+esc(item.serial)+'</td><td>'+esc(item.model)+'</td><td class="'+(cls==='critical'?'rx-critical':cls==='attention'?'rx-attention':'')+'">'+esc(rx)+'</td><td><span class="noc-chip '+cls+'">'+esc(item.reason)+'</span></td><td>'+esc(dateLabel(item.last_inform))+'</td><td><a href="'+detailLink(String(item.device_id||''))+'">Abrir <i class="bi bi-arrow-up-right"></i></a></td></tr>';
      }).join('');
    }
    setText('noc-captured','Leitura: '+dateLabel(data.captured_at));
    status('ACS RESPONDENDO','ok');
  }
  async function load(){
    if(state.busy || document.hidden) return;
    state.busy=true;const button=$('noc-refresh');button.disabled=true;
    $('noc-error').hidden=true;
    try {
      const res=await fetch('/api/dashboard-noc-data.php',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      if(res.redirected||res.status===401||res.status===403){throw new Error('Sessão expirada. Entre novamente para consultar o NOC.');}
      const fallback=res.ok
        ? 'Resposta inválida do painel NOC. Tente novamente.'
        : 'Consulta NOC indisponível (HTTP '+res.status+'). Consulte o log PHP do servidor.';
      const type=res.headers.get('content-type')||'';
      if(!type.includes('application/json'))throw new Error(fallback);
      let data;
      try { data=await res.json(); } catch { throw new Error(fallback); }
      if(!data||typeof data!=='object'||Array.isArray(data))throw new Error(fallback);
      if(!res.ok||!data.success)throw new Error(data.message||'Consulta indisponível.');
      render(data);
    }catch(e){
      $('noc-error').textContent=e.message||'Falha ao carregar indicadores.';
      $('noc-error').hidden=false;
      status('DADOS INDISPONÍVEIS','error');
      setText('noc-captured', $('noc-captured').textContent.replace(/^Leitura: /,'Última leitura válida: '));
      // Never reset last valid numbers to zero upon an outage.
    }finally {state.busy=false;button.disabled=false;}
  }
  $('noc-refresh').addEventListener('click',load);
  load();
  window.setInterval(load,120000);
})();
