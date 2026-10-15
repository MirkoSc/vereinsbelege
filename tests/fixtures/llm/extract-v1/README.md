# Recorded answers for the extraction (`extract-v1`)

Bodies of OpenAI-compatible `POST /chat/completions` answers, used by
`tests/Support/FakeLlmClient.php` and `tests/Support/FakeChatHttp.php`
(`App\Service\Ki\KiAbfrage` and `App\Service\Ki\OpenAiCompatibleClient`,
M7-2, issue #44). The HTTP status is set by the test. Shapes follow the
OpenAI API (chat completion object); the receipt behind them is made up -
supplier, numbers and the IBAN (the well-known example IBAN) are synthetic,
no real data.

| File | Content |
|---|---|
| `gueltig.json` | valid `extract-v1` object, with usage |
| `ungueltig-schema.json` | JSON that breaks the schema: no `invoice_date`, German amount `1.234,56`, unknown payment method, `null` VAT id |
| `gueltig-repariert.json` | the corrected answer of a repair call (date unreadable → `null` + warning) |
| `kein-json.json` | prose without any JSON |
| `prosa-mit-json.json` | the valid object in a code fence between prose (profile without `json_schema`), no usage |
| `verweigert.json` | `content: null` with `refusal` |
| `abgeschnitten.json` | JSON cut off at the token limit (`finish_reason: length`) |
