<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Installs Droost into qwen-code: `.qwen/settings.json` + a QWEN.md pointer.
 */
final class QwenHarnessInstaller extends AbstractHarnessInstaller {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'qwen';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'qwen-code';
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(string $root): bool {
    return is_dir($root . '/.qwen') || is_file($root . '/QWEN.md');
  }

  /**
   * {@inheritdoc}
   */
  public function install(string $root, InstallContext $context, InstallResult $result): void {
    $this->upsertJsonServer($root, '.qwen/settings.json', 'mcpServers', $context, $result);
    if ($context->writesGuidelines()) {
      $this->upsertMarkdown($root, 'QWEN.md', $this->sentencePointer(), $result);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(string $root, InstallResult $result): void {
    $this->removeJsonServer($root, '.qwen/settings.json', 'mcpServers', $result);
    $this->removeMarkdown($root, 'QWEN.md', $result);
    $this->removeDirIfEmpty($root, '.qwen');
  }

}
