<?php

declare(strict_types=1);

namespace Droost\Engine\Support;

/**
 * Best-effort redaction of secret-looking values by key name.
 *
 * Shared by droost_config_get and droost_entity_load. Redaction is a key-name
 * heuristic, not a content classifier: it cannot catch a secret stored under an
 * innocuous key, and it is defense-in-depth rather than a guarantee. Treat it
 * as a guard that removes obvious credentials, not a promise that none leak.
 */
final class SecretRedactor {

  /**
   * The marker substituted for a redacted value.
   */
  public const string MARKER = '«redacted»';

  /**
   * Name segments that mark a value as sensitive.
   *
   * Matched as whole segments, not substrings. A key is split into segments on
   * non-alphanumeric boundaries and camelCase
   * transitions, and is sensitive if any segment equals one of these. Matching
   * whole segments — rather than substrings — avoids false positives like
   * "author" (auth), "certain"/"concert" (cert), or "compass" (pass), which
   * would otherwise be silently redacted and corrupt introspection output.
   * Compound credentials (api_key, client_secret, private_key, …) are caught by
   * their generic segment (key, secret). Plural segments (keys, secrets,
   * passwords) are enumerated alongside their singulars: matching is exact, and
   * a key like "api_keys" splits to ["api", "keys"], so the plural must match.
   */
  private const array NEEDLES = [
    'pass', 'password', 'passwords', 'passwd', 'pwd', 'pw', 'passphrase',
    'passphrases',
    'secret', 'secrets', 'token', 'tokens', 'credential', 'credentials',
    'hmac', 'salt', 'salts', 'key', 'keys', 'apikey', 'apikeys',
    'dsn', 'bearer', 'oauth', 'cert', 'certs', 'certificate', 'certificates',
    'authorization', 'jwt',
    // A webhook URL is itself the credential (Slack/Teams incoming webhooks);
    // no benign whole-segment collisions for these.
    'webhook', 'webhooks',
    // High-confidence unsplittable concatenations (no benign-word collisions),
    // since whole-segment matching alone misses keys written without a
    // separator, e.g. "secretkey", "accesstoken". Plurals are enumerated for
    // the same reason a plural segment ("api_keys" -> keys) must match.
    'secretkey', 'secretkeys', 'privatekey', 'privatekeys',
    'accesstoken', 'accesstokens', 'apitoken', 'apitokens',
    'accesskey', 'accesskeys',
  ];

  /**
   * Sensitive base fields to redact per entity type.
   *
   * Applied in addition to the key-name heuristic: plenty of sensitive base
   * fields (notably user email) have innocuous names the NEEDLES cannot catch.
   * Listed per entity type rather than widened into NEEDLES globally, which
   * would over-redact same-named config keys (e.g. [site:name]). Shared by
   * droost_entity_load and droost_tokens so the two agree on what is masked.
   *
   * @var array<string, array<int, string>>
   */
  public const array SENSITIVE_BASE_FIELDS = [
    'user' => ['mail', 'init'],
    'comment' => ['mail', 'hostname'],
    'contact_message' => ['mail', 'name', 'message'],
  ];

  /**
   * Whether a field/property of an entity type should be redacted.
   *
   * Combines the key-name heuristic (isSensitive) with the per-entity-type
   * base-field list, so callers do not each re-implement the policy.
   *
   * @param string $entityTypeId
   *   The entity type id (e.g. "user").
   * @param string $fieldName
   *   The field or property name (e.g. "mail").
   *
   * @return bool
   *   TRUE if the value should be redacted.
   */
  public static function isSensitiveField(string $entityTypeId, string $fieldName): bool {
    return self::isSensitive($fieldName)
      || in_array($fieldName, self::SENSITIVE_BASE_FIELDS[$entityTypeId] ?? [], TRUE);
  }

  /**
   * Recursively redacts sensitive values from an array.
   *
   * @param array<mixed, mixed> $data
   *   The data to redact.
   *
   * @return array<string, mixed>
   *   The data with sensitive values replaced by the redaction marker.
   */
  public static function redact(array $data): array {
    $out = [];
    foreach ($data as $key => $value) {
      $key = (string) $key;
      if (self::isSensitive($key)) {
        $out[$key] = self::MARKER;
      }
      elseif (is_array($value)) {
        $out[$key] = self::redact($value);
      }
      else {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * Decides whether a key name denotes a secret.
   *
   * The key is split into lowercase segments on non-alphanumeric boundaries and
   * camelCase transitions; it is sensitive if any segment is a known
   * secret-bearing token. This catches `pass`, `user_pass`, `api_key`,
   * `clientSecret`, etc. without flagging `author`, `certain`, or `compass`.
   *
   * @param string $key
   *   The key (config key or entity field name).
   *
   * @return bool
   *   TRUE if the value should be redacted.
   */
  public static function isSensitive(string $key): bool {
    $delimited = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
    $segments = preg_split('/[^a-zA-Z0-9]+/', $delimited, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($segments as $segment) {
      if (in_array(strtolower($segment), self::NEEDLES, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
