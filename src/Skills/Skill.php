<?php

declare(strict_types=1);

namespace Droost\Engine\Skills;

/**
 * A renderable skill: a name, an auto-trigger description, and a body.
 */
final readonly class Skill {

  /**
   * Constructs a Skill.
   *
   * @param string $name
   *   The machine name (also the skill directory/file name).
   * @param string $description
   *   The trigger description a harness uses to decide when to load the skill.
   * @param string $body
   *   The skill body (Markdown, without frontmatter).
   */
  public function __construct(
    public string $name,
    public string $description,
    public string $body,
  ) {}

}
