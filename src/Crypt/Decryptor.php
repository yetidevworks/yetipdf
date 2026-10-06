<?php

declare(strict_types=1);

namespace YetiPdf\Crypt;

use YetiPdf\Core\PdfString;
use YetiPdf\Exception\EncryptedException;

/**
 * The standard security handler (RC4 40-128 bit, AES-128, AES-256), read side only.
 *
 * Most "protected" PDFs have an empty user password and only restrict printing or copying,
 * so they open without asking anyone for anything.
 */
final class Decryptor
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private string $key = '';
    private int $version;
    private int $revision;
    /** none | rc4 | aes128 | aes256 */
    private string $streamMethod = 'rc4';
    private string $stringMethod = 'rc4';

    /**
     * @param array<string, mixed> $encrypt the resolved /Encrypt dictionary
     * @param string $id first element of the trailer /ID
     */
    public function __construct(array $encrypt, string $id, string $password = '')
    {
        $filter = $encrypt['Filter'] ?? '/Standard';
        if ($filter !== '/Standard') {
            throw new EncryptedException('Unsupported security handler ' . (is_string($filter) ? $filter : ''));
        }

        $this->version = (int)($encrypt['V'] ?? 0);
        $this->revision = (int)($encrypt['R'] ?? 2);
        $o = self::str($encrypt['O'] ?? null);
        $u = self::str($encrypt['U'] ?? null);
        $p = (int)($encrypt['P'] ?? 0);
        $bits = (int)($encrypt['Length'] ?? 40);
        $encryptMetadata = ($encrypt['EncryptMetadata'] ?? true) !== false;

        if ($this->version >= 4) {
            $cf = is_array($encrypt['CF'] ?? null) ? $encrypt['CF'] : [];
            $this->streamMethod = self::method($cf, $encrypt['StmF'] ?? '/Identity');
            $this->stringMethod = self::method($cf, $encrypt['StrF'] ?? '/Identity');
            if ($this->streamMethod === 'none' && $this->stringMethod === 'none') {
                // Only embedded files are encrypted; everything this library reads is in the clear.
                return;
            }
            $stmf = $encrypt['StmF'] ?? null;
            $filterLength = is_string($stmf) ? ($cf[substr($stmf, 1)]['Length'] ?? null) : null;
            if (is_int($filterLength) && $filterLength > 0) {
                // Crypt filters give the key length in bytes, though some writers use bits here too.
                $bits = $filterLength <= 40 ? $filterLength * 8 : $filterLength;
            }
            if ($this->streamMethod === 'aes128' || $this->stringMethod === 'aes128') {
                $bits = 128;
            }
        } elseif ($this->version === 1) {
            $bits = 40;
        }

        if ($this->version >= 5) {
            if (!function_exists('openssl_decrypt')) {
                throw new EncryptedException('This PDF uses AES-256 encryption, which needs the openssl extension');
            }
            $this->key = $this->keyV5($password, $u, $o, self::str($encrypt['UE'] ?? null), self::str($encrypt['OE'] ?? null));
            return;
        }

        $n = $this->revision === 2 ? 5 : max(5, min(16, intdiv($bits, 8)));
        $suffix = $o . pack('V', $p & 0xFFFFFFFF) . $id . (($this->revision >= 4 && !$encryptMetadata) ? "\xFF\xFF\xFF\xFF" : '');

        // The password may be the user password or the owner password; either opens the file.
        foreach ([substr($password . self::PAD, 0, 32), $this->userPasswordFromOwner($password, $o, $n)] as $padded) {
            $hash = md5($padded . $suffix, true);
            if ($this->revision >= 3) {
                for ($i = 0; $i < 50; $i++) {
                    $hash = md5(substr($hash, 0, $n), true);
                }
            }
            $this->key = substr($hash, 0, $n);
            if ($u === '' || $this->checkUserPassword($u, $id)) {
                return;
            }
        }
        throw new EncryptedException('This PDF needs a password to open');
    }

    /** Recovers the padded user password from the /O entry, given the owner password. */
    private function userPasswordFromOwner(string $ownerPassword, string $o, int $n): string
    {
        $hash = md5(substr($ownerPassword . self::PAD, 0, 32), true);
        if ($this->revision >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5($hash, true);
            }
        }
        $key = substr($hash, 0, $n);
        $x = substr($o, 0, 32);
        if ($this->revision === 2) {
            return self::rc4($key, $x);
        }
        for ($i = 19; $i >= 0; $i--) {
            $k = '';
            for ($j = 0; $j < $n; $j++) {
                $k .= chr(ord($key[$j]) ^ $i);
            }
            $x = self::rc4($k, $x);
        }
        return $x;
    }

    public function decryptStream(string $data, int $num, int $gen): string
    {
        return $this->decrypt($data, $num, $gen, $this->streamMethod);
    }

    public function decryptString(PdfString|string $s, int $num, int $gen): string
    {
        return $this->decrypt($s instanceof PdfString ? $s->value : $s, $num, $gen, $this->stringMethod);
    }

    private function decrypt(string $data, int $num, int $gen, string $method): string
    {
        if ($method === 'none' || $data === '') {
            return $data;
        }
        if ($method === 'aes256') {
            return self::aesCbc($data, $this->key, 'aes-256-cbc');
        }
        $key = $this->key . substr(pack('V', $num), 0, 3) . substr(pack('V', $gen), 0, 2);
        if ($method === 'aes128') {
            $key = substr(md5($key . 'sAlT', true), 0, min(16, strlen($this->key) + 5));
            return self::aesCbc($data, $key, 'aes-128-cbc');
        }
        return self::rc4(substr(md5($key, true), 0, min(16, strlen($this->key) + 5)), $data);
    }

    private function checkUserPassword(string $u, string $id): bool
    {
        if ($this->revision === 2) {
            return self::rc4($this->key, self::PAD) === substr($u, 0, 32);
        }
        $x = self::rc4($this->key, md5(self::PAD . $id, true));
        for ($i = 1; $i <= 19; $i++) {
            $k = '';
            for ($j = 0, $n = strlen($this->key); $j < $n; $j++) {
                $k .= chr(ord($this->key[$j]) ^ $i);
            }
            $x = self::rc4($k, $x);
        }
        return substr($x, 0, 16) === substr($u, 0, 16);
    }

    private function keyV5(string $password, string $u, string $o, string $ue, string $oe): string
    {
        $password = substr($password, 0, 127);
        $u48 = substr($u, 0, 48);
        if ($this->hashV5($password, substr($u, 32, 8), '') === substr($u, 0, 32)) {
            $intermediate = $this->hashV5($password, substr($u, 40, 8), '');
            $wrapped = $ue;
        } elseif ($this->hashV5($password, substr($o, 32, 8), $u48) === substr($o, 0, 32)) {
            $intermediate = $this->hashV5($password, substr($o, 40, 8), $u48);
            $wrapped = $oe;
        } else {
            throw new EncryptedException('This PDF needs a password to open');
        }
        $key = openssl_decrypt($wrapped, 'aes-256-cbc', $intermediate, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        if ($key === false || strlen($key) !== 32) {
            throw new EncryptedException('Could not derive the AES-256 file key');
        }
        return $key;
    }

    private function hashV5(string $password, string $salt, string $userKey): string
    {
        $k = hash('sha256', $password . $salt . $userKey, true);
        if ($this->revision < 6) {
            return $k;
        }
        for ($i = 0; ; $i++) {
            $k1 = str_repeat($password . $k . $userKey, 64);
            $e = (string)openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $sum = 0;
            for ($j = 0; $j < 16; $j++) {
                $sum += ord($e[$j]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
            if ($i >= 63 && ord($e[strlen($e) - 1]) <= $i - 31) {
                break;
            }
        }
        return substr($k, 0, 32);
    }

    /** @param array<string, mixed> $cf */
    private static function method(array $cf, mixed $name): string
    {
        if (!is_string($name) || $name === '/Identity') {
            return 'none';
        }
        $filter = $cf[substr($name, 1)] ?? null;
        $cfm = is_array($filter) ? ($filter['CFM'] ?? '/None') : '/None';
        return match ($cfm) {
            '/V2' => 'rc4',
            '/AESV2' => 'aes128',
            '/AESV3' => 'aes256',
            default => 'none',
        };
    }

    private static function aesCbc(string $data, string $key, string $cipher): string
    {
        if (!function_exists('openssl_decrypt') || strlen($data) < 32) {
            return '';
        }
        $iv = substr($data, 0, 16);
        $body = substr($data, 16);
        $body = substr($body, 0, strlen($body) - (strlen($body) % 16));
        $out = openssl_decrypt($body, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($out === false || $out === '') {
            return '';
        }
        $pad = ord($out[strlen($out) - 1]);
        return ($pad >= 1 && $pad <= 16) ? substr($out, 0, -$pad) : $out;
    }

    public static function rc4(string $key, string $data): string
    {
        $klen = strlen($key);
        if ($klen === 0) {
            return $data;
        }
        $s = range(0, 255);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $klen])) & 0xFF;
            $t = $s[$i];
            $s[$i] = $s[$j];
            $s[$j] = $t;
        }
        $i = $j = 0;
        $stream = '';
        $len = strlen($data);
        // Build the keystream in blocks and XOR whole strings, which is far quicker than XORing byte by byte.
        for ($pos = 0; $pos < $len; $pos += 4096) {
            $n = min(4096, $len - $pos);
            $block = [];
            for ($k = 0; $k < $n; $k++) {
                $i = ($i + 1) & 0xFF;
                $j = ($j + $s[$i]) & 0xFF;
                $t = $s[$i];
                $s[$i] = $s[$j];
                $s[$j] = $t;
                $block[] = $s[($s[$i] + $s[$j]) & 0xFF];
            }
            $stream .= pack('C*', ...$block);
        }
        return $data ^ $stream;
    }

    private static function str(mixed $v): string
    {
        return $v instanceof PdfString ? $v->value : '';
    }
}
