<?php namespace RainLab\User\Classes\AuthManager;

use Str;
use Hash;
use Crypt;
use Request;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

/**
 * HasJwtTokens
 *
 * @package october\auth
 * @author Alexey Bobkov, Samuel Georges
 */
trait HasJwtTokens
{
    /**
     * getBearerToken
     */
    public function getBearerToken(): ?string
    {
        $user = $this->getUser();
        if (!$user) {
            return null;
        }

        // Secret key
        $secretKey = Crypt::getKey();
        if (!$secretKey) {
            throw new \Illuminate\Encryption\MissingAppKeyException;
        }

        // Prepare metadata
        $tokenId = base64_encode(Str::random(16));
        $issuedAt = Carbon::now();
        $expire = $issuedAt->addMinutes(6)->getTimestamp();
        $serverName = Request::getHost();

        // Prepare payload
        $persistCode = $user->persist_code ?: $user->getPersistCode();
        $data = [
            'login' => $user->getLogin(),
            'hash' => Hash::make($persistCode)
        ];

        // Create the token as an array
        $data = [
            'iat' => $issuedAt->getTimestamp(),
            'jti' => $tokenId,
            'iss' => $serverName,
            'nbf' => $issuedAt->getTimestamp(),
            'exp' => $expire,
            'data' => $data
        ];

        // Encode the array to a JWT string.
        return JWT::encode($data, $secretKey, 'HS512');
    }

    /**
     * checkBearerToken
     */
    public function checkBearerToken(string $jwtToken)
    {
        if (!strlen(trim($jwtToken))) {
            return false;
        }

        // Secret key
        $secretKey = Crypt::getKey();
        if (!$secretKey) {
            throw new \Illuminate\Encryption\MissingAppKeyException;
        }

        // Decode token
        try {
            JWT::$leeway = 60;
            $token = JWT::decode($jwtToken, new Key($secretKey, 'HS512'));
        }
        catch (Exception $ex) {
            return false;
        }

        // Validate claims
        $now = Carbon::now();
        $serverName = Request::getHost();
        if (
            $token->iss !== $serverName ||
            $token->nbf > $now->getTimestamp() ||
            $token->exp < $now->getTimestamp()
        ) {
            return false;
        }

        // Locate payload
        $login = $token->data['login'] ?? null;
        $hash = $token->data['hash'] ?? null;
        if (!$login || !$hash) {
            return false;
        }

        // Locate user
        $user = $this->findUserByLogin($login);
        if (!$user || !$user->persist_code) {
            return false;
        }

        // Persist code check failed
        if ($hash !== Hash::make($user->persist_code)) {
            return false;
        }

        // Pass
        $this->user = $user;

        // Check and reset
        if (!$this->check()) {
            $this->user = null;
        }
    }
}
