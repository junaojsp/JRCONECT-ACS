/* Read-only NOC equipment views. The legacy action screens remain available. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const text = value => value === null || value === undefined || value === '' ? 'N/D' : String(value);
  const fmt = value => Number(value).toLocaleString('pt-BR');
  const stamp = value => {
    if (!value) return 'Data não informada';
    // IXC dates have no timezone: retain the value reported by that source.
    if (/^\d{4}-\d{2}-\d{2} /.test(value)) return String(value);
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('pt-BR', {timeZone:'America/Sao_Paulo'});
  };
  const duration = value => {
    if (!Number.isFinite(value) || value < 0) return 'N/D';
    return `${Math.floor(value / 86400)}d ${Math.floor(value / 3600) % 24}h ${Math.floor(value / 60) % 60}min`;
  };
  const signal = d => {
    const value = d.rx?.value;
    const className = d.rx_band === 'critical' ? 'rx-critical' : d.rx_band === 'attention' ? 'rx-attention' : '';
    return `<span class="${className}">${Number.isFinite(value) ? value.toFixed(2)+' dBm' : 'N/D'}</span><br><small>${esc(text(d.rx?.source))} · ${esc(stamp(d.rx?.updated_at))}</small>`;
  };
  const communication = status => status === 'online' ? 'Online TR-069' : 'Sem Inform recente';
  let busy = false, devices = [], page = 1;
  const id = $('inventory-app').getAttribute('data-device-id');

  async function read(url) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 35000);
    try {
      const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}, signal:controller.signal});
      if (response.redirected || response.status === 401 || response.status === 403) throw new Error('Sessão expirada. Entre novamente.');
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Resposta inválida da consulta. Atualize a página.');
      let data;
      try { data = await response.json(); } catch { throw new Error('Resposta incompleta da consulta. Tente novamente.'); }
      if (!response.ok || !data?.success) throw new Error(data?.message || `Consulta indisponível (HTTP ${response.status}).`);
      if (!Array.isArray(data.devices)) throw new Error('Inventário inválido na resposta.');
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('A consulta excedeu o tempo limite. Tente novamente.');
      throw error;
    } finally { window.clearTimeout(timeout); }
  }

  function renderList() {
    const query = $('inventory-search').value.trim().toLocaleLowerCase('pt-BR');
    const status = $('inventory-status').value, band = $('inventory-signal').value;
    const filtered = devices.filter(d => (status === 'all' || d.status === status)
      && (band === 'all' || d.rx_band === band)
      && [d.serial,d.manufacturer,d.model,d.ip].some(v => String(v ?? '').toLocaleLowerCase('pt-BR').includes(query)));
    const max = Math.max(1, Math.ceil(filtered.length / 25));
    page = Math.min(page, max);
    $('inventory-total').textContent = fmt(devices.length);
    $('inventory-online').textContent = fmt(devices.filter(d => d.status === 'online').length);
    $('inventory-offline').textContent = fmt(devices.filter(d => d.status !== 'online').length);
    $('inventory-critical').textContent = fmt(devices.filter(d => d.rx_band === 'critical').length);
    $('inventory-rows').innerHTML = filtered.slice((page-1)*25,page*25).map(d => `<tr>
      <td><b>${esc(text(d.manufacturer))} ${esc(text(d.model))}</b><small>${esc(text(d.serial))}</small></td>
      <td>${esc(text(d.ip))}</td><td>${signal(d)}</td><td><span class="noc-chip ${d.status==='online'?'online':'offline'}">${communication(d.status)}</span></td>
      <td>${duration(d.uptime)}</td><td>${esc(stamp(d.last_inform))}</td>
      <td><a href="/device-noc.php?id=${encodeURIComponent(d.id)}">Abrir ↗</a></td></tr>`).join('')
      || '<tr><td colspan="7" class="noc-empty">Nenhum equipamento corresponde aos filtros.</td></tr>';
    $('inventory-page-label').textContent = `${fmt(filtered.length)} encontrados · Página ${page} de ${max}`;
    $('inventory-prev').disabled = page <= 1;
    $('inventory-next').disabled = page >= max;
  }

  function renderDetail(d) {
    $('device-model').textContent = [d.manufacturer,d.model].filter(Boolean).join(' ') || 'Equipamento ACS';
    $('device-identity').textContent = `${text(d.serial)} · Último Inform: ${stamp(d.last_inform)}`;
    $('device-status').textContent = communication(d.status);
    $('device-status').className = 'noc-chip ' + (d.status==='online'?'online':'offline');
    const diagnostics = [['Comunicação ACS',communication(d.status)],['IP reportado',text(d.ip)],
      ['Sinal RX',Number.isFinite(d.rx?.value) ? d.rx.value.toFixed(2)+' dBm' : 'N/D'],
      ['Tempo ligado',duration(d.uptime)],['Redes Wi-Fi reportadas',d.wifi.length],['Conexões WAN reportadas',d.wan.length]];
    $('device-diagnostics').innerHTML = diagnostics.map(([label,value])=>`<div><span>${esc(label)}</span><b>${esc(value)}</b></div>`).join('');
    const metrics = [['Potência RX',d.rx?.value,'dBm',d.rx?.source,d.rx?.updated_at,d.rx_band],
      ['Potência TX',d.tx?.value,'dBm','TR-069',d.tx?.updated_at,''],
      ['Temperatura',d.temperature?.value,'°C','TR-069',d.temperature?.updated_at,'']];
    $('device-metrics').innerHTML = metrics.map(([label,value,unit,source,updated,band])=>`<div><span>${label}</span><strong class="${band==='critical'?'inventory-red':''}">${Number.isFinite(value) ? esc(value)+' '+unit : 'N/D'}</strong><small>${esc(text(source))}<br>${esc(stamp(updated))}</small></div>`).join('');
    const props = [['Serial',d.serial],['Fabricante',d.manufacturer],['Modelo',d.model],['Firmware',d.firmware],['Hardware',d.hardware],['Tempo ligado',duration(d.uptime)],['Último Inform',stamp(d.last_inform)]];
    $('device-properties').innerHTML = props.map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(text(value))}</dd></div>`).join('');
    $('device-wans').innerHTML = d.wan.map(w=>`<div class="inventory-reported"><b>${esc(text(w.ip))}</b><p>Estado reportado: ${esc(text(w.status))}</p><small>Leitura: ${esc(stamp(w.updated_at))}</small></div>`).join('') || '<p class="noc-empty">Nenhuma WAN nos caminhos conhecidos desta prévia. Consulte a tela atual para outros parâmetros.</p>';
    $('device-networks').innerHTML = d.wifi.map(w=>`<div class="inventory-reported"><b>${esc(w.ssid)}</b><p>${w.enabled===true?'Habilitada':w.enabled===false?'Desabilitada':'Habilitação não informada'} · Estado: ${esc(text(w.status))}</p><small>Leitura: ${esc(stamp(w.updated_at))}</small></div>`).join('') || '<p class="noc-empty">Nenhum SSID nos caminhos conhecidos desta prévia. Ausência de leitura não significa rádio desligado.</p>';
  }

  async function load() {
    if (busy) return;
    busy = true; $('inventory-refresh').disabled = true; $('inventory-error').hidden = true;
    try {
      let last;
      if (id) {
        last = await read('/api/noc-devices.php?id='+encodeURIComponent(id));
        const device = last.devices[0];
        if (!device || device.id !== id || !Array.isArray(device.wan) || !Array.isArray(device.wifi)) throw new Error('Detalhes inválidos na resposta.');
        renderDetail(device);
      } else {
        let next = 0, complete = false;
        const rows = [], seen = new Set();
        $('inventory-load-status').textContent = 'Consultando inventário completo…';
        for (let count=0;count<201;count++) {
          last = await read('/api/noc-devices.php?skip='+next);
          for (const row of last.devices) {
            if (typeof row.id !== 'string' || seen.has(row.id)) throw new Error('O inventário mudou durante a consulta. Atualize para carregar novamente.');
            seen.add(row.id); rows.push(row);
          }
          if (rows.length > 20000) throw new Error('Inventário excedeu o limite desta prévia. Use a lista atual.');
          $('inventory-load-status').textContent = `Consultando inventário… ${fmt(rows.length)} recebidos`;
          if (!last.has_more) {complete=true;break;}
          if (!Number.isInteger(last.next_skip) || last.next_skip <= next || !last.devices.length) throw new Error('Paginação inválida no inventário.');
          next = last.next_skip;
        }
        if (!complete) throw new Error('Inventário excedeu o limite desta prévia. Use a lista atual.');
        devices = rows; page = 1; renderList();
        $('inventory-load-status').textContent = `${fmt(devices.length)} equipamentos ACS · RX IXC ${last.optical_status==='ok'?'disponível':last.optical_status==='not_configured'?'não configurado':'indisponível'} · consulta concluída`;
      }
      $('inventory-captured').textContent = 'Consulta: '+stamp(last.captured_at);
    } catch(error) {
      $('inventory-error').textContent = error.message || 'Falha na consulta dos equipamentos.';
      $('inventory-error').hidden = false;
      if (!id) $('inventory-load-status').textContent = devices.length ? 'Falha ao atualizar · mantendo a última lista válida' : 'Inventário indisponível';
    } finally { busy=false; $('inventory-refresh').disabled=false; }
  }
  $('inventory-refresh').addEventListener('click',load);
  $('inventory-menu').addEventListener('click',()=>{
    const open=document.body.classList.toggle('noc-menu-open');
    $('inventory-menu').setAttribute('aria-expanded',String(open));
  });
  $('inventory-menu-close').addEventListener('click',()=>{document.body.classList.remove('noc-menu-open');$('inventory-menu').setAttribute('aria-expanded','false');});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){
    document.body.classList.remove('noc-menu-open');$('inventory-menu').setAttribute('aria-expanded','false');
  }});
  if (!id) {
    for (const key of ['inventory-search','inventory-status','inventory-signal']) $(key).addEventListener('input',()=>{page=1;renderList();});
    $('inventory-prev').addEventListener('click',()=>{if(page>1){page--;renderList();}});
    $('inventory-next').addEventListener('click',()=>{page++;renderList();});
  }
  load();
})();
