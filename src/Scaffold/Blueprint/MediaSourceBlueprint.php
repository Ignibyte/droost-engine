<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a media source plugin: a new kind of thing the media library holds.
 *
 * A media source defines what a media type STORES (its source field), what it
 * can tell you about that value (metadata attributes), and how it is displayed
 * by default. Core ships File, Image, Video, Audio, oEmbed; a custom one is
 * how a site models "a product code", "a 3D model", or a third-party embed
 * that oEmbed does not cover.
 *
 * The default emitted here is a remote-URL source, because that is the shape
 * whose metadata actually has to be fetched rather than read off a file — the
 * case where getMetadata() earns its existence.
 *
 * Inputs: id (the source plugin id), class (defaults to the id in PascalCase),
 * label, description.
 */
final class MediaSourceBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'media-source';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A MediaSource plugin — a new kind of media the library can hold, with its source field, metadata attributes and default display. --id names the plugin; the class defaults to it in PascalCase.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid plugin id and PHP class name.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $id = $this->machineName($context->input('id', 'example_source'));
    $rawClass = $context->input('class', '');
    $class = $this->className($rawClass !== '' ? $rawClass : $id);
    if ($id === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid plugin id and class from the inputs. Pass --id (a-z, 0-9, _) and optionally --class (a valid PHP class name).');
    }
    $label = $context->input('label', '');
    if ($label === '') {
      $label = ucwords(str_replace('_', ' ', $id));
    }
    $description = $context->input('description', '');
    if ($description === '') {
      $description = sprintf('Media stored as a %s.', $label);
    }
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{id}}' => $id,
      '{{label}}' => $this->phpString($label),
      '{{label_doc}}' => $this->docText($label),
      '{{description}}' => $this->phpString($description),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Plugin/media/Source/' . $class . '.php',
      strtr($this->template(), $tokens),
      $result,
    );
  }

  /**
   * The media source template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function template(): string {
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\{{module}}\Plugin\media\Source;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\FieldConfigInterface;
use Drupal\media\Attribute\MediaSource;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\media\MediaTypeInterface;

/**
 * Provides the {{label_doc}} media source.
 *
 * The allowed_field_types key decides what the source field may be, and it is
 * the one attribute that changes the shape of everything else: "link" or
 * "string" means the value is fetched, "file"/"image" means it is stored. This
 * source
 * takes a URL, which is why getMetadata() below has real work to do.
 *
 * default_thumbnail_filename is NOT optional in practice. Without it, a media
 * item whose thumbnail cannot be derived renders a broken image in the media
 * library rather than a placeholder — and thumbnail derivation is exactly what
 * fails for remote sources on a site with no network.
 *
 * There is deliberately no buildConfigurationForm() override. The parent adds
 * the source-field selector, and a method that only calls parent and returns
 * teaches nothing — add one when this source has settings of its own, and ADD
 * to the parent's form rather than rebuilding it, or the media type becomes
 * unsavable.
 */
#[MediaSource(
  id: '{{id}}',
  label: new TranslatableMarkup('{{label}}'),
  description: new TranslatableMarkup('{{description}}'),
  allowed_field_types: ['link', 'string'],
  default_thumbnail_filename: 'generic.png',
)]
final class {{class}} extends MediaSourceBase {

  /**
   * Metadata attribute: the human-readable title of the referenced thing.
   */
  private const string ATTRIBUTE_TITLE = 'title';

  /**
   * Metadata attribute: the canonical URL.
   */
  private const string ATTRIBUTE_URL = 'url';

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The metadata attributes this source can provide, keyed by name.
   */
  public function getMetadataAttributes(): array {
    // These names are what a site maps to real fields on the media type form
    // ("store the title in field_title"). Renaming one later silently breaks
    // those mappings, so treat them as an API.
    return [
      self::ATTRIBUTE_TITLE => $this->t('Title'),
      self::ATTRIBUTE_URL => $this->t('URL'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name): mixed {
    $value = $this->getSourceFieldValue($media);
    if (!is_string($value) || $value === '') {
      // NULL means "unknown", which is what the caller expects for an
      // unpopulated source. Returning '' would store an empty title as if it
      // had been resolved.
      return NULL;
    }

    return match ($attribute_name) {
      self::ATTRIBUTE_URL => $value,
      self::ATTRIBUTE_TITLE => $this->deriveTitle($value),
      // The default thumbnail and name attributes are handled by the parent;
      // delegating rather than returning NULL is what keeps the media library
      // showing a placeholder instead of a broken image.
      default => parent::getMetadata($media, $attribute_name),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function createSourceField(MediaTypeInterface $type): FieldConfigInterface {
    // Give the generated field a label that says what to paste in. The
    // default is the plugin label, which reads as a type name rather than an
    // instruction.
    return parent::createSourceField($type)->set('label', $this->t('URL'));
  }

  /**
   * Derives a display title from a URL.
   *
   * @param string $url
   *   The source value.
   *
   * @return string|null
   *   A human-readable title, or NULL when none can be derived.
   */
  private function deriveTitle(string $url): ?string {
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || trim($path, '/') === '') {
      // A bare host with no path has no title in it. Say so rather than
      // inventing one from the domain.
      return NULL;
    }
    $slug = basename(trim($path, '/'));
    $slug = preg_replace('/\.[a-z0-9]{1,5}$/i', '', $slug) ?? $slug;
    return ucwords(str_replace(['-', '_'], ' ', $slug));
  }

}
PHP;
  }

}
