<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Owns AGENTS.md — the single source of truth all other harness files point at.
 *
 * Writes the full guidelines block (the brain directive + version-stamped
 * conventions + topic list). Always runs, before the pointer writers.
 */
final class AgentsHarnessInstaller extends AbstractHarnessInstaller {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'agents';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'AGENTS.md (shared)';
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(string $root): bool {
    // AGENTS.md is the universal source of truth; always applicable.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function install(string $root, InstallContext $context, InstallResult $result): void {
    if (!$context->writesGuidelines()) {
      return;
    }
    $this->upsertMarkdown($root, 'AGENTS.md', $context->blockBody, $result);
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(string $root, InstallResult $result): void {
    $this->removeMarkdown($root, 'AGENTS.md', $result);
  }

}
