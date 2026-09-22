# Integração SIMS - JRCONECT ACS

## Base URL

```
https://painelacs.jrconect.com/api/sims/v1
```

## Autenticação

Todas as chamadas usam:

```
Authorization: Bearer <TOKEN_FORNECIDO_PELA_JRCONECT>
Accept: application/json
Content-Type: application/json
```

O token é exclusivo para a SIMS e não corresponde ao token do IXC ou do GenieACS.

## Identificação do assinante

A API aceita:

- `id_contrato` do IXC; ou
- `id_login` do IXC.

Pelo identificador recebido, a JRCONECT localiza o registro de Cliente Fibra no IXC, obtém o serial/MAC da ONU e localiza o equipamento correspondente no GenieACS.

## Consultar equipamento e SSID

```http
GET /api/sims/v1/wifi.php?id_contrato=4740
Authorization: Bearer <TOKEN>
```

ou:

```http
GET /api/sims/v1/wifi.php?id_login=2704
Authorization: Bearer <TOKEN>
```

Resposta:

```json
{
  "success": true,
  "device": {
    "serial": "FHTT99F5A9D0",
    "model": "HG6143D3",
    "manufacturer": "FiberHome",
    "last_inform": "2026-09-22T17:52:18.000Z"
  },
  "wifi": {
    "ssid_24": "MinhaRede",
    "ssid_5": "MinhaRede-5G"
  }
}
```

A API não retorna a senha Wi-Fi atual.

## Alterar SSID e senha

Aceita `POST` ou `PUT`.

```http
PUT /api/sims/v1/wifi.php
Authorization: Bearer <TOKEN>
Content-Type: application/json
```

Payload:

```json
{
  "id_contrato": "4740",
  "ssid": "MinhaRede",
  "password": "NovaSenha123",
  "wlan_index": 1
}
```

Também pode ser usado `id_login` no lugar de `id_contrato`.

### wlan_index

- `1`: rede principal / 2.4 GHz na maioria dos CPEs;
- outros índices podem ser usados conforme o modelo do equipamento.

A JRCONECT pode ampliar posteriormente a API para seleção abstrata por banda (2.4/5 GHz), reboot e demais operações.

Resposta:

```json
{
  "success": true,
  "message": "Alteração de Wi-Fi enviada ao equipamento.",
  "device": {
    "serial": "FHTT99F5A9D0",
    "device_id": "..."
  },
  "wifi": {
    "ssid": "MinhaRede",
    "wlan_index": 1
  },
  "task": {
    "http_code": 202,
    "status": "queued"
  }
}
```

`status=queued` significa que o GenieACS aceitou a tarefa e ela será aplicada quando o CPE executar a comunicação necessária. `status=applied` indica resposta imediata.

## Segurança

- GenieACS NBI não é exposto para a SIMS.
- IXC token não é fornecido à SIMS.
- O token da integração SIMS é independente.
- Allowlist de IP pode ser aplicada no servidor.
- Senhas nunca são retornadas pela API.


## Consultar status TR-069

```http
GET /api/sims/v1/status.php?id_contrato=4740
Authorization: Bearer <TOKEN>
```

Resposta:

```json
{
  "success": true,
  "device": {
    "serial": "FHTT99F5A9D0",
    "device_id": "...",
    "model": "HG6143D3",
    "manufacturer": "FiberHome",
    "online": true,
    "last_inform": "2026-09-22T17:52:18.000Z",
    "last_inform_age_seconds": 42
  }
}
```

O equipamento é considerado online quando o último Inform ocorreu há menos de 300 segundos.

## Reiniciar equipamento

```http
POST /api/sims/v1/reboot.php
Authorization: Bearer <TOKEN>
Content-Type: application/json
```

Payload:

```json
{
  "id_contrato": "4740"
}
```

Também é aceito `id_login`.

## Seleção simplificada de banda Wi-Fi

O endpoint de Wi-Fi continua aceitando `wlan_index` para compatibilidade, mas a integração pode usar `band`:

```json
{
  "id_contrato": "4740",
  "ssid": "MinhaRede",
  "password": "NovaSenha123",
  "band": "2.4"
}
```

Valores aceitos:

- `2.4` - rede 2.4 GHz
- `5` - rede 5 GHz
- `both` - aplica SSID/senha nas duas bandas

A API detecta automaticamente se o CPE utiliza índice 5 ou 2 para a rede de 5 GHz.

## Auditoria

As operações da integração são registradas no servidor em:

```
/var/www/gacs/logs/sims-api.log
```

São registrados evento, horário UTC, IP de origem, contrato/login, serial e resultado da tarefa. Senhas e tokens não são registrados no log.
