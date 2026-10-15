# Recorded answers for the connection test

Bodies of OpenAI-compatible `POST /chat/completions` answers, used by
`tests/Support/FakeChatHttp.php` (`App\Service\Ki\Verbindungstest`, M7-1,
M7-1b). The HTTP status is set by the test. Shapes follow the OpenAI API
(chat completion object and error object); ids, model names and the key
fragment in `401-key-falsch.json` are made up - no real key, no real data.

| File | Probe | Meaning |
|---|---|---|
| `schema-erzwungen.json` | text + `response_format` | server enforces the schema: bare JSON |
| `schema-ignoriert-satz.json` | text + `response_format` | server ignores it (Anthropic's compatible endpoint): a sentence |
| `bild-rot.json` | picture | model saw the red test picture |
| `bild-kein-bild.json` | picture | model did not see it (answer without the colour) |
| `400-bild.json`, `401-…`, `404-…`, `429-…`, `502-proxy.html` | any | error answers of a provider |
