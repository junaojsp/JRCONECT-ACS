/* Device overview composed from reported values only. */
(() => {
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const $=id=>document.getElementById(id), known=v=>v!==null&&v!==undefined&&v!=='';
  const text=v=>known(v)?String(v):'N/D';
  const measure=(v,u='')=>known(v)?text(v)+(u?' '+u:''):'N/D';
  const date=v=>{if(!v)return 'Data não informada';if(/^\d{4}-\d{2}-\d{2} /.test(v))return v;const d=new Date(v);return Number.isNaN(d.getTime())?String(v):d.toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'});};
  const duration=v=>Number.isFinite(v)&&v>=0?Math.floor(v/86400)+'d '+Math.floor(v/3600)%24+'h '+Math.floor(v/60)%60+'min':'N/D';
  const pairs=rows=>rows.map(([label,value])=>'<div><dt>'+esc(label)+'</dt><dd>'+esc(text(value))+'</dd></div>').join('');
  const bytes=v=>{const n=Number(v);if(!known(v)||!Number.isFinite(n)||n<0)return 'N/D';const p=n?Math.max(0,Math.min(3,Math.floor(Math.log(n)/Math.log(1024)))):0;return (n/1024**p).toLocaleString('pt-BR',{maximumFractionDigits:1})+' '+['B','KiB','MiB','GiB'][p];};
  window.renderNocDevice=d=>{
    const t=d.telemetry||{}, metric=k=>t[k]?.value??null;
    const wan=d.wan.find(w=>w.status==='Connected')||d.wan[0]||{};
    const rx=d.rx?.value, rxKnown=Number.isFinite(rx), issues=[];
    const communication=d.status==='online'?'Online TR-069':'Sem Inform recente';
    if(d.status!=='online')issues.push(['Sem Inform recente','Último Inform: '+date(d.last_inform)]);
    if(d.rx_band==='critical')issues.push(['RX óptico crítico',measure(rx,'dBm')+' · '+text(d.rx?.source)+' · '+date(d.rx?.updated_at)]);
    $('device-model').textContent=[d.manufacturer,d.model].filter(Boolean).join(' ')||'Equipamento ACS';
    $('device-identity').textContent=text(d.serial)+' · Último Inform: '+date(d.last_inform);
    $('device-status').textContent=communication;
    $('device-status').className='noc-chip '+(d.status==='online'?'online':'offline');
    const diag=(label,value,tone='')=>'<div><span><i class="bi '+(tone==='good'?'bi-check-circle':tone==='bad'?'bi-x-circle':'bi-dash-circle')+' '+tone+'"></i>'+esc(label)+'</span><b class="'+(tone==='good'?'device-good':tone==='bad'?'device-bad':known(value)?'':'device-muted')+'">'+esc(text(value))+'</b></div>';
    $('device-diagnostics').innerHTML=[
      diag('Comunicação ACS',communication,d.status==='online'?'good':'bad'),
      diag('Conexão WAN',wan.status,wan.status==='Connected'?'good':''),
      diag('Sinal óptico (RX)',measure(rx,'dBm'),d.rx_band==='critical'?'bad':d.rx_band==='good'?'good':''),
      diag('Transmissão óptica (TX)',measure(d.tx?.value,'dBm')),
      diag('Temperatura',measure(d.temperature?.value,'°C')),
      diag('Processamento (CPU)',measure(metric('cpu'),'%')),
      diag('Memória em uso',measure(metric('memory'),'%')),
      diag('Wi-Fi',d.wifi.length+' rede(s) reportada(s)'),
      diag('Rede local',measure(metric('hosts'),'host(s) reportado(s)')),
      diag('Voz',metric('voice')),diag('Sincronismo (NTP)',metric('ntp')),
      diag('Ocorrências nesta leitura',issues.length,issues.length?'bad':'')
    ].join('');
    const entries=[
      ['Sinal RX',rx,'dBm','optical',d.rx?.source,d.rx?.updated_at],
      ['Sinal TX',d.tx?.value,'dBm','optical','TR-069',d.tx?.updated_at],
      ['Temperatura',d.temperature?.value,'°C','optical','TR-069',d.temperature?.updated_at],
      ['CPU',metric('cpu'),'%','system','TR-069',t.cpu?.updated_at],
      ['Memória em uso',metric('memory'),'%','system','TR-069',null],
      ['Tempo ligado',d.uptime===null?null:duration(d.uptime),'','system','TR-069',null],
      ['Uptime da conexão',known(wan.uptime)?duration(Number(wan.uptime)):null,'','system','TR-069',null],
      ['Hosts reportados',metric('hosts'),'','system','TR-069',t.hosts?.updated_at],
      ['Sincronismo (NTP)',metric('ntp'),'','system','TR-069',t.ntp?.updated_at]
    ];
    $('device-metrics').innerHTML=entries.map(([label,value,unit,category,source,at])=>'<div data-metric-category="'+category+'"><span>'+label+'</span><strong>'+esc(measure(value,unit))+'</strong><small class="device-metric-tag '+(known(value)?'':'unknown')+'">'+(known(value)?esc(text(source)):'Sem leitura')+'</small>'+(at?'<small>'+esc(date(at))+'</small>':'')+'</div>').join('');
    $('device-properties').innerHTML=pairs([['Fabricante',d.manufacturer],['Modelo',d.model],['Serial',d.serial],['Firmware',d.firmware],['Hardware',d.hardware],['Último Inform',date(d.last_inform)]]);
    $('device-connections').innerHTML=pairs([['IP reportado',d.ip],['Estado WAN',wan.status],['Tempo da conexão',known(wan.uptime)?duration(Number(wan.uptime)):'N/D'],['Redes Wi-Fi reportadas',d.wifi.length],['Hosts reportados',metric('hosts')]]);
    $('device-wans').innerHTML=d.wan.map(w=>'<div class="inventory-reported"><b>'+esc(text(w.ip))+'</b><p>Estado reportado: '+esc(text(w.status))+'</p><small>Leitura: '+esc(date(w.updated_at))+'</small></div>').join('')||'<p class="noc-empty">Nenhuma WAN nos caminhos conhecidos desta prévia. Consulte a tela atual para outros parâmetros.</p>';
    $('device-networks').innerHTML=d.wifi.map(w=>'<div class="inventory-reported"><b>'+esc(w.ssid)+'</b><p>'+(w.enabled===true?'Habilitada':w.enabled===false?'Desabilitada':'Habilitação não informada')+' · Estado: '+esc(text(w.status))+'</p><small>Leitura: '+esc(date(w.updated_at))+'</small></div>').join('')||'<p class="noc-empty">Nenhum SSID nos caminhos conhecidos desta prévia. Ausência de leitura não significa rádio desligado.</p>';
    $('device-gauge').innerHTML=rxKnown?'<div class="device-gauge-track"><i style="left:'+Math.max(0,Math.min(100,(rx+35)/27*100))+'%"></i></div><div class="device-gauge-labels"><span>-35 dBm</span><span>RX '+esc(rx.toFixed(2))+' dBm</span><span>-8 dBm</span></div>':'<p class="noc-empty">Sinal óptico sem leitura válida</p>';
    if(rxKnown){
      const y=Math.max(14,Math.min(130,14+(-8-rx)/27*116));
      $('device-rx-chart').innerHTML='<svg viewBox="0 0 600 155" role="img" aria-label="Uma leitura RX disponível, sem série histórica"><path d="M35 14H580M35 72H580M35 130H580" stroke="#204055" fill="none"/><path d="M35 87H580" stroke="#d79828" stroke-dasharray="5 5"/><path d="M35 100H580" stroke="#d5535b" stroke-dasharray="5 5"/><circle cx="310" cy="'+y+'" r="5" fill="'+(d.rx_band==='critical'?'#ff5363':'#00d99b')+'"/><text x="310" y="152" text-anchor="middle" fill="#7894aa" font-size="11">Última leitura reportada</text></svg>';
    }else $('device-rx-chart').innerHTML='<p class="noc-empty">Sem amostra RX para apresentar</p>';
    $('device-traffic').innerHTML='<div class="device-traffic-counters"><div><span>↓ Recebido acumulado</span><b>'+esc(bytes(wan.bytes_received?.value))+'</b></div><div><span>↑ Enviado acumulado</span><b>'+esc(bytes(wan.bytes_sent?.value))+'</b></div></div><div class="device-traffic-empty"><i class="bi bi-graph-up"></i>Sem série temporal para calcular velocidade.<br>Uma leitura de contadores não mede kbps.</div><p class="device-chart-note">Contadores da WAN reportada; podem reiniciar com a conexão ou com o equipamento.</p>';
    $('device-current-issues').innerHTML=issues.map(([label,note])=>'<div class="device-issue-row"><i class="bi bi-exclamation-circle"></i><div><b>'+esc(label)+'</b><small>'+esc(note)+'</small></div></div>').join('')||'<div class="device-issue-row"><div>Sem ocorrência de comunicação ou RX crítico identificada nas leituras disponíveis.<small>Dados ausentes não são considerados saudáveis. Esta triagem não é histórico de alertas.</small></div></div>';
    $('device-voice').textContent='Estado da linha reportada: '+text(metric('voice'))+'. Outros parâmetros permanecem na tela atual.';
    $('device-hosts').textContent='Hosts reportados: '+text(metric('hosts'))+'. A lista detalhada permanece na tela atual.';
    window.selectNocDeviceSection?.(window.nocDeviceSection||'overview');
  };
  window.selectNocDeviceSection=(section,focus=false)=>{
    if(!document.querySelectorAll)return;
    window.nocDeviceSection=section;
    document.querySelectorAll('[data-section]').forEach(tab=>{const selected=tab.getAttribute('data-section')===section;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;if(selected&&focus)tab.focus();});
    document.querySelectorAll('[data-sections]').forEach(panel=>{panel.hidden=!panel.getAttribute('data-sections').split(' ').includes(section);});
    document.querySelectorAll('[data-metric-category]').forEach(card=>{card.hidden=section==='optical'&&card.getAttribute('data-metric-category')!=='optical';});
    $('device-panels').setAttribute('aria-labelledby','tab-'+section);
  };
  if(document.querySelectorAll){
    const tabs=[...document.querySelectorAll('[data-section]')];
    tabs.forEach((tab,index)=>{
      tab.addEventListener('click',()=>window.selectNocDeviceSection(tab.getAttribute('data-section')));
      tab.addEventListener('keydown',event=>{
        let next;
        if(event.key==='ArrowRight')next=(index+1)%tabs.length;
        if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;
        if(event.key==='Home')next=0;
        if(event.key==='End')next=tabs.length-1;
        if(next!==undefined){event.preventDefault();window.selectNocDeviceSection(tabs[next].getAttribute('data-section'),true);}
      });
    });
  }
})();
