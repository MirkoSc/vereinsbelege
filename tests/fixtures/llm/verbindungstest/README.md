# Recorded answers for the connection test

Bodies of OpenAI-compatible `POST /chat/completions` answers, used by
`tests/Support/FakeChatHttp.php` (`App\Service\Ki\Verbindungstest`, M7-1).
The HTTP status is set by the test. Shapes follow the OpenAI API
(chat completion object and error object); ids, model names and the key
fragment in `401-key-falsch.json` are made up - no real key, no real data.
