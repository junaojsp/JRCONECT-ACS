import test from 'node:test';
import assert from 'node:assert/strict';
import { findVoipPending, reviewRequired } from './reconciliation.js';

const product = (id, contractId, contractStatus = 'active', productId = '74') =>
  ({id, contractId, contractStatus, productId});

test('abre pendencia somente para produto 74 sem vinculo', () => {
  assert.deepEqual(findVoipPending({
    productRows:[product(10,100),product(11,101),product(12,102,'active','75')],
    assignments:[{productContractId:11,status:'active'}],
  }), [{productContractId:'10',contractId:'100',productId:'74'}]);
});

test('nao reabre vinculo reservado, suspenso ou aguardando baixa', () => {
  for (const status of ['reserved','active','suspended','awaiting_release']) {
    assert.deepEqual(findVoipPending({
      productRows:[product(10,100)],
      assignments:[{productContractId:10,status}],
    }),[]);
  }
});

test('nao gera pendencias para status contratuais desconhecidos', () => {
  assert.deepEqual(findVoipPending({
    productRows:[product(10,100,'unknown'), product(11,101,'suspended')],
    assignments:[],
  }),[]);
});

test('rejeita snapshots incompletos e duplicados', () => {
  assert.throws(()=>findVoipPending({productRows:null,assignments:[]}),TypeError);
  assert.throws(()=>findVoipPending({productRows:[product(1,2),product(1,2)],assignments:[]}),TypeError);
  assert.throws(()=>findVoipPending({productRows:[product('x',2)],assignments:[]}),TypeError);
});

test('cancelamento exige revisao e nunca libera numero', () => {
  assert.equal(reviewRequired({contractStatus:'cancelled',hasProduct74:true}),'awaiting_review');
  assert.equal(reviewRequired({contractStatus:'active',hasProduct74:false}),'awaiting_review');
  assert.equal(reviewRequired({contractStatus:'suspended',hasProduct74:true}),'keep_reserved');
  assert.equal(reviewRequired({contractStatus:'unknown',hasProduct74:true}),'unknown_manual_review');
});
