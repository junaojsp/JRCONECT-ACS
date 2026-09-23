# Sincronização IXC -> GenieACS

Arquivo:

```
cron/ixc-device-sync.php
```

## Objetivo

Remover automaticamente do GenieACS ONUs/ONTs que já foram válidas no IXC e posteriormente deixaram de existir ou deixaram de possuir login válido.

## Proteções

- Nunca remove dispositivo que nunca foi previamente confirmado como válido no IXC.
- Erros de API, timeout ou HTTP do IXC não incrementam o contador de ausência.
- São necessárias 3 verificações consecutivas sem vínculo válido IXC antes da remoção.
- O modo padrão é DRY-RUN e não apaga nada.

## Primeiro teste

```bash
php /var/www/gacs/cron/ixc-device-sync.php
```

## Ativar remoção

```bash
php /var/www/gacs/cron/ixc-device-sync.php --apply
```

## Estado e auditoria

Estado:

```
/var/www/gacs/runtime/ixc-device-sync/state.json
```

Log:

```
/var/www/gacs/logs/ixc-device-sync.log
```

## Cron recomendado

Depois de validar o DRY-RUN:

```cron
*/15 * * * * /usr/bin/php /var/www/gacs/cron/ixc-device-sync.php --apply >> /var/log/gacs-ixc-device-sync.log 2>&1
```
