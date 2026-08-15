<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Shared file I/O and managed-region helpers for harness installers.
 */
abstract class AbstractHarnessInstaller implements HarnessInstallerInterface {

  /**
   * Reads a project-relative file, or '' if absent.
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The relative path.
   *
   * @return string
   *   The file content, or ''.
   */
  protected function read(string $root, string $relative): string {
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
  }

  /**
   * Writes a project-relative file, creating parent directories.
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The relative path.
   * @param string $content
   *   The content to write.
   */
  protected function write(string $root, string $relative, string $content): void {
    $path = $root . '/' . $relative;
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException(sprintf('Could not create directory: %s', $dir));
    }
    if (file_put_contents($path, $content) === FALSE) {
      throw new \RuntimeException(sprintf('Could not write file: %s', $path));
    }
  }

  /**
   * Deletes a project-relative file if it exists.
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The relative path.
   */
  protected function delete(string $root, string $relative): void {
    $path = $root . '/' . $relative;
    if (is_file($path)) {
      unlink($path);
    }
  }

  /**
   * Removes a project-relative directory only if it is empty (not a symlink).
   *
   * Used on uninstall to clean up a config dir Droost created (.codex/.qwen/
   * .gemini) once its only file is gone, so a stale empty dir doesn't keep
   * re-triggering auto-detection. Never removes a dir that still holds user
   * files.
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The relative directory path.
   */
  protected function removeDirIfEmpty(string $root, string $relative): void {
    $path = $root . '/' . $relative;
    if (!is_dir($path) || is_link($path)) {
      return;
    }
    $entries = @scandir($path);
    if (is_array($entries) && array_diff($entries, ['.', '..']) === []) {
      @rmdir($path);
    }
  }

  /**
   * Deletes a project-relative directory and its files (one level + nested).
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The relative directory path.
   */
  protected function deleteDir(string $root, string $relative): void {
    $path = $root . '/' . $relative;
    // A symlinked directory: remove the link, never recurse into its target
    // (which could be user data outside our tree).
    if (is_link($path)) {
      unlink($path);
      return;
    }
    if (!is_dir($path)) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
      if ($item instanceof \SplFileInfo) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
      }
    }
    rmdir($path);
  }

  /**
   * Upserts a `{command, args}` MCP server entry under a JSON key path.
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The JSON config relative path.
   * @param string $parent
   *   The parent key (e.g. "mcpServers").
   * @param \Droost\Engine\Harness\InstallContext $context
   *   The install context (carries command + args).
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator.
   */
  protected function upsertJsonServer(string $root, string $relative, string $parent, InstallContext $context, InstallResult $result): void {
    if (JsonMerge::isMalformed($root . '/' . $relative)) {
      $result->addWarning(sprintf('%s is present but not valid JSON; left unchanged to avoid destroying its contents. Fix it and re-run.', $relative));
      return;
    }
    $data = JsonMerge::read($root . '/' . $relative);
    $data = JsonMerge::setPath($data, $parent, 'droost', [
      'command' => $context->command,
      'args' => $context->args,
    ]);
    $this->write($root, $relative, JsonMerge::encode($data));
    $result->addWritten($relative);
  }

  /**
   * Removes the droost MCP server entry from a JSON key path.
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The JSON config relative path.
   * @param string $parent
   *   The parent key.
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator.
   */
  protected function removeJsonServer(string $root, string $relative, string $parent, InstallResult $result): void {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
      return;
    }
    $data = JsonMerge::read($path);
    if (!isset($data[$parent]) || !is_array($data[$parent]) || !array_key_exists('droost', $data[$parent])) {
      return;
    }
    $data = JsonMerge::unsetPath($data, $parent, 'droost');
    if ($data === []) {
      $this->delete($root, $relative);
      $result->addRemoved($relative . ' (file)');
      return;
    }
    $this->write($root, $relative, JsonMerge::encode($data));
    $result->addRemoved($relative . ' (server)');
  }

  /**
   * The Claude-style import pointer body referencing AGENTS.md.
   *
   * @return string
   *   The pointer body.
   */
  protected function importPointer(): string {
    return "Droost guidelines are maintained in AGENTS.md (imported below).\n\n@AGENTS.md";
  }

  /**
   * The sentence pointer body for harnesses without an import syntax.
   *
   * @return string
   *   The pointer body.
   */
  protected function sentencePointer(): string {
    return 'Follow the Droost project guidelines in `./AGENTS.md`.';
  }

  /**
   * Upserts a markdown managed region into a file (creating it if needed).
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The relative path.
   * @param string $body
   *   The region body (without markers).
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator.
   */
  protected function upsertMarkdown(string $root, string $relative, string $body, InstallResult $result): void {
    $existing = $this->read($root, $relative);
    try {
      $new = ManagedRegion::upsert($existing, Markers::MD_BEGIN, Markers::MD_END, $body);
    }
    catch (\RuntimeException) {
      $result->addWarning(sprintf('%s has a malformed Droost region (mismatched markers); left unchanged. Fix the markers and re-run.', $relative));
      return;
    }
    $this->write($root, $relative, $new);
    $result->addWritten($relative);
  }

  /**
   * Removes a markdown managed region, deleting the file if only ours remains.
   *
   * @param string $root
   *   The project root.
   * @param string $relative
   *   The relative path.
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator.
   */
  protected function removeMarkdown(string $root, string $relative, InstallResult $result): void {
    $existing = $this->read($root, $relative);
    if (!ManagedRegion::isWellFormed($existing, Markers::MD_BEGIN, Markers::MD_END)) {
      return;
    }
    if (ManagedRegion::isOnlyRegion($existing, Markers::MD_BEGIN, Markers::MD_END)) {
      $this->delete($root, $relative);
      $result->addRemoved($relative . ' (file)');
      return;
    }
    $this->write($root, $relative, ManagedRegion::strip($existing, Markers::MD_BEGIN, Markers::MD_END));
    $result->addRemoved($relative . ' (region)');
  }

}
