<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Installs Droost into opencode: `opencode.json` MCP + AGENTS.md instruction.
 */
final class OpencodeHarnessInstaller extends AbstractHarnessInstaller {

  /**
   * The AGENTS.md pointer added to opencode's instructions array.
   */
  private const string AGENTS_POINTER = './AGENTS.md';

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'opencode';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'opencode';
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(string $root): bool {
    return is_file($root . '/opencode.json') || is_file($root . '/opencode.jsonc');
  }

  /**
   * {@inheritdoc}
   */
  public function install(string $root, InstallContext $context, InstallResult $result): void {
    // opencode.jsonc allows comments, which json_decode would strip on a
    // round-trip; if that is the only config present, don't clobber it with a
    // second opencode.json — tell the user to edit it by hand.
    if (!is_file($root . '/opencode.json') && is_file($root . '/opencode.jsonc')) {
      $result->addWarning('opencode.jsonc found; add the droost MCP server to it manually (JSONC cannot be safely rewritten).');
      return;
    }
    if (JsonMerge::isMalformed($root . '/opencode.json')) {
      $result->addWarning('opencode.json is present but not valid JSON; left unchanged to avoid destroying its contents. Fix it and re-run.');
      return;
    }
    $data = JsonMerge::read($root . '/opencode.json');
    $data = JsonMerge::setPath($data, 'mcp', 'droost', [
      'type' => 'local',
      'command' => [$context->command, ...$context->args],
      'enabled' => TRUE,
    ]);
    if ($context->writesGuidelines()) {
      $instructions = (isset($data['instructions']) && is_array($data['instructions'])) ? $data['instructions'] : [];
      if (!in_array(self::AGENTS_POINTER, $instructions, TRUE)) {
        $instructions[] = self::AGENTS_POINTER;
      }
      $data['instructions'] = array_values($instructions);
    }
    $this->write($root, 'opencode.json', JsonMerge::encode($data));
    $result->addWritten('opencode.json');
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(string $root, InstallResult $result): void {
    $path = $root . '/opencode.json';
    if (!is_file($path)) {
      return;
    }
    // A present-but-unparseable file decodes to [] via read(); without this
    // guard the "empty after removal" branch below would delete() it, taking
    // every server/secret it holds. Mirror install(): leave it untouched.
    if (JsonMerge::isMalformed($path)) {
      $result->addWarning('opencode.json is present but not valid JSON; left unchanged. Remove the droost MCP server from it manually.');
      return;
    }
    $data = JsonMerge::read($path);
    $data = JsonMerge::unsetPath($data, 'mcp', 'droost');
    if (isset($data['instructions']) && is_array($data['instructions'])) {
      $data['instructions'] = array_values(array_filter(
        $data['instructions'],
        static fn(mixed $i): bool => $i !== self::AGENTS_POINTER,
      ));
      if ($data['instructions'] === []) {
        unset($data['instructions']);
      }
    }
    if ($data === []) {
      $this->delete($root, 'opencode.json');
      $result->addRemoved('opencode.json (file)');
      return;
    }
    $this->write($root, 'opencode.json', JsonMerge::encode($data));
    $result->addRemoved('opencode.json (server)');
  }

}
