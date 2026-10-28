<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid as SymfonyUuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Passkeys (SEC-002, UACC-002) on web-auth/webauthn-lib: registration and assertion ceremonies.
 * Options are JSON in the WebAuthn "JSON" format (@simplewebauthn/browser optionsJSON); the
 * challenge state lives in the cache for config('security.webauthn.challenge_ttl') seconds and is single use.
 */
class WebAuthnService
{
    private ?SerializerInterface $serializer = null;

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(new AttestationStatementSupportManager([new NoneAttestationStatementSupport])))->create();
    }

    private function steps(): CeremonyStepManagerFactory
    {
        $f = new CeremonyStepManagerFactory;
        $f->setAllowedOrigins(config('security.webauthn.origins'));

        return $f;
    }

    private function rpId(): string
    {
        return config('security.webauthn.rp_id');
    }

    private function toJson(object $options): string
    {
        return $this->serializer()->serialize($options, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true, JsonEncode::OPTIONS => JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES]);
    }

    private function remember(array $state): string
    {
        $id = (string) Str::uuid();
        Cache::put("webauthn:{$id}", $state, (int) config('security.webauthn.challenge_ttl', 300));

        return $id;
    }

    private function take(string $challengeId, string $type): array
    {
        $state = Cache::pull("webauthn:{$challengeId}");
        if (! is_array($state) || ($state['type'] ?? null) !== $type) {
            throw new ApiException('unauthenticated', 'The passkey challenge expired. Try again.', 401);
        }

        return $state;
    }

    /** @return array{challenge_id: string, options: array} */
    public function registrationOptions(User $user): array
    {
        $exclude = Passkey::where('user_id', $user->id)->get()
            ->map(fn (Passkey $p) => PublicKeyCredentialDescriptor::create('public-key', Base64UrlSafe::decodeNoPadding($p->credential_id), $p->transports ?? []))->all();
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(config('security.webauthn.rp_name'), $this->rpId()),
            PublicKeyCredentialUserEntity::create($user->email, $user->id, $user->name ?: $user->email),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(null, AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED, AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            60000,
        );
        $json = $this->toJson($options);

        return ['challenge_id' => $this->remember(['type' => 'create', 'user_id' => $user->id, 'options' => $json]), 'options' => json_decode($json, true)];
    }

    public function register(User $user, string $challengeId, array $credential, ?string $name): Passkey
    {
        $state = $this->take($challengeId, 'create');
        if ($state['user_id'] !== $user->id) {
            throw new ApiException('unauthenticated', 'The passkey challenge belongs to another user.', 401);
        }
        /** @var PublicKeyCredentialCreationOptions $options */
        $options = $this->serializer()->deserialize($state['options'], PublicKeyCredentialCreationOptions::class, 'json');
        try {
            $pkc = $this->serializer()->deserialize(json_encode($credential), PublicKeyCredential::class, 'json');
            if (! $pkc->response instanceof AuthenticatorAttestationResponse) {
                throw new \InvalidArgumentException('Not an attestation response.');
            }
            $record = AuthenticatorAttestationResponseValidator::create($this->steps()->creationCeremony())->check($pkc->response, $options, $this->rpId());
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ApiException::validation(['credential' => ['The passkey could not be verified: '.$e->getMessage()]]);
        }
        $hash = hash('sha256', $record->publicKeyCredentialId);
        if (Passkey::where('credential_hash', $hash)->exists()) {
            throw ApiException::conflict('This passkey is already registered.');
        }

        return Passkey::create([
            'user_id' => $user->id,
            'name' => $name ?: 'Passkey '.now()->format('Y-m-d'),
            'credential_id' => Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            'credential_hash' => $hash,
            'public_key' => base64_encode($record->credentialPublicKey),
            'sign_count' => $record->counter,
            'transports' => $record->transports,
            'aaguid' => $record->aaguid->toRfc4122(),
            'backup_eligible' => (bool) $record->backupEligible,
        ]);
    }

    /** Sign-in options; with an email (second factor) only that user's passkeys are allowed. */
    public function authenticationOptions(?User $user = null): array
    {
        $allow = $user ? Passkey::where('user_id', $user->id)->get()
            ->map(fn (Passkey $p) => PublicKeyCredentialDescriptor::create('public-key', Base64UrlSafe::decodeNoPadding($p->credential_id), $p->transports ?? []))->all() : [];
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32), $this->rpId(), $allow,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED, 60000);
        $json = $this->toJson($options);

        return ['challenge_id' => $this->remember(['type' => 'get', 'user_id' => $user?->id, 'options' => $json]), 'options' => json_decode($json, true)];
    }

    /** Verifies an assertion and returns the passkey's user (and updates the signature counter). */
    public function authenticate(string $challengeId, array $credential, ?User $expected = null): User
    {
        $state = $this->take($challengeId, 'get');
        $fail = fn (string $why = 'The passkey could not be verified.') => new ApiException('unauthenticated', $why, 401);
        try {
            $pkc = $this->serializer()->deserialize(json_encode($credential), PublicKeyCredential::class, 'json');
        } catch (\Throwable) {
            throw $fail();
        }
        if (! $pkc->response instanceof AuthenticatorAssertionResponse) {
            throw $fail();
        }
        $passkey = Passkey::where('credential_hash', hash('sha256', $pkc->rawId))->first();
        if (! $passkey || ($state['user_id'] && $state['user_id'] !== $passkey->user_id) || ($expected && $expected->id !== $passkey->user_id)) {
            throw $fail('This passkey is not registered for this account.');
        }
        /** @var PublicKeyCredentialRequestOptions $options */
        $options = $this->serializer()->deserialize($state['options'], PublicKeyCredentialRequestOptions::class, 'json');
        $record = CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($passkey->credential_id), 'public-key', $passkey->transports ?? [], 'none', EmptyTrustPath::create(),
            SymfonyUuid::fromString($passkey->aaguid ?: '00000000-0000-0000-0000-000000000000'), base64_decode($passkey->public_key), $passkey->user_id,
            (int) $passkey->sign_count, null, $passkey->backup_eligible,
        );
        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->steps()->requestCeremony())
                ->check($record, $pkc->response, $options, $this->rpId(), $pkc->response->userHandle ?? $passkey->user_id);
        } catch (\Throwable $e) {
            throw $fail('The passkey could not be verified: '.$e->getMessage());
        }
        $passkey->forceFill(['sign_count' => $record->counter, 'last_used_at' => now()])->save();

        return User::findOrFail($passkey->user_id);
    }

    public static function present(Passkey $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'aaguid' => $p->aaguid, 'transports' => $p->transports ?? [],
            'backup_eligible' => (bool) $p->backup_eligible, 'created_at' => $p->created_at?->toIso8601ZuluString(),
            'last_used_at' => $p->last_used_at?->toIso8601ZuluString(),
        ];
    }
}
