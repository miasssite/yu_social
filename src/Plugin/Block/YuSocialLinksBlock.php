<?php

declare(strict_types=1);

namespace Drupal\yu_social\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Social links block.
 *
 * The list of links (label, URL, icon, nofollow, weight) is maintained in
 * the module settings at /admin/config/system/yu-social. This block instance
 * only overrides per-link visibility ("enabled"), the display mode and the
 * extra CSS classes. When a class field of this instance is empty, the
 * matching default class from the module settings is used instead.
 */
#[Block(
  id: 'yu_social_links_block',
  admin_label: new TranslatableMarkup('Social links'),
  category: new TranslatableMarkup('Custom'),
)]
final class YuSocialLinksBlock extends BlockBase implements ContainerFactoryPluginInterface {

  private const DISPLAY_MODES = ['icon_label', 'icon_only', 'label_only'];

  /**
   * Keys of the CSS class overrides (identical to the module settings keys).
   */
  private const CLASS_KEYS = ['wrapper_classes', 'link_classes', 'icon_classes', 'label_classes'];

  /**
   * Default values of a per-link override.
   */
  private const NEW_OVERRIDE = [
    'enabled' => TRUE,
    'display' => 'icon_label',
    'wrapper_classes' => '',
    'link_classes' => '',
    'icon_classes' => '',
    'label_classes' => '',
  ];

  /**
   * Constructs a YuSocialLinksBlock instance.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'orientation' => 'horizontal',
      'overrides' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme' => 'yu_social_links_block',
      '#links' => $this->getLinks(),
      '#orientation' => $this->configuration['orientation'] ?? 'horizontal',
      // FontAwesome assets are loaded site-wide by the fontawesome module
      // itself (see fontawesome_page_attachments()), so only our own CSS
      // is attached here.
      '#attached' => [
        'library' => [
          'yu_social/yu_social',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $cache = CacheableMetadata::createFromObject($this->configFactory->get('yu_social.settings'));

    // The rendered links may reference SVG files; their cache tags must be
    // included so that the block is invalidated when a file changes.
    foreach ($this->configFactory->get('yu_social.settings')->get('links') ?? [] as $link) {
      if (!empty($link['svg']) && ($file = $this->loadSvgFile((int) $link['svg']))) {
        $cache->addCacheableDependency($file);
      }
    }
    return $cache->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['orientation'] = [
      '#type' => 'select',
      '#title' => $this->t('Расположение элементов'),
      '#options' => [
        'horizontal' => $this->t('Горизонтально'),
        'vertical' => $this->t('Вертикально'),
      ],
      '#default_value' => in_array($this->configuration['orientation'] ?? 'horizontal', ['horizontal', 'vertical'], TRUE)
        ? ($this->configuration['orientation'] ?? 'horizontal') : 'horizontal',
      '#description' => $this->t('Расположение ссылок внутри блока.'),
    ];

    $global_links = $this->getGlobalLinks();
    // Overrides are kept in form state so that they survive the form
    // rebuild; they are matched to the global list by index.
    $overrides = $form_state->get('yu_social_overrides');
    if ($overrides === NULL) {
      $overrides = $this->configuration['overrides'] ?? [];
      $form_state->set('yu_social_overrides', $overrides);
    }

    $form['links_area'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['social-links-rows']],
    ];

    foreach ($global_links as $i => $link) {
      $form['links_area']['links'][$i] = $this->buildLinkRow($i, $link, $overrides[$i] ?? []);
    }

    return $form;
  }

  /**
   * Builds a single link override row of the block instance form.
   *
   * Label, URL, icon, nofollow and weight are edited in the module settings
   * only; this row holds the per-instance overrides. An empty class field
   * means "use the default class from the module settings".
   */
  private function buildLinkRow(int $i, array $link, array $override): array {
    $title = ($link['label'] ?? '') !== '' ? $link['label'] : $this->t('Новая ссылка');

    $row = [
      '#type' => 'details',
      '#title' => $this->t('Ссылка #%number: @label', [
        '%number' => $i + 1,
        '@label' => $title,
      ]),
      // Collapsed by default, except the first row.
      '#open' => $i === 0,
      '#attributes' => ['class' => ['social-links-row']],
      '#summary_attributes' => ['class' => ['social-links-row__summary']],
    ];

    $row['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Включен'),
      '#default_value' => (bool) ($override['enabled'] ?? $link['enabled'] ?? TRUE),
    ];

    $row['display'] = [
      '#type' => 'select',
      '#title' => $this->t('Отображать'),
      '#options' => [
        'icon_label' => $this->t('Иконку и метку'),
        'icon_only' => $this->t('Только иконку'),
        'label_only' => $this->t('Только метку'),
      ],
      '#default_value' => in_array($override['display'] ?? 'icon_label', self::DISPLAY_MODES, TRUE)
        ? ($override['display'] ?? 'icon_label') : 'icon_label',
    ];

    $row['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы обертки'),
      '#default_value' => $override['wrapper_classes'] ?? '',
      '#description' => $this->t('Дополнительные классы элемента-обертки li. Если оставить пустым, будут использованы классы по умолчанию из настроек модуля.'),
    ];

    $row['link_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы ссылки'),
      '#default_value' => $override['link_classes'] ?? '',
      '#description' => $this->t('Дополнительные классы ссылки a. Если оставить пустым, будут использованы классы по умолчанию из настроек модуля.'),
    ];

    $row['icon_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы иконки'),
      '#default_value' => $override['icon_classes'] ?? '',
      '#description' => $this->t('Дополнительные классы иконки i, кроме классов FontAwesome. Если оставить пустым, будут использованы классы по умолчанию из настроек модуля.'),
    ];

    $row['label_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы метки'),
      '#default_value' => $override['label_classes'] ?? '',
      '#description' => $this->t('Дополнительные классы метки span. Если оставить пустым, будут использованы классы по умолчанию из настроек модуля.'),
    ];

    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $orientation = $form_state->getValue('orientation');
    $this->configuration['orientation'] = in_array($orientation, ['horizontal', 'vertical'], TRUE)
      ? $orientation : 'horizontal';

    $this->configuration['overrides'] = $this->getSubmittedOverrides($form_state);
  }

  /**
   * Extracts clean per-link overrides from the submitted form values.
   *
   * @return array[]
   *   List of overrides, matched to the global link list by index.
   */
  private function getSubmittedOverrides(FormStateInterface $form_state): array {
    $values = $form_state->getValue(['links_area', 'links'])
      ?? $form_state->getValue(['settings', 'links_area', 'links'])
      ?? [];

    $overrides = [];
    foreach ($values as $row) {
      if (!is_array($row)) {
        continue;
      }
      $display = $row['display'] ?? 'icon_label';
      $overrides[] = [
        'enabled' => !empty($row['enabled']),
        'display' => in_array($display, self::DISPLAY_MODES, TRUE) ? $display : 'icon_label',
        'wrapper_classes' => (string) ($row['wrapper_classes'] ?? ''),
        'link_classes' => (string) ($row['link_classes'] ?? ''),
        'icon_classes' => (string) ($row['icon_classes'] ?? ''),
        'label_classes' => (string) ($row['label_classes'] ?? ''),
      ];
    }
    return $overrides;
  }

  /**
   * Returns the global link list from the module settings, sorted by weight.
   *
   * @return array[]
   *   List of link definitions: label, url, icon, nofollow, weight.
   */
  private function getGlobalLinks(): array {
    $links = $this->configFactory->get('yu_social.settings')->get('links') ?? [];
    usort($links, static fn(array $a, array $b): int => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    return array_values($links);
  }

  /**
   * Returns the default CSS classes from the module settings.
   *
   * @return array
   *   Classes keyed by wrapper_classes, link_classes, icon_classes and
   *   label_classes.
   */
  private function getDefaultClasses(): array {
    $defaults = [];
    foreach (self::CLASS_KEYS as $key) {
      $defaults[$key] = (string) ($this->configFactory->get('yu_social.settings')->get('default_classes.' . $key) ?? '');
    }
    return $defaults;
  }

  /**
   * Returns the prepared links of this block instance.
   *
   * The global list is taken from the module settings; per-link overrides of
   * this instance (enabled, display, extra CSS classes) are applied by index.
   * Links added to the global list after this instance was configured get
   * the default override values. An empty class override falls back to the
   * matching default class from the module settings.
   */
  private function getLinks(): array {
    $overrides = $this->configuration['overrides'] ?? [];
    $default_classes = $this->getDefaultClasses();

    $links = [];
    foreach ($this->getGlobalLinks() as $i => $item) {
      $override = $overrides[$i] ?? self::NEW_OVERRIDE;

      $item += [
        'enabled' => TRUE,
        'icon_type' => 'fontawesome',
        'svg' => NULL,
        'display' => 'icon_label',
        'wrapper_classes' => '',
        'link_classes' => '',
        'icon_classes' => '',
        'label_classes' => '',
      ];
      $item['enabled'] = !empty($override['enabled']);
      $item['display'] = $override['display'];

      // Per-instance classes win; empty fields fall back to the module
      // defaults.
      foreach (self::CLASS_KEYS as $key) {
        $class = trim((string) ($override[$key] ?? ''));
        $item[$key] = $class !== '' ? $class : $default_classes[$key];
      }

      if (empty($item['enabled'])) {
        continue;
      }
      $links[] = $this->prepareLink($item);
    }
    return $links;
  }

  /**
   * Prepares a single link for rendering.
   *
   * Plain phone numbers are converted to "tel:", email addresses to
   * "mailto:"; anything else is used as-is.
   */
  private function prepareLink(array $item): array {
    $url = trim((string) $item['url']);

    if (preg_match('/^(https?:|tel:|mailto:|\/|#)/i', $url)) {
      $href = $url;
    }
    elseif (filter_var($url, FILTER_VALIDATE_EMAIL)) {
      $href = 'mailto:' . $url;
    }
    elseif (preg_match('/^[+0-9()\- ]+$/', $url)) {
      $href = 'tel:' . preg_replace('/[^+0-9]/', '', $url);
    }
    else {
      $href = $url;
    }

    $display = in_array($item['display'] ?? 'icon_label', self::DISPLAY_MODES, TRUE)
      ? ($item['display'] ?? 'icon_label')
      : 'icon_label';

    $icon_type = $item['icon_type'] ?? 'fontawesome';
    $svg_file = $icon_type === 'svg' && !empty($item['svg'])
      ? $this->loadSvgFile((int) $item['svg'])
      : NULL;

    return [
      'label' => (string) $item['label'],
      'href' => $href,
      'icon' => (string) $item['icon'],
      'icon_type' => $icon_type,
      'svg_url' => $svg_file?->createFileUrl(FALSE) ?: '',
      'display' => $display,
      'nofollow' => (bool) ($item['nofollow'] ?? TRUE),
      'wrapper_classes' => (string) ($item['wrapper_classes'] ?? ''),
      'link_classes' => (string) ($item['link_classes'] ?? ''),
      'icon_classes' => (string) ($item['icon_classes'] ?? ''),
      'label_classes' => (string) ($item['label_classes'] ?? ''),
      'external' => (bool) preg_match('#^https?://#i', $href),
    ];
  }

  /**
   * Loads an uploaded SVG file by its ID.
   */
  private function loadSvgFile(int $fid): ?FileInterface {
    if ($fid <= 0) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    return $file instanceof FileInterface ? $file : NULL;
  }

}
