<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki\Okf;

/**
 * Parsed OKF page metadata.
 *
 * Three shapes, matching the contract's page classes
 * (docs/provenance-spec.md):
 * - managed: $provenance set, $error NULL (a valid droost block);
 * - invalid: $provenance NULL, $error set (a droost block that violates the
 *   contract — the page fails staleness, fail-closed);
 * - unmanaged: both NULL (hand-written OKF content; never fails).
 */
final readonly class PageMeta {

  /**
   * Constructs a PageMeta.
   *
   * @param string|null $type
   *   The OKF type key.
   * @param string|null $title
   *   The OKF title key.
   * @param string|null $description
   *   The OKF description key.
   * @param array<int, string> $tags
   *   The OKF tags list.
   * @param string|null $timestamp
   *   The OKF timestamp key (RFC 3339, as written).
   * @param string|null $resource
   *   The OKF resource key.
   * @param \Droost\Engine\Wiki\Okf\Provenance|null $provenance
   *   The validated droost block, when present and valid.
   * @param string|null $error
   *   The contract violation, when a droost block is present but invalid.
   * @param string $body
   *   The markdown body after the frontmatter fence.
   */
  public function __construct(
    public ?string $type,
    public ?string $title,
    public ?string $description,
    public array $tags,
    public ?string $timestamp,
    public ?string $resource,
    public ?Provenance $provenance,
    public ?string $error,
    public string $body,
  ) {}

  /**
   * Whether the page carries a valid droost provenance block.
   *
   * @return bool
   *   TRUE for managed pages.
   */
  public function isManaged(): bool {
    return $this->provenance !== NULL;
  }

  /**
   * Whether the page carries a droost block that violates the contract.
   *
   * @return bool
   *   TRUE for invalid pages.
   */
  public function isInvalid(): bool {
    return $this->error !== NULL;
  }

  /**
   * An unmanaged page with no usable OKF metadata.
   *
   * @param string $body
   *   The page content.
   *
   * @return self
   *   The metadata.
   */
  public static function unmanaged(string $body): self {
    return new self(NULL, NULL, NULL, [], NULL, NULL, NULL, NULL, $body);
  }

  /**
   * An invalid page: a droost block that cannot be honoured.
   *
   * @param string $reason
   *   The contract violation.
   * @param string $body
   *   The page content.
   *
   * @return self
   *   The metadata.
   */
  public static function invalid(string $reason, string $body): self {
    return new self(NULL, NULL, NULL, [], NULL, NULL, NULL, $reason, $body);
  }

}
