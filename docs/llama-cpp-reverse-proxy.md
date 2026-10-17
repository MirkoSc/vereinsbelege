# llama.cpp-Server hinter einem Reverse-Proxy

Anleitung für Vereine, die Belege **nicht an einen KI-Dienstleister** geben
wollen und stattdessen einen eigenen
[llama.cpp](https://github.com/ggml-org/llama.cpp)-Server betreiben (z. B.
auf einem Raspberry Pi oder einem VPS). Hintergrund: E-08 in
`docs/ENTSCHEIDUNGEN.md`, Client in `docs/spec/03-erfassung-und-ki.md` §6.

Die Anwendung läuft beim Webhoster und spricht den KI-Server **ausschließlich
über `https://`** an (Basis-URL ohne `http://`, ohne Zugangsdaten in der
Adresse). `llama-server` selbst kann kein TLS-Zertifikat verwalten und ist
nicht für das offene Internet gedacht. Deshalb gilt:

```
Webhoster ──https + API-Key──▶ Reverse-Proxy (TLS) ──http, nur lokal──▶ llama-server
```

> **Datenschutz:** Der Server sieht die Belege im Klartext (auch Namen und
> IBANs von Einreichern). Ein Auftragsverarbeitungsvertrag mit einem
> Cloud-Anbieter entfällt, dafür liegt die Verantwortung für Betrieb,
> Updates und Absicherung beim Verein.

## 1. llama-server starten

Nötig ist ein Modell **mit Bildverarbeitung**: die Modelldatei (`.gguf`)
und die dazugehörige Projektor-Datei (`mmproj`). Beispiel:

```bash
llama-server \
  --model  /models/vision-modell-q4.gguf \
  --mmproj /models/vision-modell-mmproj.gguf \
  --host 127.0.0.1 --port 8080 \
  --api-key-file /etc/llama/api-key \
  --ctx-size 16384 \
  --parallel 1
```

- `--host 127.0.0.1`: nur der Reverse-Proxy auf demselben Rechner erreicht
  den Server. **Niemals** `0.0.0.0` ohne Firewall.
- `--api-key-file` (alternativ `--api-key` oder die Umgebungsvariable
  `LLAMA_API_KEY`): der Server verlangt `Authorization: Bearer <key>`. Der
  Key sollte lang und zufällig sein, z. B. `openssl rand -hex 32`. Der
  Server prüft den Key auch dann, wenn der Proxy davor ihn schon geprüft hat
  – zwei Schlösser sind gewollt.
- `--ctx-size`: Eine Belegseite als Bild braucht viele Token. Die Vorlage
  erlaubt deshalb nur **2 Seiten je Aufruf**; wer mehr Kontext hat, erhöht
  „Bilder je Aufruf“ im Profil.
- `--parallel 1`: auf kleiner Hardware läuft ein Aufruf nach dem anderen;
  die Anwendung ruft ohnehin immer nur einen Beleg zur Zeit ab.
- Der Name im Feld „Modell“ des Profils wird von `llama-server` nicht
  geprüft (der Server liefert das Modell, mit dem er gestartet wurde). Die
  Vorlage trägt `default` ein; jeder nicht leere Name funktioniert.

## 2. Reverse-Proxy mit TLS und Auth

Der Proxy braucht ein gültiges Zertifikat (z. B. Let's Encrypt) für eine
eigene (Sub-)Domain, etwa `llm.verein.example`.

### Caddy (holt das Zertifikat selbst)

```caddyfile
llm.verein.example {
    # Nur der API-Endpunkt wird durchgereicht, alles andere ist zu.
    @api path /v1/chat/completions
    handle @api {
        # Erster Riegel: ohne passenden Key kommt keine Anfrage durch.
        @ohneKey not header Authorization "Bearer {env.LLM_API_KEY}"
        respond @ohneKey 401

        reverse_proxy 127.0.0.1:8080 {
            # Lange Antworten kleiner Hardware nicht abbrechen.
            transport http {
                response_header_timeout 300s
            }
        }
    }
    respond 404
}
```

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name llm.verein.example;

    ssl_certificate     /etc/letsencrypt/live/llm.verein.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/llm.verein.example/privkey.pem;

    # Seiten gehen als Base64-Bilder im Anfragetext mit: großzügig, aber begrenzt.
    client_max_body_size 40m;

    location = /v1/chat/completions {
        # Erster Riegel; der Key steht hier nur im Proxy, nicht im Repository.
        if ($http_authorization != "Bearer <KEY>") { return 401; }

        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
        proxy_request_buffering off;
        proxy_buffering off;
    }

    location / { return 404; }
}

# Kein Redirect von http auf https für den API-Pfad nötig - der Client ruft
# ausschließlich https auf (siehe unten, „Weiterleitungen“).
```

Empfehlungen für beide:

- **Nur einen Pfad freigeben** (`/v1/chat/completions` – mehr ruft die
  Anwendung nicht auf). Die Pfade `/props`, `/slots`, `/metrics`, `/completion` und die eingebaute
  Weboberfläche gehören nicht ins Internet.
- **Ratenbegrenzung** pro IP (`limit_req` in nginx, `rate_limit` als
  Caddy-Modul), da jede Anfrage teure Rechenzeit kostet.
- **Firewall**: Port 8080 von außen sperren (nur 443 und ggf. 80 für die
  Zertifikatserneuerung öffnen).
- **Zugriff einschränken**: Wenn der Webhoster eine feste ausgehende
  IP-Adresse hat, den Proxy zusätzlich auf diese Adresse beschränken. Bei
  Shared Hosting ist das meist nicht der Fall – dann trägt der API-Key.
- **Kein Zugriffslog mit Anfragetext**: Der Anfragetext enthält die Belege.
  Proxy und `llama-server` dürfen Body und Antworten nicht protokollieren
  (bei `llama-server` keine ausführliche Protokollierung einschalten und
  die Ausgabe nicht an Dritte weitergeben).
- **Key-Wechsel** gelegentlich: neuen Key im Proxy und im Server setzen,
  dann im Admin unter *KI-Anbieter* im Profil eintragen.

## 3. Profil in der Anwendung anlegen

1. *Admin → KI-Anbieter → Neues Profil aus Vorlage „llama.cpp-Server“*.
2. **Basis-URL**: `https://llm.verein.example/v1` – ohne
   `/chat/completions`, die Anwendung hängt es selbst an.
3. **API-Key**: der Key aus Abschnitt 1/2 (er wird mit dem Server-Schlüssel
   verschlüsselt gespeichert und nie wieder angezeigt).
4. **Zeitlimit**: Die Vorlage setzt 180 s. Auf einem Raspberry Pi kann eine
   Seite länger brauchen; der Proxy-Timeout (oben 300 s) muss mindestens so
   groß sein wie das Zeitlimit des Profils. Höchstens 300 s sind möglich.
5. Speichern und **„Verbindung testen“**. Der Test prüft Adresse, Key und
   Modell und trägt ins Profil ein, ob Bilder und JSON-Schema wirklich
   funktionieren. Fehlt dem Modell die `mmproj`-Datei, meldet der Test
   „keine Bilder“ – das Profil kann dann nur Text lesen (E-Rechnungen,
   PDF-Textebene), keine Fotos.
6. Als **Standard** oder als **Fallback-Profil** wählen.

Zur Prüfung von außen (ohne die Anwendung):

```bash
curl -sS https://llm.verein.example/v1/chat/completions \
     -H "Authorization: Bearer $LLM_API_KEY" -H 'Content-Type: application/json' \
     -d '{"model":"default","messages":[{"role":"user","content":"Sag Hallo."}]}'
# ohne Header muss 401 kommen, ein anderer Pfad 404
curl -sS -o /dev/null -w '%{http_code}\n' -X POST https://llm.verein.example/v1/chat/completions
```

Auch `tools/hosting-check.php` kann die Erreichbarkeit vom Webspace aus
prüfen: `…/hosting-check.php?llm_url=https://llm.verein.example/v1/chat/completions`
(06 §5); die Probe sendet keinen Key, jede HTTP-Antwort (auch 401/404/405)
zählt als „erreichbar“.

## 4. Hinweise zum Verhalten der Anwendung

- **Weiterleitungen (3xx) folgt die Anwendung bewusst nicht**, damit der
  API-Key nicht an ein Umleitungsziel gesendet wird. Die Basis-URL muss also
  direkt antworten – kein `http`→`https`-Redirect und keine
  `www`-Weiterleitung auf dem API-Pfad. Eine Weiterleitung zeigt sich als
  Fehler im Verbindungstest.
- **Fähigkeiten**: Die Vorlage nimmt `vision` und `json_schema` an, weil
  `llama-server` `response_format` in eine Grammatik umsetzt und Bilder
  als `image_url` mit `data:image/jpeg;base64,…` liest. „Verbindung testen“
  überschreibt beides mit dem tatsächlichen Verhalten.
- **Ausgabelänge**: Die Anwendung begrenzt die Antwort mit
  `max_completion_tokens` (Feld „Max. Tokens“ im Profil). Ob die jeweilige
  `llama-server`-Version diesen Parameternamen auswertet, ist nicht
  zugesichert; wertet sie ihn nicht aus, begrenzt nur die Kontextgröße die
  Antwort. Das ist ein Qualitäts-, kein Sicherheitsthema; M7-5 (Qualitäts-
  prüfung gegen echte Belege) hält fest, wie sich das Modell verhält.
- **Kein Cron, keine Hintergrundaufrufe vom Server**: Aufrufe entstehen
  nur in einer angemeldeten Browser-Session (CLAUDE.md §4). Ist der
  KI-Server aus, scheitert nur das Auslesen – Belege lassen sich weiter von
  Hand erfassen; ein Fallback-Profil (z. B. OpenAI) springt bei Ausfall
  (Zeitüberschreitung, Verbindungsfehler, HTTP 429/5xx) einmal ein.
- **Alternative Worker**: Wer ohnehin einen Raspberry Pi als
  Verarbeitungs-Worker betreibt (`docs/spec/07-worker.md`), kann dort ein
  lokales Profil im Heimnetz definieren, ohne den Server ins Internet zu
  stellen.
