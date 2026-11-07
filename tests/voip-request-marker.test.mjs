import test from 'node:test';
import assert from 'node:assert/strict';
import { inspectObservation, inspectVoipRequests, REQUEST_MARKER } from '../mcp/integrations/ixc-voip-request-inspector.js';

const listing = {
  produto_id:'74', somente_leitura:true, liberacao_automatica:false,
  total_contratos:2, contratos:[
    {contrato_id:'5006',registros_produto_74:['303']},
    {contrato_id:'6396',registros_produto_74:['383']}
  ]
};
function fakeClient() {
  return {list: async(resource,options)=>{
    const id = options.filters[0].value;
    const values = {
      contrato:{
        '5006':{id:'5006',id_cliente:'11',status:'A',obs_contrato:'Texto anterior '+REQUEST_MARKER+' final'},
        '6396':{id:'6396',id_cliente:'12',status:'I',obs_contrato:'texto sem pedido'}
      },
      cliente:{
        '11':{id:'11',razao:'CLIENTE PRIVADO',obs:'sem marcador'},
        '12':{id:'12',razao:'CLIENTE PRIVADO',obs:'algum texto '+REQUEST_MARKER}
      }
    };
    return {total:1,registros:[values[resource]?.[id]]};
  }};
}
test('recognizes exact marker and preserves privacy',async()=>{
  const r=await inspectVoipRequests(fakeClient(),listing);
  assert.equal(r.contratos_inspecionados,2);
  assert.equal(r.registros[0].classificacao,'marcador_contrato_validar_campo');
  assert.equal(r.registros[1].classificacao,'marcador_cliente_requer_desambiguacao');
  assert.equal(r.reserva_did,false);
  assert.equal(r.escrita_ixc,false);
  assert.equal(JSON.stringify(r).includes('CLIENTE PRIVADO'),false);
  assert.equal(JSON.stringify(r).includes('Texto anterior'),false);
  assert.equal(JSON.stringify(r).includes(REQUEST_MARKER),false);
});
test('does not match variations of the exact marker',()=>{
  assert.deepEqual(inspectObservation({obs_contrato:'#JRC_VOIP_SOLICITAR'},['obs_contrato']).marked,[]);
});
test('fails closed on partial or mismatched response',async()=>{
  await assert.rejects(inspectVoipRequests({list:async()=>({total:2,registros:[]})},listing));
  await assert.rejects(inspectVoipRequests(fakeClient(),{...listing,total_contratos:3}));
});
test('fails closed on wrong observation type',()=>{
  assert.throws(()=>inspectObservation({obs:42},['obs']));
});
test('never calls mutations',async()=>{
  let writes=0;
  const client=fakeClient();client.update=()=>{writes++;throw new Error('should not happen')};
  const r=await inspectVoipRequests(client,listing);
  assert.equal(writes,0);
  assert.ok(r.registros.every(x=>x.atribuicao_automatica===false));
});
