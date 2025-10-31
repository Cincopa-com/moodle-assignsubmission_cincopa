<?php

class Encrypter
{
    const SALT_SIZE = 16;
    const IV_SIZE   = 16;
    const KEY_SIZE  = 32;
    const ITER      = 100000;

    public static function encrypt($password, $plaintext)
    {
        $salt = self::randomBytes(self::SALT_SIZE);
        $key  = self::deriveKey($password, $salt);
        $iv = openssl_random_pseudo_bytes(self::IV_SIZE);
        
        $encrypted = openssl_encrypt(
            $plaintext,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        $result = $salt . $iv . $encrypted;
        return self::base64UrlEncode($result);
    }

    public static function decrypt($password, $b64)
    {
        $all = self::base64UrlDecode($b64);
        
        if (strlen($all) < self::SALT_SIZE + self::IV_SIZE) {
            throw new InvalidArgumentException("Ciphertext too short.");
        }

        $salt = substr($all, 0, self::SALT_SIZE);
        $iv   = substr($all, self::SALT_SIZE, self::IV_SIZE);
        $offset = self::SALT_SIZE + self::IV_SIZE;
        $ciphertext = substr($all, $offset);

        $key = self::deriveKey($password, $salt);

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new RuntimeException("Decryption failed.");
        }

        return $decrypted;
    }

    public static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode($text)
    {
        $padded = strtr($text, '-_', '+/');
        $mod = strlen($padded) % 4;
        
        if ($mod === 2) {
            $padded .= '==';
        } elseif ($mod === 3) {
            $padded .= '=';
        }
        
        return base64_decode($padded);
    }

    private static function deriveKey($password, $salt)
    {
        $ikm = $password;
        $keySize = 32;

        // HKDF-Extract
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        // HKDF-Expand
        $okm = '';
        $t = '';
        $counter = 1;

        while (strlen($okm) < $keySize) {
            $input = $t . chr($counter);
            $t = hash_hmac('sha256', $input, $prk, true);
            $okm .= $t;
            $counter++;
        }

        return substr($okm, 0, $keySize);
    }

    private static function randomBytes($n)
    {
        return random_bytes($n);
    }
}

function createSelfGeneratedTempTokenV3getTempAPIKeyV2(
    $parent_token, 
    $expire, // Can be DateTime object or string
    $permissions = null, 
    $rid = null, 
    $fid = null, 
    $rrid = null,
    $sourceipv4 = null, 
    $host = null
) {
    if (empty($parent_token)) {
        throw new Exception("empty parent_token not allowed");
    }

    $keyfreg = explode('i', $parent_token, 2);
    $accid = $keyfreg[0];
    $last4_parent_token = substr($parent_token, -4);

    $payload = '';

    // Handle DateTime object or string
    if ($expire instanceof DateTime) {
        $utc = clone $expire;
        $utc->setTimezone(new DateTimeZone('UTC'));
        
        // Get microseconds
        $micro = $utc->format('u');
        
        // Format: YYYY-MM-DDTHH:MM:SS.FFFFFFFZ (7 decimal places)
        $formatted = $utc->format('Y-m-d\TH:i:s') . '.' . str_pad($micro, 7, '0') . 'Z';
    } else {
        // If it's already a formatted string, use it as-is
        $formatted = $expire;
    }
    
    $payload .= $formatted;

    if ($permissions !== null) {
        $payload .= '!p' . $permissions;
    }

    if ($rid !== null) {
        $payload .= '!r' . $rid;
    }

    if ($fid !== null) {
        $payload .= '!f' . $fid;
    }

    if ($rrid !== null) {
        $payload .= '!d' . $rrid;
    }

    if ($sourceipv4 !== null) {
        $payload .= '!i' . $sourceipv4;
    }

    if ($host !== null) {
        $payload .= '!h' . $host;
    }

    $emsg = Encrypter::encrypt($parent_token, $payload);

    return sprintf("%si3%s%s", $accid, $last4_parent_token, $emsg);
}

// Helper function to parse and decrypt tokens
function parseTempToken($token, $parent_token) {
    if (preg_match('/^(\d+)i3(.{4})(.+)$/', $token, $matches)) {
        $accid = $matches[1];
        $last4 = $matches[2];
        $encrypted = $matches[3];
        
        try {
            $decrypted = Encrypter::decrypt($parent_token, $encrypted);
            return [
                'accid' => $accid,
                'last4' => $last4,
                'payload' => $decrypted
            ];
        } catch (Exception $e) {
            throw new Exception("Decryption failed: " . $e->getMessage());
        }
    }
    throw new Exception("Invalid token format");
}