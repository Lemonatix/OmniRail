<?php
/**
 * Dünner cURL-Wrapper.
 *
 * Gibt bewusst NIE eine Exception zurück, sondern immer ein Array mit
 * status/body/error. So kann jeder Provider selbst entscheiden, ob ein
 * Fehlschlag fatal ist oder ob degradiert weitergearbeitet wird.
 *
 * Nicht final: für Tests darf eine Ableitung getJson/postJson überschreiben
 * und ohne echtes Netzwerk arbeiten. Zur Laufzeit gibt es dennoch nur diese
 * eine Ausprägung.
 */
class Http
{
    /**
     * TLS-1.2-Cipher-Reihenfolge eines Chrome-Browsers.
     *
     * HINTERGRUND: Die DB setzt Akamai Bot Manager ein, der den TLS-ClientHello
     * auswertet (JA3-Fingerprint). Mit der Standard-Cipher-Reihenfolge von
     * cURL/OpenSSL antwortet bahn.de mit 403 "OPS_BLOCKED" - unabhängig von
     * IP, User-Agent und allen anderen Headern. Setzt man diese Reihenfolge,
     * geht dieselbe Anfrage durch.
     *
     * Nachgemessen: ohne Cipher-Liste 403, mit Cipher-Liste 200 (5/5 Versuche),
     * und zwar sogar ganz ohne User-Agent.
     */
    private const TLS12_CIPHERS = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
        . 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
        . 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305';

    /** TLS 1.3 wird in cURL über eine eigene Option gesetzt. */
    private const TLS13_CIPHERS = 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256';

    private int $timeout;
    private bool $browserTls;

    /**
     * @param bool $browserTls Browser-Cipher-Reihenfolge verwenden. Für die
     *                         DB zwingend, für andere Hosts unschädlich.
     */
    public function __construct(int $timeout = 25, bool $browserTls = false)
    {
        $this->timeout    = $timeout;
        $this->browserTls = $browserTls;
    }

    /** Liefert eine Kopie dieses Clients mit Browser-TLS-Profil. */
    public function withBrowserTls(): self
    {
        return new self($this->timeout, true);
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error:?string,json:?array}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            return $this->fail('cURL ist auf diesem Server nicht verfügbar.');
        }

        $ch = curl_init();
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => '', // gzip/deflate automatisch
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($this->browserTls) {
            // TLS-1.2-Suiten: von OpenSSL/cURL überall unterstützt.
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, self::TLS12_CIPHERS);
            // TLS 1.3 braucht cURL >= 7.61 mit OpenSSL >= 1.1.1. Fehlt die
            // Konstante, bleibt es bei der Standardreihenfolge - dann greift
            // im Zweifel wieder das Blocking, aber nichts bricht.
            if (defined('CURLOPT_TLS13_CIPHERS')) {
                curl_setopt($ch, CURLOPT_TLS13_CIPHERS, self::TLS13_CIPHERS);
            }
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        // Kein curl_close(): seit PHP 8.0 wirkungslos, seit 8.5 gibt es dafür
        // eine Deprecation-Warnung. Die landete mitten in der JSON-Antwort und
        // machte damit JEDEN API-Aufruf auf PHP 8.5 unbrauchbar. Das Handle
        // räumt der Garbage Collector auf.
        unset($ch);

        // Jeden Aufruf nach draußen verbuchen - siehe Health.php. Der Haken
        // sitzt hier und nicht an den Aufrufstellen, weil er sonst genau die
        // Provider verpasst, die sich ihren Client selbst bauen.
        Health::note($url, $raw !== false && $status >= 200 && $status < 400,
            $err !== '' ? $err : ('HTTP ' . $status));

        if ($raw === false) {
            return $this->fail($err !== '' ? $err : 'Unbekannter Netzwerkfehler', $status);
        }

        $json = null;
        $decoded = json_decode((string) $raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $json = $decoded;
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => (string) $raw,
            'error'  => null,
            'json'   => $json,
        ];
    }

    /** @param array<string,string> $headers */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers + ['Accept' => 'application/json']);
    }

    /**
     * Mehrere GET-Anfragen GLEICHZEITIG.
     *
     * WOZU: Die Wagenreihung braucht eine Anfrage je Zug. Nacheinander sind
     * zwölf Züge zwölf Round-Trips - nachgemessen neunzehn Sekunden, und die
     * Trefferliste wartete so lange auf eine Angabe, die nur ein Zusatz ist.
     * Parallel kostet dasselbe knapp zwei.
     *
     * Die Rückgabe behält die Schlüssel der Eingabe, damit die Zuordnung
     * nicht über die Reihenfolge laufen muss - bei curl_multi kommen die
     * Antworten in beliebiger Reihenfolge zurück.
     *
     * @param array<string|int,string> $urls    Schlüssel => URL
     * @param array<string,string>     $headers für alle Anfragen dieselben
     * @return array<string|int,array{ok:bool,status:int,body:string,error:?string,json:?array}>
     */
    public function getJsonAll(array $urls, array $headers = [], int $parallel = 8): array
    {
        if ($urls === []) {
            return [];
        }
        if (!function_exists('curl_multi_init')) {
            // Ohne curl_multi eben nacheinander - langsam, aber richtig.
            $out = [];
            foreach ($urls as $k => $url) {
                $out[$k] = $this->getJson($url, $headers);
            }
            return $out;
        }

        $hdr = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }

        $out    = [];
        $stapel = array_chunk($urls, max(1, $parallel), true);

        foreach ($stapel as $teil) {
            $multi   = curl_multi_init();
            $handles = [];

            foreach ($teil as $k => $url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_HTTPHEADER     => $hdr,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                // Dasselbe Browser-Profil wie in request() - ohne blockt
                // bahn.de auch die gleichzeitigen Anfragen (Wagenreihung).
                if ($this->browserTls) {
                    curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, self::TLS12_CIPHERS);
                    if (defined('CURLOPT_TLS13_CIPHERS')) {
                        curl_setopt($ch, CURLOPT_TLS13_CIPHERS, self::TLS13_CIPHERS);
                    }
                }
                curl_multi_add_handle($multi, $ch);
                $handles[$k] = $ch;
            }

            do {
                $status = curl_multi_exec($multi, $laufen);
                if ($laufen) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($laufen && $status === CURLM_OK);

            foreach ($handles as $k => $ch) {
                $raw    = curl_multi_getcontent($ch);
                $code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err    = curl_error($ch);
                curl_multi_remove_handle($multi, $ch);

                Health::note((string) $teil[$k], $raw !== null && $raw !== false
                    && $code >= 200 && $code < 300, $err !== '' ? $err : ('HTTP ' . $code));

                if ($raw === null || $raw === false || $code < 200 || $code >= 300) {
                    $out[$k] = $this->fail($err !== '' ? $err : 'HTTP ' . $code, $code);
                    continue;
                }
                $decoded = json_decode((string) $raw, true);
                $out[$k] = [
                    'ok'     => true,
                    'status' => $code,
                    'body'   => (string) $raw,
                    'error'  => null,
                    'json'   => is_array($decoded) ? $decoded : null,
                ];
            }
            curl_multi_close($multi);
        }

        return $out;
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->request('POST', $url, $headers + [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Accept'       => 'application/json',
        ], $encoded === false ? '{}' : $encoded);
    }

    /** @return array{ok:bool,status:int,body:string,error:string,json:null} */
    private function fail(string $message, int $status = 0): array
    {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $message, 'json' => null];
    }
}
