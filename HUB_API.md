# Hub-API – Schnittstelle für das externe Admin-Dashboard

Eine kleine, **nur lesende** JSON-Schnittstelle, über die das Super-Admin-Dashboard (dev.pnikolay.dev)
den Zustand der OMUN-Website abfragt. Code: `lib/hub.php` (Endpunkte) und `lib/audit.php` (Protokoll).

Phase 1: nur `GET`. Schreibaktionen gibt es nicht. Sie kommen später, und nur wenn sie ausdrücklich gewünscht sind.

## Sicherheit

| Was | Wie |
| --- | --- |
| Zugriff | Nur mit dem Header `Authorization: Bearer <HUB_TOKEN>` |
| Token | Wird im Admin erzeugt (nur der SHA-256-Hash liegt in `data/deploy.json`) **oder** kommt aus der Umgebungsvariable `HUB_TOKEN` (mind. 32 Zeichen). Nie im Code, nie im Repo |
| Vergleich | Mit `hash_equals()` auf die SHA-256-Werte, also in konstanter Zeit |
| Fehler | Falscher oder fehlender Token ergibt immer nur `401 {"error":"unauthorized"}`. Interne Fehler ergeben nur `500 {"error":"internal_error"}` |
| Aus | Ist kein Token eingerichtet oder die API im Admin ausgeschaltet, antwortet jeder Endpunkt mit `404` |
| Rate-Limit | 30 Anfragen pro Minute und IP (gilt auch für fehlgeschlagene Versuche), danach `429` mit `Retry-After: 60` |
| Header | `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, `Content-Type: application/json` |
| Methoden | Nur `GET` (und `HEAD`), alles andere ergibt `405` |
| Browser | Kein CORS. Das Dashboard muss die API **serverseitig** aufrufen, der Token gehört nie in den Browser |
| Daten | **Keine** Passwort-Hashes, Tokens, Schlüssel, 2FA-Secrets, Namen, E-Mail-Adressen, Kennungen oder IP-Adressen |

Fehlgeschlagene Hub-Zugriffe werden selbst in `/errors` protokolliert (`hub.auth_failed`).

## Einrichtung

1. Update einspielen: **Admin → Update → Auf GitHub nach Updates suchen → einspielen** (ab Version 2026.11.03.1).
2. **Admin → Update → Hub-API:** Häkchen bei „Hub-API eingeschaltet“ setzen (beim ersten Mal wird automatisch ein Token erstellt) → Admin-Passwort eingeben → Speichern.
3. Den angezeigten Token (`omunhub_…`) kopieren. **Er wird nur dieses eine Mal angezeigt.**
4. Im Dashboard serverseitig als Umgebungsvariable hinterlegen, z. B. `OMUN_HUB_TOKEN`, und `OMUN_HUB_URL=https://omun.eu/api/hub` setzen.
5. Testen (siehe unten).

Neuer Token: Häkchen „Neuen Token erstellen“ → Speichern. Der alte Token gilt dann sofort nicht mehr.
Ausschalten: Häkchen „Hub-API eingeschaltet“ entfernen → Speichern.

**Alternative ohne Admin:** Auf einem Server mit echten Umgebungsvariablen `HUB_TOKEN` setzen (siehe `.env.example`).
Bei Strato geht das praktisch nur über die `.htaccess`. Die liegt aber im Repo und wird bei jedem Update überschrieben. Dort deshalb Variante „Admin“ nehmen.

### Umgebungsvariablen

| Variable | Wo | Pflicht | Bedeutung |
| --- | --- | --- | --- |
| `HUB_TOKEN` | OMUN-Server | nein (alternativ Token im Admin) | Bearer-Token, mind. 32 Zeichen |
| `OMUN_HUB_TOKEN` (Name frei) | Dashboard-Server | ja | derselbe Token, den das Dashboard mitschickt |
| `OMUN_HUB_URL` (Name frei) | Dashboard-Server | ja | `https://omun.eu/api/hub` |

## Testen mit curl

```bash
export OMUN_HUB_TOKEN='omunhub_…'
curl -s -H "Authorization: Bearer $OMUN_HUB_TOKEN" https://omun.eu/api/hub/health
curl -s -H "Authorization: Bearer $OMUN_HUB_TOKEN" https://omun.eu/api/hub/stats
curl -s -H "Authorization: Bearer $OMUN_HUB_TOKEN" "https://omun.eu/api/hub/audit?limit=20"
curl -s -H "Authorization: Bearer $OMUN_HUB_TOKEN" https://omun.eu/api/hub/admins
curl -s -H "Authorization: Bearer $OMUN_HUB_TOKEN" "https://omun.eu/api/hub/errors?limit=20"
# Gegenprobe: muss 401 liefern
curl -s -o /dev/null -w '%{http_code}\n' https://omun.eu/api/hub/health
```

## Endpunkte

Alle Zeiten im Format ISO 8601 mit Zeitzone (Europe/Berlin).
`?limit=` gilt für `/audit` und `/errors`: Standard 50, höchstens 200.

### `GET /api/hub/health`

```json
{
    "project": "omun",
    "version": "2026.11.03.1",
    "status": "ok",
    "time": "2026-11-03T16:11:43+01:00",
    "checks": {
        "data_writable": true,
        "content_ok": true,
        "admin_setup": true
    }
}
```

`status` ist `"degraded"`, sobald ein Check `false` ist:
- `data_writable: false` bedeutet: `data/` ist nicht beschreibbar.
- `content_ok: false` bedeutet: `content.json` ist beschädigt (R2 in ARCHITECTURE.md).
- `admin_setup: false` bedeutet: `auth.json` fehlt, und **jeder** könnte das Admin-Passwort setzen (R3).

### `GET /api/hub/stats`

```json
{
    "project": "omun",
    "version": "2026.11.03.1",
    "time": "2026-11-03T16:11:43+01:00",
    "registrations": {
        "total": 84,
        "by_status": { "received": 20, "confirmed": 58, "waitlist": 4, "cancelled": 2 },
        "role_pending": 1,
        "chairs": 12,
        "conference_managers": 3,
        "participant_admins": 1,
        "papers_uploaded": 37,
        "last_registration": "2026-11-02T19:04:11+01:00",
        "open": true
    },
    "codes": { "total": 120, "used": 84, "free": 36 },
    "portal_accounts": 82,
    "resolutions": {
        "current_by_status": { "draft": 4, "debate": 1, "closed": 1 },
        "finished": 3
    },
    "content": {
        "committees": 6,
        "news_total": 5,
        "news_published": 4,
        "team": 18,
        "pages": 2,
        "content_versions": 50
    },
    "uploads": { "files": 64, "bytes": 18234567 },
    "visitors": {
        "last_7_days": { "views": 1520, "visitors": 410 },
        "last_30_days": { "views": 6030, "visitors": 1702 }
    }
}
```

Chairs und Conference Manager werden aus Datenschutzgründen nur **gezählt**, nicht aufgelistet.

### `GET /api/hub/audit?limit=50`

Die letzten Änderungen und Ereignisse, neueste zuerst. Zusammengeführt aus `data/audit-log.json` (neu, max. 500 Einträge)
und dem bestehenden Update-Protokoll `data/update-log.json`.

```json
{
    "events": [
        { "time": "2026-11-03T16:20:02+01:00", "actor": "admin", "action": "registration.update", "target": "id 3f9a1c2b7d10 → confirmed" },
        { "time": "2026-11-03T16:18:40+01:00", "actor": "admin", "action": "content.save", "target": "Startseite" },
        { "time": "2026-11-03T16:15:12+01:00", "actor": "mcp", "action": "update.install", "target": "2026.11.01.1 → 2026.11.03.1 (Claude (MCP))" },
        { "time": "2026-11-03T16:11:57+01:00", "actor": "admin", "action": "admin.login", "target": "2fa" }
    ]
}
```

`actor` ist `admin` (es gibt nur ein gemeinsames Admin-Passwort, keine einzelnen Admin-Benutzer) oder `mcp`.

Protokollierte Aktionen:

| Bereich | Aktionen |
| --- | --- |
| Admin | `admin.setup`, `admin.login`, `admin.logout`, `admin.password_change`, `admin.2fa_add`, `admin.2fa_remove`, `admin.recovery_codes_new` |
| Inhalte | `content.save`, `content.item_create`, `content.item_update`, `content.item_delete`, `content.item_move`, `content.import`, `content.history_restore` |
| Medien | `media.upload`, `media.delete` |
| Anmeldungen | `registration.update`, `registration.delete`, `registration.delete_all`, `registration.confirm_role`, `registration.send_credentials`, `allocation.save` |
| Kennungen | `codes.add`, `codes.delete`, `codes.delete_unused`, `legacy.assign_codes`, `legacy.strip_names`, `legacy.send_credentials` |
| Konten | `staff.password_change` |
| Betrieb | `update.install`, `update.failed`, `deploy.settings`, `mcp.new_key`, `hub.settings`, `hub.new_token` |

Bei Anmeldungen steht nur die interne ID im Log, nie eine Kennung, ein Name oder eine E-Mail-Adresse.

### `GET /api/hub/admins`

```json
{
    "admins": [
        {
            "name": "admin",
            "role": "admin",
            "note": "Single shared admin password (no individual admin users).",
            "password_changed": "2026-09-12T10:02:13+02:00",
            "two_factor": {
                "enabled": true,
                "devices": [ { "name": "iPhone Philip", "created": "2026-09-12T10:05:40+02:00" } ],
                "recovery_codes_left": 7
            },
            "last_login": "2026-11-03T16:11:57+01:00",
            "last_activity": "2026-11-03T16:20:02+01:00"
        }
    ],
    "staff_accounts": [
        { "name": "laptops", "label": "Laptop (beamer)", "role": "viewer", "password_changed": null, "default_password": true }
    ],
    "integrations": {
        "mcp_enabled": true,
        "mcp_key_created": "2026-10-07T13:59:00+02:00",
        "hub_token_created": "2026-11-03T16:11:57+01:00",
        "hub_token_from_env": false
    }
}
```

`last_login` und `last_activity` stammen aus dem Audit-Log und sind erst ab dem Update auf 2026.11.03.1 gefüllt.
`default_password: true` heißt: Das Laptop-Konto hat noch das Start-Passwort.

### `GET /api/hub/errors?limit=50`

Fehlgeschlagene Logins, abgelehnte Schlüssel und fehlgeschlagene Updates, neueste zuerst (`data/error-log.json`, max. 500, und Fehler aus `update-log.json`).
Ohne IP-Adressen und ohne eingegebene Kennungen.

```json
{
    "errors": [
        { "time": "2026-11-03T16:11:57+01:00", "actor": "anonymous", "action": "admin.login_failed", "target": "admin" },
        { "time": "2026-11-03T15:40:10+01:00", "actor": "anonymous", "action": "portal.login_failed", "target": "portal" },
        { "time": "2026-11-03T15:02:00+01:00", "actor": "anonymous", "action": "hub.auth_failed", "target": "/api/hub/stats" }
    ]
}
```

Mögliche Aktionen: `admin.login_failed`, `admin.2fa_failed`, `admin.login_rate_limited`, `portal.login_failed`,
`portal.login_rate_limited`, `mcp.auth_failed`, `hub.auth_failed`, `update.failed`.

### Fehlerantworten

| Code | Body | Wann |
| --- | --- | --- |
| 401 | `{"error":"unauthorized"}` | Token fehlt oder ist falsch |
| 404 | `{"error":"not_found"}` | API aus, oder unbekannter Endpunkt |
| 405 | `{"error":"method_not_allowed"}` | Nicht `GET` |
| 429 | `{"error":"too_many_requests"}` | Mehr als 30 Anfragen pro Minute |
| 500 | `{"error":"internal_error"}` | Interner Fehler (ohne Details) |

## Hinweise zu diesem Projekt

- OMUN hat **keine Datenbank** (kein MySQL, kein Supabase). Alle Daten liegen als JSON in `data/`. Prepared Statements und SQL-Migrationen gibt es deshalb nicht.
  Das Audit-Log ist eine JSON-Datei, die beim ersten Eintrag automatisch entsteht. Es muss nichts migriert werden.
- `/admins`: Es gibt nur ein Admin-Passwort (mit 2FA-Geräten), keine Benutzerkonten. Deshalb gibt der Endpunkt diesen einen Admin, das Staff-Konto `laptops` und den Status der Integrationen aus.
