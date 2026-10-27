<?php

namespace Tests\Support;

use App\Services\AttendanceSecurity as S;

/** Test-only virtual authenticator: actual P-256 keys and ES256 signatures. */
class AttendanceAuthenticator
{
    public $key;

    public string $id;

    public function __construct()
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $localConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (! getenv('OPENSSL_CONF') && is_file($localConfig)) {
            $options['config'] = $localConfig;
        }
        $this->key = openssl_pkey_new($options);
        if (! $this->key) {
            throw new \RuntimeException('Test key generation requires a valid OpenSSL configuration.');
        }
        $this->id = random_bytes(32);
    }

    private function head(int $type, int $n): string
    {
        return $n < 24 ? chr(($type << 5) | $n) : ($n < 256 ? chr(($type << 5) | 24).chr($n) : chr(($type << 5) | 25).pack('n', $n));
    }

    private function text(string $s): string
    {
        return $this->head(3, strlen($s)).$s;
    }

    private function bytes(string $s): string
    {
        return $this->head(2, strlen($s)).$s;
    }

    private function num(int $n): string
    {
        return $n >= 0 ? $this->head(0, $n) : $this->head(1, -1 - $n);
    }

    private function client(array $options, string $type, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $options['options']['publicKey']['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
    }

    public function register(array $options, int $flags = 0x45, string $origin = 'https://orientation.example.test'): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = chr(0xA5).$this->num(1).$this->num(2).$this->num(3).$this->num(-7).$this->num(-1).$this->num(1).$this->num(-2).$this->bytes($ec['x']).$this->num(-3).$this->bytes($ec['y']);
        $auth = hash('sha256', 'orientation.example.test', true).chr($flags).pack('N', 0).str_repeat(chr(0), 16).pack('n', strlen($this->id)).$this->id.$cose;
        $att = chr(0xA3).$this->text('fmt').$this->text('none').$this->text('attStmt').chr(0xA0).$this->text('authData').$this->bytes($auth);

        return ['challenge_id' => $options['challenge_id'], 'id' => S::encode($this->id), 'clientDataJSON' => S::encode($this->client($options, 'webauthn.create', $origin)), 'attestationObject' => S::encode($att), 'label' => 'Work phone'];
    }

    public function assertion(array $options, int $counter = 1, int $flags = 5, string $origin = 'https://orientation.example.test', string $rp = 'orientation.example.test'): array
    {
        $client = $this->client($options, 'webauthn.get', $origin);
        $auth = hash('sha256', $rp, true).chr($flags).pack('N', $counter);
        openssl_sign($auth.hash('sha256', $client, true), $sig, $this->key, OPENSSL_ALGO_SHA256);

        return ['challenge_id' => $options['challenge_id'], 'id' => S::encode($this->id), 'clientDataJSON' => S::encode($client), 'authenticatorData' => S::encode($auth), 'signature' => S::encode($sig)];
    }
}
