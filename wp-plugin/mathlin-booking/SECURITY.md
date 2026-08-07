# Security Notes — MGF Venue Invoice Documents

## Private Document Storage

Invoice PDF rendering uses a temporary directory for the brief render-to-send
window. The preferred location is the OS temporary directory (typically `/tmp`
on Linux, outside the web root).

If that is unavailable, a fallback directory under `wp-content/mbs-private/render/`
is used with the following protections:

### Apache (.htaccess — created automatically)
```
Order deny,allow
Deny from all
```

### Nginx (must be configured manually)
Add to your server block:
```nginx
location /wp-content/mbs-private/ {
    deny all;
    return 403;
}
```

### IIS (web.config — place in the mbs-private directory)
```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <remove users="*" roles="" verbs="" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
    <handlers>
      <clear />
    </handlers>
  </system.webServer>
</configuration>
```

## Composer Dependency Security

Run `composer audit` regularly to check for known vulnerabilities:
```bash
composer audit --working-dir=wp-plugin/mathlin-booking
```

The CI workflow includes a security audit step. Monitor Dompdf security
advisories at: https://github.com/dompdf/dompdf/security/advisories

## PDF Rendering Security

- Remote resource loading is DISABLED (`isRemoteEnabled = false`)
- PHP execution in templates is DISABLED (`isPhpEnabled = false`)
- JavaScript is DISABLED (`isJavascriptEnabled = false`)
- SVG input is not accepted (images must be PNG/JPEG/GIF)
- Logos are loaded from the validated database asset store as data URIs
- No arbitrary local file references — chroot is restricted
- A new Dompdf instance is created for each document (no state leakage)
- DejaVu Sans is used as the default Unicode-capable font

## Guest Download Tokens

- Cryptographically random (32 bytes)
- Only the SHA-256 hash is stored server-side
- Time-bounded (default 72 hours)
- Use-count limited (default 5 downloads)
- Revocable by administrator
- Scoped to exactly one immutable document
- No personal data in the URL
- Separate rate-limited endpoint (20 requests per 5 minutes per IP)
- Token consumed only AFTER successful document delivery
