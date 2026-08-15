<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Installs Droost into gemini-cli (settings.json + a GEMINI.md pointer).
 */
final class GeminiHarnessInstaller extends AbstractHarnessInstaller {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'gemini';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'gemini-cli';
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(string $root): bool {
    return is_dir($root . '/.gemini') || is_file($root . '/GEMINI.md');
  }

  /**
   * {@inheritdoc}
   */
  public function install(string $root, InstallContext $context, InstallResult $result): void {
    $this->upsertJsonServer($root, '.gemini/settings.json', 'mcpServers', $context, $result);
    if ($context->writesGuidelines()) {
      $this->upsertMarkdown($root, 'GEMINI.md', $this->sentencePointer(), $result);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(string $root, InstallResult $result): void {
    $this->removeJsonServer($root, '.gemini/settings.json', 'mcpServers', $result);
    $this->removeMarkdown($root, 'GEMINI.md', $result);
    $this->removeDirIfEmpty($root, '.gemini');
  }

}
