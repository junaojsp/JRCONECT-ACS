const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const nodes=new Map(), calls=[];
function node(id){if(!nodes.has(id))nodes.set(id,{value:'',style:{},checkValidity:()=>true,reportValidity(){},setAttribute(){},removeAttribute(){}});return nodes.get(id);}
let shown=0,hidden=0;
class Modal{show(){shown++;}static getInstance(){return {hide(){hidden++;}};}}
const context={document:{getElementById:node,addEventListener(){}},window:{location:{hostname:'example.invalid'}},bootstrap:{Modal},console,
  fetchAPI:async(url,options)=>{calls.push({url,...options});return {success:true};},showLoading(){},hideLoading(){},showToast(){},setTimeout(){},setInterval(){},clearInterval(){},deviceId:'test-device'};
vm.createContext(context);vm.runInContext(fs.readFileSync('assets/js/device-detail.js','utf8'),context);
(async()=>{
 context.openEditWiFiModal('test-device','Rede de teste','Synthetic-Password');
 assert.equal(shown,1);assert.equal(node('edit-device-id').value,'test-device');
 node('edit-wifi-ssid').value='Nova rede de teste';node('edit-security-mode').value='WPA2-PSK';node('edit-wlan-index').value='5';
 await context.confirmUpdateWiFi();
 assert.equal(hidden,1);assert.equal(calls.length,1);assert.equal(calls[0].url,'/api/update-wifi-config.php');assert.equal(calls[0].method,'POST');
 assert.deepEqual(JSON.parse(calls[0].body),{device_id:'test-device',wifi_ssid:'Nova rede de teste',security_mode:'WPA2-PSK',wlan_index:5,wifi_password:'Synthetic-Password'});
 node('edit-wifi-password').value='short';await context.confirmUpdateWiFi();assert.equal(calls.length,1);
 console.log('PASS operational Wi-Fi: existing modal, selected WLAN, original POST payload and validation; no real request sent');
})().catch(e=>{console.error(e);process.exitCode=1;});
