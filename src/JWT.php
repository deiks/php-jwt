<?php

namespace Lindelius\JWT;

use ArrayAccess;
use Iterator;
use Lindelius\JWT\Exception\BeforeValidException;
use Lindelius\JWT\Exception\DomainException;
use Lindelius\JWT\Exception\ExpiredJwtException;
use Lindelius\JWT\Exception\InvalidArgumentException;
use Lindelius\JWT\Exception\InvalidAudienceException;
use Lindelius\JWT\Exception\InvalidJwtException;
use Lindelius\JWT\Exception\InvalidSignatureException;
use Lindelius\JWT\Exception\JsonException;
use Lindelius\JWT\Exception\RuntimeException;

/**
 * An abstract base class for JWT models.
 */
abstract class JWT implements Iterator
{
    public const HS256 = 'HS256';
    public const HS384 = 'HS384';
    public const HS512 = 'HS512';

    public const RS256 = 'RS256';
    public const RS384 = 'RS384';
    public const RS512 = 'RS512';

    /**
     * Leeway time (in seconds) to account for clock skew between servers.
     *
     * @var int
     */
    public static $leeway = 0;

    /**
     * The hashing algorithm to use when encoding the JWT.
     *
     * @var string|null
     */
    private $algorithm;

    /**
     * The set of claims included with the JWT.
     *
     * @var array
     */
    private $claims = [];

    /**
     * The hash representation of the JWT.
     *
     * @var string|null
     */
    private $hash;

    /**
     * The header data included with the JWT.
     *
     * @var array
     */
    private $header = [];

    /**
     * Get the current value for a given claim.
     *
     * @param  string $claim
     * @return mixed
     */
    public function __get(string $claim)
    {
        return $this->getClaim($claim);
    }

    /**
     * Check whether a given claim has been set.
     *
     * @param  string $claim
     * @return bool
     */
    public function __isset(string $claim): bool
    {
        return isset($this->claims[$claim]);
    }

    /**
     * Set a new value for a given claim.
     *
     * @param  string $claim
     * @param  mixed  $value
     * @return void
     */
    public function __set(string $claim, $value): void
    {
        $this->setClaim($claim, $value);
    }

    /**
     * Get the string representation of the JWT (i.e. its hash).
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getHash();
    }

    /**
     * Unset a given claim.
     *
     * @param  string $claimName
     * @return void
     */
    public function __unset(string $claimName): void
    {
        // If the claim exists, clear the hash since it will no longer be valid
        if (array_key_exists($claimName, $this->claims)) {
            $this->hash = null;
        }

        unset($this->claims[$claimName]);
    }

    /**
     * Get the value of the "current" claim.
     *
     * @see https://www.php.net/manual/en/iterator.current.php
     * @return mixed
     */
    public function current()
    {
        return current($this->claims);
    }

    /**
     * Encode the JWT object and return the resulting hash.
     *
     * @param  mixed $key
     * @return string
     * @throws DomainException
     * @throws JsonException
     * @throws RuntimeException
     */
    public function encode($key): string
    {
        $segments = [];

        // Build the main segments of the JWT
        $segments[] = url_safe_base64_encode(static::jsonEncode($this->header));
        $segments[] = url_safe_base64_encode(static::jsonEncode($this->claims));

        // Sign the JWT with the given key
        $segments[] = url_safe_base64_encode(
            $this->generateSignature($key, implode('.', $segments))
        );

        return $this->hash = implode('.', $segments);
    }

    /**
     * Get the current value of a given claim.
     *
     * @param  string $name
     * @return mixed
     */
    public function getClaim(string $name)
    {
        return $this->claims[$name] ?? null;
    }

    /**
     * Get the entire set of claims included in the JWT.
     *
     * @return array
     */
    public function getClaims(): array
    {
        return $this->claims;
    }

    /**
     * Get the hash representation of the JWT, or null if it has not yet been
     * signed.
     *
     * @return string|null
     */
    public function getHash(): ?string
    {
        return $this->hash;
    }

    /**
     * Get the header data included with the JWT.
     *
     * @return array
     */
    public function getHeader(): array
    {
        return $this->header;
    }

    /**
     * Get the current value of a given header field.
     *
     * @param  string $field
     * @return mixed
     */
    public function getHeaderField(string $field)
    {
        return $this->header[$field] ?? null;
    }

    /**
     * Get the name of the "current" claim.
     *
     * @see https://www.php.net/manual/en/iterator.key.php
     * @return mixed
     */
    public function key()
    {
        return key($this->claims);
    }

    /**
     * Advance the iterator to the "next" claim.
     *
     * @see https://www.php.net/manual/en/iterator.next.php
     * @return void
     */
    public function next(): void
    {
        next($this->claims);
    }

    /**
     * Rewind the claims iterator.
     *
     * @see https://www.php.net/manual/en/iterator.rewind.php
     * @return void
     */
    public function rewind(): void
    {
        reset($this->claims);
    }

    /**
     * Set a new value for a given claim.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function setClaim(string $name, $value): void
    {
        $this->claims[$name] = $value;

        // Clear the generated hash since it's no longer valid
        $this->hash = null;
    }

    /**
     * Set a new value for a given header field.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return void
     */
    public function setHeaderField(string $field, $value): void
    {
        $this->header[$field] = $value;

        if ($field === 'alg') {
            $this->algorithm = $value;
        }

        // Clear the generated hash since it's no longer valid
        $this->hash = null;
    }

    /**
     * Check whether the current position in the claims array is valid.
     *
     * @see https://www.php.net/manual/en/iterator.valid.php
     * @return bool
     */
    public function valid(): bool
    {
        return $this->key() !== null && $this->key() !== false;
    }

    /**
     * Verify that the JWT is correctly formatted and that the given signature
     * is valid.
     *
     * @param  mixed       $key
     * @param  string|null $audience
     * @return bool
     * @throws DomainException
     * @throws InvalidJwtException
     * @throws JsonException
     * @throws RuntimeException
     */
    public function verify($key, ?string $audience = null): bool
    {
        // If the app is using multiple keys, attempt to find the correct one
        if (is_array($key) || $key instanceof ArrayAccess) {
            $kid = $this->getHeaderField('kid');

            if ($kid !== null) {
                if (!is_string($kid) && !is_numeric($kid)) {
                    throw new InvalidJwtException('Invalid "kid" value. Unable to lookup secret key.');
                }

                $key = array_key_exists($kid, $key) ? $key[$kid] : null;
            }
        }

        // Verify the signature
        $segments = explode('.', $this->hash ?: '');

        if (count($segments) !== 3) {
            throw new InvalidSignatureException('Unable to verify the signature due to an invalid JWT hash.');
        }

        $dataToSign = $segments[0] . '.' . $segments[1];
        $signature  = url_safe_base64_decode($segments[2]);

        if (!$this->verifySignature($key, $dataToSign, $signature)) {
            throw new InvalidSignatureException('Invalid JWT signature.');
        }

        // Validate the audience constraint, if included
        if (isset($this->aud)) {
            // Make sure the audience claim is set to a valid value
            if (is_array($this->aud)) {
                $expectedIndex = 0;

                foreach ($this->aud as $index => $aud) {
                    if ($index !== $expectedIndex || !is_string($aud)) {
                        throw new InvalidJwtException('Invalid "aud" value.');
                    }

                    $expectedIndex++;
                }

                $validAudiences = $this->aud;
            } elseif (is_string($this->aud)) {
                $validAudiences = [$this->aud];
            } else {
                throw new InvalidJwtException('Invalid "aud" value.');
            }

            if (!in_array($audience, $validAudiences)) {
                throw new InvalidAudienceException('Invalid JWT audience.');
            }
        }

        // Validate the "expires at" time constraint, if included
        if (isset($this->exp)) {
            if (!is_numeric($this->exp)) {
                throw new InvalidJwtException('Invalic "exp" value.');
            } elseif ((time() - static::$leeway) >= (float) $this->exp) {
                throw new ExpiredJwtException('The JWT has expired.');
            }
        }

        // Validate the "issued at" time constraint, if included
        if (isset($this->iat)) {
            if (!is_numeric($this->iat)) {
                throw new InvalidJwtException('Invalid "iat" value.');
            } elseif ((time() + static::$leeway) < (float) $this->iat) {
                throw new BeforeValidException('The JWT is not yet valid.');
            }
        }

        // Validate the "not before" time constraint, if included
        if (isset($this->nbf)) {
            if (!is_numeric($this->nbf)) {
                throw new InvalidJwtException('Invalid "nbf" value.');
            } elseif ((time() + static::$leeway) < (float) $this->nbf) {
                throw new BeforeValidException('The JWT is not yet valid.');
            }
        }

        return true;
    }

    /**
     * Decode a given JWT hash and use the decoded data to populate the object.
     *
     * @param  string $hash
     * @return void
     * @throws InvalidJwtException
     */
    public function decodeAndInitialize(string $hash): void
    {
        $segments = explode('.', $hash);

        if (count($segments) !== 3) {
            throw new InvalidJwtException('Unexpected number of JWT segments.');
        }

        // Decode the JWT's segments
        if (false === ($decodedHeader = url_safe_base64_decode($segments[0]))) {
            throw new InvalidJwtException('Invalid header encoding.');
        }

        if (false === ($decodedClaims = url_safe_base64_decode($segments[1]))) {
            throw new InvalidJwtException('Invalid claims encoding.');
        }

        if (false === ($decodedSignature = url_safe_base64_decode($segments[2]))) {
            throw new InvalidJwtException('Invalid signature encoding.');
        }

        // Validate the decoded header
        if (empty($header = static::jsonDecode($decodedHeader))) {
            throw new InvalidJwtException('Invalid JWT header.');
        }

        if (!is_array($header) && !is_object($header)) {
            throw new InvalidArgumentException('Invalid JWT header.');
        } else {
            $header = (array) $header;
        }

        if (empty($header['typ']) || $header['typ'] !== 'JWT') {
            throw new InvalidJwtException('Invalid JWT type.');
        }

        // Validate the decoded claims
        if (empty($claims = static::jsonDecode($decodedClaims))) {
            throw new InvalidJwtException('Invalid set of JWT claims.');
        }

        if (!is_array($claims) && !is_object($claims)) {
            throw new InvalidArgumentException('Invalid set of JWT claims.');
        } else {
            $claims = (array) $claims;
        }

        // Populate the JWT with the decoded data
        foreach ($header as $field => $value) {
            $this->setHeaderField($field, $value);
        }

        foreach ($claims as $name => $value) {
            $this->setClaim($name, $value);
        }

        // Use the original hash to prevent verification failures due to encoding discrepancies
        $this->hash = $hash;
    }

    /**
     * Generate a signature for the JWT using a given key.
     *
     * @param  mixed  $key
     * @param  string $dataToSign
     * @return string
     * @throws DomainException
     * @throws RuntimeException
     */
    protected function generateSignature($key, string $dataToSign): string
    {
        $method = 'encodeWith' . $this->algorithm;

        if (!method_exists($this, $method)) {
            throw new DomainException('Unsupported hashing algorithm.');
        }

        $signature = call_user_func_array(
            [$this, $method],
            [$key, $dataToSign]
        );

        if (empty($signature) || !is_string($signature)) {
            throw new RuntimeException('Unable to sign the JWT.');
        }

        return $signature;
    }

    /**
     * Verify the JWT's signature using a given key.
     *
     * @param  mixed  $key
     * @param  string $dataToSign
     * @param  string $signature
     * @return bool
     * @throws DomainException
     * @throws RuntimeException
     */
    protected function verifySignature($key, string $dataToSign, string $signature): bool
    {
        $method = 'verifyWith' . $this->algorithm;

        if (!method_exists($this, $method)) {
            throw new DomainException('Unsupported hashing algorithm.');
        }

        $verified = call_user_func_array(
            [$this, $method],
            [$key, $dataToSign, $signature]
        );

        if (!is_bool($verified)) {
            throw new RuntimeException(sprintf('Invalid return value given from "%s".', $method));
        }

        return $verified;
    }

    /**
     * Create a new JWT.
     *
     * @param  string $algorithm
     * @return static
     */
    public static function create(string $algorithm)
    {
        $jwt = new static();
        $jwt->setHeaderField('alg', $algorithm);
        $jwt->setHeaderField('typ', 'JWT');

        return $jwt;
    }

    /**
     * Decode a JWT hash and return the resulting object.
     *
     * @param  string $hash
     * @return static
     * @throws InvalidJwtException
     */
    public static function decode(string $hash)
    {
        $jwt = new static();
        $jwt->decodeAndInitialize($hash);

        return $jwt;
    }

    /**
     * Decode a given JSON string.
     *
     * @param  string $json
     * @return mixed
     * @throws JsonException
     */
    protected static function jsonDecode(string $json)
    {
        $data  = json_decode($json);
        $error = json_last_error();

        if ($error !== JSON_ERROR_NONE) {
            throw new JsonException(sprintf('Unable to decode the given JSON string (%s).', $error));
        }

        return $data;
    }

    /**
     * Convert given data to its JSON representation.
     *
     * @param  mixed $data
     * @return string
     * @throws JsonException
     */
    protected static function jsonEncode($data): string
    {
        $json  = json_encode($data);
        $error = json_last_error();

        if ($error !== JSON_ERROR_NONE) {
            throw new JsonException(sprintf('Unable to encode the given data (%s).', $error));
        }

        return $json;
    }
}
