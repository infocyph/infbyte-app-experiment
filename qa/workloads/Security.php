<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\OTP\TOTP;

final readonly class Security
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public function setup(): void
    {
        new Accounts($this->app)->setup();
    }

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $auth = $this->app->make(AuthServices::class);
        $owner = $auth->accounts()->create('owner-' . $id . '@example.test', metadata: ['tenant' => 'alpha'])->account;
        $other = $auth->accounts()->create('other-' . $id . '@example.test', metadata: ['tenant' => 'beta'])->account;
        ensure($owner instanceof Account && $other instanceof Account, 'Missing persisted accounts.');
        if (getenv('SCENARIO') === 'passkey') {
            $this->assertPasskey($owner->id());

            return;
        }
        if (getenv('SCENARIO') === 'authorization') {
            $this->assertAuthorization($owner, $other, $id);

            return;
        }
        $otp = $this->app->make(OtpManager::class);
        $enrollment = $otp->beginEnrollment($owner->id(), 'owner-' . $id, false, 8);
        $factor = $enrollment->factor();
        ensure($enrollment->successful() && $factor !== null && count($enrollment->recoveryCodes()) === 8, 'OTP enrollment failed.');
        $code = new TOTP($enrollment->payload->secret, 6, 30, 'sha256')->generate();
        $confirmed = $otp->completeEnrollment($owner->id(), $factor->id, $code);
        ensure($confirmed->successful() && $confirmed->factor?->enabled === true, 'TOTP confirmation failed.');
        ensure(!$otp->verifyFactor($factor, $code)->verified, 'TOTP replay accepted.');
    }

    /** @throws \Throwable */
    private function assertAuthorization(Account $owner, Account $other, string $id): void
    {
        $auth = $this->app->make(AuthServices::class);
        $principal = new Principal($owner->id(), accountId: $owner->id(), metadata: ['tenant' => 'alpha']);
        $foreign = new Principal($other->id(), accountId: $other->id(), metadata: ['tenant' => 'beta']);
        $permission = $auth->permissions()->create('receipt.read.' . $id);
        $role = $auth->roles()->create('reader-' . $id);
        $auth->permissions()->assignToRole($role->id, $permission->id);
        $auth->roles()->assign($owner->id(), $role->id);
        ensure($auth->authorizer()->can($principal, 'receipt.read.' . $id)->allowed, 'Role permission denied.');
        ensure(!$auth->authorizer()->can($foreign, 'receipt.read.' . $id)->allowed, 'Foreign tenant permission accepted.');
        $grant = $auth->delegation()->grant($principal->id(), 'receipt.approve', resourceType: 'receipt', resourceId: $id);
        ensure($grant->grant !== null, 'Grant creation failed.');
        $resource = ['type' => 'receipt', 'id' => $id];
        ensure($auth->authorizer()->can($principal, 'receipt.approve', $resource)->allowed, 'Scoped grant denied.');
        ensure(!$auth->authorizer()->can($foreign, 'receipt.approve', $resource)->allowed, 'Foreign grant accepted.');
        $auth->delegation()->revoke($grant->grant->id, $principal->id());
        ensure(!$auth->authorizer()->can($principal, 'receipt.approve', $resource)->allowed, 'Revoked grant accepted.');
        $auth->roles()->revoke($owner->id(), $role->id);
        ensure(!$auth->authorizer()->can($principal, 'receipt.read.' . $id)->allowed, 'Revoked role accepted.');
    }

    /** @throws \Throwable */
    private function assertPasskey(string $owner): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        ensure($key instanceof \OpenSSLAsymmetricKey, 'Disposable authenticator creation failed.');
        $details = openssl_pkey_get_details($key);
        ensure(is_array($details) && is_string($details['ec']['x'] ?? null) && is_string($details['ec']['y'] ?? null), 'Missing P-256 coordinates.');
        $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . "\x22\x58\x20" . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $raw = random_bytes(32);
        $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $credential = $encode($raw);
        $user = hash('sha3-256', "foundation:passkey:user:v1\0" . $owner, true);
        $record = \Webauthn\CredentialRecord::create($raw, 'public-key', [], 'none', \Webauthn\TrustPath\EmptyTrustPath::create(),
            \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'), $cose, $user, 0);
        $serializer = new \Webauthn\Denormalizer\WebauthnSerializerFactory(\Webauthn\AttestationStatement\AttestationStatementSupportManager::create())->create();
        $store = $this->app->make(PasskeyCredentialStoreInterface::class);
        $store->save(new PasskeyCredential($credential, $owner, $credential, $serializer->serialize($record, 'json'), '', 0, [], time()));
        $service = $this->app->make(PasskeyServiceInterface::class);
        $challenge = $service->startAuthentication($owner);
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge->challenge, 'origin' => 'https://experiment.test'], JSON_THROW_ON_ERROR);
        $data = hash('sha256', 'experiment.test', true) . "\x05" . pack('N', 1);
        $signature = '';
        ensure(openssl_sign($data . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256), 'Assertion signing failed.');
        $payload = json_encode(['id' => $credential, 'rawId' => $credential, 'type' => 'public-key', 'response' => [
            'authenticatorData' => $encode($data), 'clientDataJSON' => $encode($client), 'signature' => $encode($signature), 'userHandle' => $encode($user),
        ]], JSON_THROW_ON_ERROR);
        $result = new PasskeyAuthenticationResult($challenge->id, $credential, '', '', '', metadata: ['credential_json' => $payload]);
        $manager = $this->app->make(AuthServices::class)->passkeys();
        ensure($manager->finishAuthentication($result)->successful(), 'Signed native assertion rejected.');
        ensure($store->findByCredentialId($credential)?->revision === 1, 'Credential counter transition missing.');
        ensure(!$manager->finishAuthentication($result)->successful(), 'WebAuthn challenge replay accepted.');
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        new Accounts($this->app)->verify($expected * 2);
    }
}
