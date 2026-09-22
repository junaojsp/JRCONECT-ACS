# IXC ACS compatibility endpoint

The IXC Subscriber Center was observed calling:

- `GET /api/v1/token`
- Source IP: `138.204.112.14`
- `Authorization: Bearer <JWT>`
- `Content-Type: application/x-www-form-urlencoded`

The endpoint implementation lives at:

`api/v1/token.php`

## Nginx mapping

Use a dedicated exact-match location in the HTTPS `acs.jrconect.com` server block:

```nginx
location = /api/v1/token {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/gacs/api/v1/token.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
```

Do not proxy this path to GenieACS UI.

## Security

The endpoint:

- Allows only GET.
- Restricts source IPs via `IXC_ACS_ALLOWED_IPS`.
- Defaults to `138.204.112.14`.
- Requires a Bearer JWT.
- Rejects `alg=none`.
- Never logs or returns the raw JWT.
- Logs only JWT header/claim names to `logs/ixc-acs-compat.log`.
- Does **not** yet verify the JWT signature.

Until the IXC signing key/verification contract is known, the endpoint must be treated as a compatibility probe, not as completed authentication.

## Optional allowlist override

For PHP-FPM, set:

```
IXC_ACS_ALLOWED_IPS=138.204.112.14
```

If more than one IXC source address becomes necessary, provide a comma-separated list.

## Deployment

```bash
cd /var/www/gacs
git pull origin ui/equipamentos-cards-reference
php -l /var/www/gacs/api/v1/token.php
```

Then update Nginx, test with `nginx -t`, and reload Nginx.
