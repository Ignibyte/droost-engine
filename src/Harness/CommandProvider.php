<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Lists the slash commands a harness installer materializes.
 *
 * Commands are whole files, copied verbatim: unlike skills they carry no
 * generated frontmatter, so what ships is exactly what was authored. The
 * provider reads a directory of `<name>.md` files; the file's basename is
 * the command's name (`droost-init.md` becomes `/droost-init`).
 *
 * Commands land in `.claude/commands/`, a directory shared with the user's
 * own commands — so installers refresh exactly the names listed here and
 * never touch anything else. Keeping every shipped name on a `droost-`
 * prefix is what makes that safe in practice.
 */
final readonly class CommandProvider {

  /**
   * Constructs a CommandProvider.
   *
   * @param string $commandsDir
   *   Absolute path to the directory of command files.
   */
  public function __construct(
    private string $commandsDir,
  ) {}

  /**
   * Returns every command, keyed by name.
   *
   * Names carry their subdirectory path ('droost/init'), because Claude
   * Code namespaces slash commands by directory — commands/droost/init.md
   * is /droost:init. A flat directory keeps flat names, so consumers that
   * never nest see exactly what they always saw.
   *
   * @return array<string, string>
   *   Command name to its file content. Empty when the directory is missing
   *   — a module shipping no commands is a configuration, not an error.
   */
  public function getCommands(): array {
    if (!is_dir($this->commandsDir)) {
      return [];
    }
    $commands = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(
        $this->commandsDir,
        \FilesystemIterator::SKIP_DOTS,
      ),
    );
    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
      if ($file->getExtension() !== 'md') {
        continue;
      }
      $content = file_get_contents($file->getPathname());
      if ($content === FALSE) {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($this->commandsDir) + 1);
      $commands[substr($relative, 0, -3)] = $content;
    }
    ksort($commands);
    return $commands;
  }

}
