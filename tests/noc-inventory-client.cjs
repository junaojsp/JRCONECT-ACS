const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/noc-inventory.js'), 'utf8');
function boot(id, fetcher) {
  const nodes = new Map();
  const node = key => {
    if (!nodes.has(key)) nodes.set(key, {textContent:'',innerHTML:'',value:key==='inventory-status'||key==='inventory-signal'?'all':'',
      hidden:true,handlers:{},getAttribute(){return id;},setAttribute(){},addEventListener(event,fn){this.handlers[event]=fn;}});
    return nodes.get(key);
  };
  const context = {document:{getElementById:node,addEventListener(){},body:{classList:{toggle(){},remove(){}}}},
    window:{setTimeout,clearTimeout},AbortController,fetch:fetcher};
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/js/noc-device.js'), 'utf8'),context);
  vm.runInContext(source,context);
  return {node, window:context.window, async settle(){for(let i=0;i<30;i++){await new Promise(resolve=>setImmediate(resolve));if(!node('inventory-refresh').disabled)return;}throw Error('Client did not settle');}};
}
const response = (devices, extra={}) => ({ok:true,status:200,headers:{get(){return 'application/json';}},
  async json(){return {success:true,devices,has_more:false,captured_at:'2026-10-22T01:00:00Z',...extra};}});
const device = i => ({id:'test-'+i,serial:'SERIAL-'+i,manufacturer:'TEST',model:'MODEL',status:i%2?'offline':'online',
  rx:{value:-28,source:'IXC'},rx_band:'critical',ip:'192.0.2.1',uptime:0,wan:[],wifi:[],temperature:{value:0},tx:{value:0}});
(async()=>{
  let rows=Array.from({length:52},(_,i)=>device(i));
  rows[0].model='<script>unsafe</script>';
  let calls=0;
  let app=boot('',async()=>{calls++;return response(rows);});
  await app.settle();
  assert.equal(app.node('inventory-total').textContent,'52');
  assert.equal(app.node('inventory-online').textContent,'26');
  assert.equal(app.node('inventory-offline').textContent,'26');
  assert.equal(app.node('inventory-critical').textContent,'52');
  assert.equal((app.node('inventory-rows').innerHTML.match(/<tr>/g)||[]).length,25);
  assert.ok(!app.node('inventory-rows').innerHTML.includes('<script>'));
  assert.ok(app.node('inventory-rows').innerHTML.includes('&lt;script&gt;'));
  assert.ok(app.node('inventory-rows').innerHTML.includes('0d 0h 0min'));
  assert.ok(app.node('inventory-rows').innerHTML.includes('/device-detail.php?id=test-0'));
  app.node('inventory-next').handlers.click();
  assert.match(app.node('inventory-page-label').textContent,/Página 2 de 3/);
  app.node('inventory-search').value='SERIAL-51';app.node('inventory-search').handlers.input();
  assert.match(app.node('inventory-page-label').textContent,/1 encontrados/);
  app.node('inventory-status').value='online';app.node('inventory-status').handlers.input();
  assert.match(app.node('inventory-rows').innerHTML,/Nenhum equipamento/);
  // A failed refresh preserves the complete valid list and exposes the error.
  rows=null;
  app.node('inventory-refresh').handlers.click();await app.settle();
  assert.equal(app.node('inventory-total').textContent,'52');
  assert.equal(app.node('inventory-error').hidden,false);
  // Repeated device pages terminate rather than silently duplicating results.
  calls=0;
  app=boot('',async()=>{calls++;return response([device(1)],{has_more:true,next_skip:calls});});
  await app.settle();assert.equal(calls,2);assert.match(app.node('inventory-error').textContent,/inventário mudou/);
  const d=device(1);d.wifi=[{ssid:'<img src=x onerror=alert(1)>',enabled:false}];
  app=boot('test-1',async()=>response([d]));await app.settle();
  assert.match(app.node('device-networks').innerHTML,/Desabilitada/);
  assert.ok(!app.node('device-networks').innerHTML.includes('<img'));
  assert.match(app.node('device-metrics').innerHTML,/0 °C/);
  assert.match(app.node('device-wans').innerHTML,/Nenhuma WAN nos caminhos conhecidos/);
  const direct={success:true,source:'IXC',device_id:'test-1',serial:'SERIAL-1',slot:'12',pon:'2',onu_number:'9',
    password:'NEVER_RENDER',nome:'PRIVATE_CUSTOMER',optical:{rx_power:'-28,2',tx_power:0,temperature:48,voltage:3.32,last_update:'2026-10-22 03:30:00'}};
  const requested=[];
  app=boot('test-1',async(url,options)=>{
    requested.push(url);assert.equal(options.method,undefined);assert.equal(options.body,undefined);
    return url.startsWith('/api/get-onu-optical.php')?{...response([]),async json(){return direct;}}:response([device(1)]);
  });await app.settle();
  assert.deepEqual(requested,['/api/noc-devices.php?id=test-1','/api/get-onu-optical.php?device_id=test-1']);
  assert.match(app.node('device-metrics').innerHTML,/48 °C/);
  assert.match(app.node('device-metrics').innerHTML,/3.32 V/);
  assert.match(app.node('device-metrics').innerHTML,/0 dBm/);
  assert.match(app.node('device-metrics').innerHTML,/IXC/);
  assert.match(app.node('device-properties').innerHTML,/PON IXC/);
  assert.ok(![...['device-metrics','device-properties']].some(k=>/NEVER_RENDER|PRIVATE_CUSTOMER/.test(app.node(k).innerHTML)));
  assert.equal(app.node('inventory-optical-warning').hidden,true);
  const bounds=device(1);
  assert.equal(app.window.mergeNocLegacyOptical(bounds,{...direct,optical:{rx_power:0,tx_power:100,temperature:'',voltage:false}}),true);
  assert.equal(bounds.rx.value,-28);assert.equal(bounds.tx.value,0);assert.equal(bounds.temperature.value,0);
  assert.equal(bounds.voltage,undefined);
  const before=JSON.stringify(bounds);
  assert.equal(app.window.mergeNocLegacyOptical(bounds,{...direct,serial:'ANOTHER-SERIAL'}),false);
  assert.equal(JSON.stringify(bounds),before);
  app=boot('test-1',async url=>url.startsWith('/api/get-onu-optical.php')
    ?{...response([]),status:401,ok:false,async json(){return {success:false,source:'IXC'};}}:response([device(1)]));
  await app.settle();assert.equal(app.node('inventory-error').hidden,true);
  assert.equal(app.node('inventory-optical-warning').hidden,false);
  assert.match(app.node('device-metrics').innerHTML,/0 °C/);
  app=boot('test-1',async url=>url.startsWith('/api/get-onu-optical.php')
    ?{...response([]),async json(){return {...direct,device_id:'another-device'};}}:response([device(1)]));
  await app.settle();assert.equal(app.node('inventory-optical-warning').hidden,false);
  assert.match(app.node('device-metrics').innerHTML,/0 °C/);
  app=boot('',async()=>({...response([]),status:401,ok:false}));await app.settle();
  assert.match(app.node('inventory-error').textContent,/Sessão expirada/);
  app=boot('',async()=>({...response([]),headers:{get(){return 'text/html';}}}));await app.settle();
  assert.match(app.node('inventory-error').textContent,/Resposta inválida/);
  const missing=device(9);missing.rx={value:null};missing.rx_band='unavailable';
  app=boot('',async()=>response([missing],{optical_status:'unavailable',optical_reason:'refresh_busy'}));await app.settle();
  assert.equal(app.node('inventory-critical').textContent,'N/D');
  assert.match(app.node('inventory-rx-coverage').textContent,/Nenhuma leitura RX válida/);
  assert.equal(app.node('inventory-optical-warning').hidden,false);
  assert.match(app.node('inventory-optical-warning').textContent,/Outra consulta está atualizando/);
  const normal=device(8);normal.rx.value=-24;normal.rx_band='good';
  app=boot('',async()=>response([normal],{optical_status:'ok'}));await app.settle();
  assert.equal(app.node('inventory-critical').textContent,'0');
  assert.equal(app.node('inventory-optical-warning').hidden,true);
  console.log('PASS inventory UI: filters, pagination, escaping, zero values, stale data, repeated pages, detail, session and non-JSON failure');
})().catch(error=>{console.error(error);process.exitCode=1;});
