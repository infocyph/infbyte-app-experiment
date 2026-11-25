<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Exception\Token\InvalidTokenException;
use Infocyph\Epicrypt\Token\Jwt\Enum\JweKeyManagementAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwe;

function interopB64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function interopUnb64(string $value): string
{
    $bytes = base64_decode(strtr($value, '-_', '+/'), true);
    verify(is_string($bytes), 'Invalid independent Base64URL input.');
    return $bytes;
}

// Test-only RFC 3394 oracle using the native AES block primitive, not Epicrypt.
function interopAesWrap(string $key, string $value, bool $unwrap): string
{
    $n = 4;
    verify(strlen($value) === ($unwrap ? 40 : 32), 'Unexpected AES-KW test input length.');
    $a = $unwrap ? substr($value, 0, 8) : str_repeat("\xa6", 8);
    $blocks = array_values(str_split($unwrap ? substr($value, 8) : $value, 8));
    foreach ($unwrap ? range(5, 0) : range(0, 5) as $j) {
        foreach ($unwrap ? range($n - 1, 0) : range(0, $n - 1) as $i) {
            $counter = pack('N2', 0, $n * $j + $i + 1);
            if ($unwrap) {
                $block = openssl_decrypt(($a ^ $counter) . $blocks[$i], 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
                verify(is_string($block) && strlen($block) === 16, 'Native AES-KW unwrap failed.');
                $a = substr($block, 0, 8);
            } else {
                $block = openssl_encrypt($a . $blocks[$i], 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
                verify(is_string($block) && strlen($block) === 16, 'Native AES-KW wrap failed.');
                $a = substr($block, 0, 8) ^ $counter;
            }
            $blocks[$i] = substr($block, 8, 8);
        }
    }
    if ($unwrap) {
        verify(hash_equals(str_repeat("\xa6", 8), $a), 'RFC 3394 integrity register mismatch.');
    }
    return ($unwrap ? '' : $a) . implode('', $blocks);
}

function interopRsaOaep(string $key, string $input, bool $decrypt): string
{
    $file = tempnam(sys_get_temp_dir(), 'lab-oaep-');
    verify(is_string($file), 'Temporary key file unavailable.');
    try {
        verify(chmod($file, 0600) && file_put_contents($file, $key) === strlen($key), 'Disposable key could not be written.');
        $command = ['openssl', 'pkeyutl', $decrypt ? '-decrypt' : '-encrypt', '-inkey', $file,
            '-pkeyopt', 'rsa_padding_mode:oaep', '-pkeyopt', 'rsa_oaep_md:sha256', '-pkeyopt', 'rsa_mgf1_md:sha256'];
        if (!$decrypt) {
            $command[] = '-pubin';
        }
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        verify(is_resource($process), 'Native OpenSSL CLI unavailable.');
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        verify(proc_close($process) === 0 && is_string($output) && $output !== '', 'Native RSA-OAEP-256 operation failed.');
        return $output;
    } finally {
        unlink($file);
    }
}

function interopConcatKey(string $private, string $public, string $algorithm, array $header): string
{
    $secret = sodium_crypto_scalarmult($private, $public);
    $info = '';
    foreach ([$algorithm, interopUnb64($header['apu']), interopUnb64($header['apv'])] as $value) {
        $info .= pack('N', strlen($value)) . $value;
    }
    return hash('sha256', pack('N', 1) . $secret . $info . pack('N', 256), true);
}

/** @return array{string,string} */
function interopGcmSeal(string $plaintext, string $key, string $iv, string $aad): array
{
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    verify(is_string($cipher) && strlen($tag) === 16, 'Native GCM seal failed.');
    return [$cipher, $tag];
}

/** @return array<string,string> */
function interopJweDocument(string $token, string $format): array
{
    if ($format === 'flattened') {
        return json_decode($token, true, flags: JSON_THROW_ON_ERROR);
    }
    $parts = explode('.', $token);
    verify(count($parts) === 5, 'Independent compact serialization shape mismatch.');
    return array_combine(['protected', 'encrypted_key', 'iv', 'ciphertext', 'tag'], $parts);
}

function interopJweSerialize(array $document, string $format): string
{
    return $format === 'flattened' ? json_encode($document, JSON_THROW_ON_ERROR) : implode('.', [
        $document['protected'], $document['encrypted_key'] ?? '', $document['iv'], $document['ciphertext'], $document['tag'],
    ]);
}

function jweInteropWorkload(string $algorithmName, string $format, string $direction): void
{
    $algorithm = JweKeyManagementAlgorithm::from($algorithmName);
    $ecdh = in_array($algorithmName, ['ECDH-ES', 'ECDH-ES+A256KW'], true);
    $rsa = $algorithmName === 'RSA-OAEP-256';
    $private = random_bytes(32);
    $public = $private;
    if ($ecdh) {
        $public = sodium_crypto_scalarmult_base($private);
    } elseif ($rsa) {
        [$private, $public] = josePemPair(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    }
    $headers = $ecdh ? ['apu' => interopB64("sender\0tenant"), 'apv' => interopB64('recipient')] : [];
    $payload = "\x00\xff" . random_bytes(8192);
    $sender = new Jwe($public, $algorithm, keyId: 'recipient');
    $receiver = new Jwe($private, $algorithm, keyId: 'recipient');
    $decrypt = static fn(string $value): string => $format === 'compact' ? $receiver->decryptCompact($value) : $receiver->decryptFlattened($value);
    if ($direction === 'library-to-native') {
        $token = $format === 'compact' ? $sender->encryptCompact($payload, $headers) : $sender->encryptFlattened($payload, $headers);
        $doc = interopJweDocument($token, $format);
        $header = json_decode(interopUnb64($doc['protected']), true, flags: JSON_THROW_ON_ERROR);
        verify($header['alg'] === $algorithmName && $header['enc'] === 'A256GCM' && $header['kid'] === 'recipient', 'Protected JOSE identity mismatch.');
        $wrapped = interopUnb64($doc['encrypted_key'] ?? '');
        $key = $private;
        if ($ecdh) {
            verify($header['epk']['kty'] === 'OKP' && $header['epk']['crv'] === 'X25519' && !isset($header['epk']['d']), 'Ephemeral key disclosure or shape mismatch.');
            $key = interopConcatKey($private, interopUnb64($header['epk']['x']), $algorithmName === 'ECDH-ES' ? 'A256GCM' : $algorithmName, $header);
        }
        $cek = match ($algorithmName) {
            'dir', 'ECDH-ES' => $key,
            'A256KW', 'ECDH-ES+A256KW' => interopAesWrap($key, $wrapped, true),
            'A256GCMKW' => openssl_decrypt($wrapped, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, interopUnb64($header['iv']), interopUnb64($header['tag'])),
            'RSA-OAEP-256' => interopRsaOaep($key, $wrapped, true),
        };
        verify(is_string($cek) && strlen($cek) === 32, 'Independent content key resolution failed.');
        if (in_array($algorithmName, ['dir', 'ECDH-ES'], true)) {
            verify($wrapped === '', 'Direct mode published a wrapped key.');
        }
        verify(openssl_decrypt(interopUnb64($doc['ciphertext']), 'aes-256-gcm', $cek, OPENSSL_RAW_DATA,
            interopUnb64($doc['iv']), interopUnb64($doc['tag']), $doc['protected']) === $payload, 'Independent native authentication/decryption failed.');
    } else {
        verify($direction === 'native-to-library', 'Unknown interoperability direction.');
        $header = ['alg' => $algorithmName, 'enc' => 'A256GCM', 'kid' => 'recipient'] + $headers;
        $cek = random_bytes(32);
        $key = $public;
        if ($ecdh) {
            $ephemeral = random_bytes(32);
            $header['epk'] = ['kty' => 'OKP', 'crv' => 'X25519', 'x' => interopB64(sodium_crypto_scalarmult_base($ephemeral))];
            $key = interopConcatKey($ephemeral, $public, $algorithmName === 'ECDH-ES' ? 'A256GCM' : $algorithmName, $header);
        }
        $wrapped = '';
        switch ($algorithmName) {
            case 'dir':
            case 'ECDH-ES':
                $cek = $key;
                break;
            case 'A256KW':
            case 'ECDH-ES+A256KW':
                $wrapped = interopAesWrap($key, $cek, false);
                break;
            case 'A256GCMKW':
                $iv = random_bytes(12);
                [$wrapped, $tag] = interopGcmSeal($cek, $key, $iv, '');
                $header['iv'] = interopB64($iv);
                $header['tag'] = interopB64($tag);
                break;
            case 'RSA-OAEP-256':
                $wrapped = interopRsaOaep($public, $cek, false);
                break;
        }
        $protected = interopB64(json_encode($header, JSON_THROW_ON_ERROR));
        $iv = random_bytes(12);
        [$cipher, $tag] = interopGcmSeal($payload, $cek, $iv, $protected);
        $doc = ['protected' => $protected, 'iv' => interopB64($iv), 'ciphertext' => interopB64($cipher), 'tag' => interopB64($tag)];
        if ($wrapped !== '') {
            $doc['encrypted_key'] = interopB64($wrapped);
        }
        $token = interopJweSerialize($doc, $format);
    }
    verify($decrypt($token) === $payload, 'Valid independent JWE exchange failed.');
    foreach (['iv', 'ciphertext', 'tag'] as $field) {
        $mutated = $doc;
        $bytes = interopUnb64($mutated[$field]);
        $bytes[0] = chr(ord($bytes[0]) ^ 1);
        $mutated[$field] = interopB64($bytes);
        expectFailure(static fn() => $decrypt(interopJweSerialize($mutated, $format)), InvalidTokenException::class, 'Altered JWE accepted: ' . $field);
    }
    $header['kid'] = 'foreign';
    $mutated = $doc;
    $mutated['protected'] = interopB64(json_encode($header, JSON_THROW_ON_ERROR));
    expectFailure(static fn() => $decrypt(interopJweSerialize($mutated, $format)), InvalidTokenException::class, 'Foreign protected identity accepted.');
    verify($decrypt($token) === $payload, 'JWE recovery after rejected documents failed.');
    echo "$algorithmName $format $direction native interoperability passed\n";
}
