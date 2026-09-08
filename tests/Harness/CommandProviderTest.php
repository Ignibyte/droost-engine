<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Harness;

use Droost\Engine\Harness\CommandProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the slash-command provider.
 *
 * Commands are copied verbatim and keyed by basename; a missing directory is
 * a consumer that ships none, not an error.
 */
#[CoversClass(CommandProvider::class)]
final class CommandProviderTest extends TestCase {

  /**
   * Commands come back verbatim, keyed by name, sorted, md-only.
   */
  public function testReadsCommandsVerbatimByName(): void {
    $dir = sys_get_temp_dir() . '/droost-cmd-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, TRUE);
    file_put_contents($dir . '/droost-init.md', "---\ntitle: Init\n---\nBody.\n");
    file_put_contents($dir . '/droost-upgrade.md', "Upgrade.\n");
    file_put_contents($dir . '/notes.txt', 'not a command');

    $commands = (new CommandProvider($dir))->getCommands();

    $this->assertSame(['droost-init', 'droost-upgrade'], array_keys($commands));
    $this->assertSame("---\ntitle: Init\n---\nBody.\n", $commands['droost-init']);

    unlink($dir . '/droost-init.md');
    unlink($dir . '/droost-upgrade.md');
    unlink($dir . '/notes.txt');
    rmdir($dir);
  }

  /**
   * Nested files become namespaced names: directories ARE the namespace.
   *
   * Claude Code serves commands/droost/init.md as /droost:init. A flat
   * directory keeps flat names, so nothing changes for consumers that never
   * nest.
   */
  public function testNestedCommandsCarryTheirPathAsTheName(): void {
    $dir = sys_get_temp_dir() . '/droost-cmd-' . bin2hex(random_bytes(6));
    mkdir($dir . '/droost/workflow', 0755, TRUE);
    file_put_contents($dir . '/droost/init.md', "Init.\n");
    file_put_contents($dir . '/droost/workflow/continue.md', "Continue.\n");
    file_put_contents($dir . '/plain.md', "Plain.\n");

    $commands = (new CommandProvider($dir))->getCommands();

    $this->assertSame(
      ['droost/init', 'droost/workflow/continue', 'plain'],
      array_keys($commands),
    );
    $this->assertSame("Continue.\n", $commands['droost/workflow/continue']);

    unlink($dir . '/droost/init.md');
    unlink($dir . '/droost/workflow/continue.md');
    unlink($dir . '/plain.md');
    rmdir($dir . '/droost/workflow');
    rmdir($dir . '/droost');
    rmdir($dir);
  }

  /**
   * A missing directory yields no commands and no error.
   */
  public function testMissingDirectoryShipsNothing(): void {
    $provider = new CommandProvider(sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(6)));
    $this->assertSame([], $provider->getCommands());
  }

}
