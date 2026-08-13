<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Support;

use Droost\Engine\Support\SecretRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the best-effort secret redactor shared by config/entity reads.
 */
#[CoversClass(SecretRedactor::class)]
final class SecretRedactorTest extends TestCase {

  /**
   * Key names that must be treated as sensitive.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function sensitiveProvider(): array {
    return [
      'password word' => ['password'],
      'user pass segment' => ['pass'],
      'user_pass compound' => ['user_pass'],
      'secret segment' => ['client_secret'],
      'token segment' => ['access_token'],
      'credentials' => ['credentials'],
      'hmac segment' => ['hmac_key'],
      'salt segment' => ['hash_salt'],
      'private_key via key' => ['private_key'],
      'api_key via key' => ['api_key'],
      'apikey single segment' => ['apikey'],
      'access_key via key' => ['access_key'],
      'passphrase' => ['passphrase'],
      'oauth segment' => ['oauth'],
      'bearer segment' => ['bearer_token'],
      'dsn segment' => ['database_dsn'],
      'cert segment' => ['ssl_cert'],
      'certificate word' => ['certificate'],
      'exact key' => ['key'],
      'underscore key suffix' => ['consumer_key'],
      'concatenated secretkey' => ['secretkey'],
      'concatenated accesstoken' => ['accesstoken'],
      // Plural forms (sweep-2 finding #2): a key like "api_keys" splits to
      // ["api", "keys"], so the plural segment must be a needle too.
      'plural passwords' => ['passwords'],
      'plural secrets' => ['secrets'],
      'plural keys exact' => ['keys'],
      'api_keys via keys' => ['api_keys'],
      'private_keys via keys' => ['private_keys'],
      'access_keys via keys' => ['access_keys'],
      'plural salts' => ['salts'],
      'concatenated accesstokens' => ['accesstokens'],
    ];
  }

  /**
   * Key names that must NOT be treated as sensitive.
   *
   * Includes the regression guards for round-2 finding M2: segment-boundary
   * matching must not flag common words that merely contain a needle substring.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function benignProvider(): array {
    return [
      'label' => ['label'],
      'title' => ['title'],
      'keyboard' => ['keyboard_layout'],
      'monkey is not key' => ['monkey'],
      'uuid' => ['uuid'],
      'status' => ['status'],
      'author is not auth' => ['author'],
      'authority is not auth' => ['authority'],
      'certain is not cert' => ['certain'],
      'concert is not cert' => ['concert'],
      'compass is not pass' => ['compass'],
      'passenger is not pass' => ['passenger'],
      'connection is not a secret' => ['connection_string'],
    ];
  }

  /**
   * Sensitive key names are detected.
   */
  #[DataProvider('sensitiveProvider')]
  public function testIsSensitiveTrue(string $key): void {
    $this->assertTrue(SecretRedactor::isSensitive($key), "$key is sensitive");
    // Case-insensitive.
    $this->assertTrue(SecretRedactor::isSensitive(strtoupper($key)), "$key is sensitive (uppercase)");
  }

  /**
   * Benign key names are not flagged.
   */
  #[DataProvider('benignProvider')]
  public function testIsSensitiveFalse(string $key): void {
    $this->assertFalse(SecretRedactor::isSensitive($key), "$key is not sensitive");
  }

  /**
   * Keys in camelCase are segmented on case transitions.
   *
   * Not part of the case-insensitive matrix because upper-casing a camelCase
   * key (clientSecret → CLIENTSECRET) erases the segment boundary; Drupal keys
   * are snake_case in practice, so this is a robustness extra.
   */
  public function testCamelCaseSegmentation(): void {
    $this->assertTrue(SecretRedactor::isSensitive('clientSecret'));
    $this->assertTrue(SecretRedactor::isSensitive('apiKey'));
    $this->assertFalse(SecretRedactor::isSensitive('authorName'));
  }

  /**
   * Redaction replaces sensitive values, recurses, and preserves benign data.
   */
  public function testRedactReplacesAndRecurses(): void {
    // Sensitive values are generated, not hard-coded, so they are not mistaken
    // for real credentials — the test only cares that they get redacted.
    $data = [
      'site_name' => 'My site',
      'password' => self::randomValue(),
      'nested' => [
        'api_key' => self::randomValue(),
        'label' => 'keep me',
        'deeper' => ['client_secret' => self::randomValue()],
      ],
      'count' => 5,
    ];
    $out = SecretRedactor::redact($data);

    $this->assertSame('My site', $out['site_name']);
    $this->assertSame(SecretRedactor::MARKER, $out['password']);
    $nested = $out['nested'];
    $this->assertIsArray($nested);
    $this->assertSame(SecretRedactor::MARKER, $nested['api_key']);
    $this->assertSame('keep me', $nested['label']);
    $deeper = $nested['deeper'];
    $this->assertIsArray($deeper);
    $this->assertSame(SecretRedactor::MARKER, $deeper['client_secret']);
    $this->assertSame(5, $out['count']);
  }

  /**
   * A throwaway value standing in for a credential.
   *
   * Generated rather than written out so no line in this file reads as a real
   * secret; the assertions only care that the value is replaced. Replaces the
   * randomMachineName() helper this test used while it extended Drupal's
   * UnitTestCase.
   *
   * @return string
   *   A random hex string.
   */
  private static function randomValue(): string {
    return bin2hex(random_bytes(8));
  }

}
