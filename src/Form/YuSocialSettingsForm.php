<?php

declare(strict_types=1);

namespace Drupal\yu_social\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure social links settings for this site.
 */
final class YuSocialSettingsForm extends ConfigFormBase {

  /**
   * Icon types available for a link.
   */
  public const ICON_TYPES = ['fontawesome', 'svg'];

  /**
   * Default values for a newly added link row.
   */
  private const NEW_ROW = [
    'enabled' => TRUE,
    'label' => '',
    'url' => '',
    'icon_type' => 'fontawesome',
    'icon' => 'fa-solid fa-link',
    'svg' => NULL,
    'nofollow' => TRUE,
    'weight' => 0,
  ];

  /**
   * Default CSS class values applied when a block instance leaves a class
   * field empty.
   */
  private const DEFAULT_CLASSES = [
    'wrapper_classes' => 'social-links__item--default',
    'link_classes' => 'social-links__link--default',
    'icon_classes' => 'social-links__icon--default',
    'label_classes' => 'social-links__label--default',
  ];

  /**
   * The entity type manager.
   *
   * Must be protected (not private): the form object is serialized into the
   * form cache between page load and submission, and
   * DependencySerializationTrait::__sleep() only sees properties that are
   * visible from FormBase scope, silently dropping private ones. On wakeup
   * the service is restored from _serviceIds.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a YuSocialSettingsForm instance.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'yu_social_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['yu_social.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // The current rows are kept in form state so that AJAX "Add"/"Remove"
    // operations do not lose the values typed into other rows.
    $links = $form_state->get('links');
    if ($links === NULL) {
      $links = $this->config('yu_social.settings')->get('links') ?? [];
      usort($links, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);
      $links = array_values($links);
      $form_state->set('links', $links);
    }

    $form['#attached'] = [
      'library' => ['yu_social/yu_social.admin'],
    ];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Управление ссылками, отображаемыми в блоке «Social links». Телефоны автоматически преобразуются в «tel:», адреса email — в «mailto:». Поля «Классы по умолчанию» задают CSS-классы, которые используются, если соответствующее поле в настройках экземпляра блока оставлено пустым.') . '</p>',
    ];

    $form['links_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'yu-social-links-wrapper'],
    ];

    $form['links_wrapper']['links'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Ссылки'),
      '#tree' => TRUE,
      '#attributes' => ['class' => ['social-links-rows']],
    ];

    foreach ($links as $i => $link) {
      $form['links_wrapper']['links'][$i] = $this->buildRow($i, $link);
    }

    $form['links_wrapper']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Добавить ссылку'),
      '#submit' => ['::addRow'],
      '#ajax' => [
        'callback' => '::ajaxRefresh',
        'wrapper' => 'yu-social-links-wrapper',
      ],
    ];

    $form['default_classes'] = [
      '#type' => 'details',
      '#title' => $this->t('Классы по умолчанию'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('Классы по умолчанию применяются ко всем ссылкам блока. Если одноимённое поле класса заполнено в настройках конкретного экземпляра блока, оно имеет приоритет над значением, заданным здесь.'),
    ];

    $form['default_classes']['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы обертки'),
      '#default_value' => $this->getDefaultClass('wrapper_classes'),
      '#description' => $this->t('Классы по умолчанию для элемента-обертки li.'),
    ];

    $form['default_classes']['link_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы ссылки'),
      '#default_value' => $this->getDefaultClass('link_classes'),
      '#description' => $this->t('Классы по умолчанию для ссылки a.'),
    ];

    $form['default_classes']['icon_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы иконки'),
      '#default_value' => $this->getDefaultClass('icon_classes'),
      '#description' => $this->t('Классы по умолчанию для иконки i, кроме классов FontAwesome.'),
    ];

    $form['default_classes']['label_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы метки'),
      '#default_value' => $this->getDefaultClass('label_classes'),
      '#description' => $this->t('Классы по умолчанию для метки span.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns a single default class value from the module settings.
   */
  private function getDefaultClass(string $key): string {
    return (string) ($this->config('yu_social.settings')->get('default_classes.' . $key) ?? self::DEFAULT_CLASSES[$key]);
  }

  /**
   * Builds a single link row.
   */
  private function buildRow(int $i, array $link): array {
    $title = $link['label'] !== '' ? $link['label'] : $this->t('Новая ссылка');
    $icon_type = in_array($link['icon_type'] ?? 'fontawesome', self::ICON_TYPES, TRUE)
      ? ($link['icon_type'] ?? 'fontawesome')
      : 'fontawesome';

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
      '#default_value' => (bool) $link['enabled'],
    ];

    $row['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Метка'),
      '#default_value' => $link['label'],
    ];

    $row['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ссылка / значение'),
      '#default_value' => $link['url'],
      '#description' => $this->t('Полный URL, телефон или email.'),
    ];

    $row['nofollow'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Добавлять к ссылке rel="nofollow"'),
      '#default_value' => (bool) ($link['nofollow'] ?? TRUE),
    ];

    $row['icon_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Тип иконки'),
      '#options' => [
        'fontawesome' => $this->t('Иконка FontAwesome'),
        'svg' => $this->t('Иконка SVG'),
      ],
      '#default_value' => $icon_type,
      '#ajax' => [
        'callback' => '::ajaxRefresh',
        'wrapper' => 'yu-social-links-wrapper',
        'event' => 'change',
      ],
    ];

    $row['icon'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Классы иконки FontAwesome'),
      '#default_value' => $link['icon'],
      '#description' => $this->t('Например: fa-brands fa-telegram.'),
      '#states' => [
        'visible' => [
          'select[name="links[links][' . $i . '][icon_type]"]' => ['value' => 'fontawesome'],
        ],
      ],
    ];

    $row['svg'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Изображение SVG'),
      '#upload_location' => 'public://social-icons',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'svg'],
      ],
      '#default_value' => isset($link['svg']) ? [$link['svg']] : [],
      '#description' => $this->t('Загрузите SVG-файл, который будет использоваться в качестве иконки.'),
      '#states' => [
        'visible' => [
          'select[name="links[links][' . $i . '][icon_type]"]' => ['value' => 'svg'],
        ],
      ],
    ];

    $row['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Вес'),
      '#default_value' => (int) $link['weight'],
      '#description' => $this->t('Меньшие значения отображаются первыми.'),
    ];

    $row['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Удалить'),
      '#name' => 'remove_row_' . $i,
      '#row_index' => $i,
      '#submit' => ['::removeRow'],
      '#ajax' => [
        'callback' => '::ajaxRefresh',
        'wrapper' => 'yu-social-links-wrapper',
      ],
    ];

    return $row;
  }

  /**
   * Submit callback: adds a new empty row.
   */
  public function addRow(array &$form, FormStateInterface $form_state): void {
    $links = $this->extractLinks($form_state);
    $links[] = self::NEW_ROW;
    $form_state->set('links', $links);
    $form_state->setRebuild();
  }

  /**
   * Submit callback: removes the row the button belongs to.
   */
  public function removeRow(array &$form, FormStateInterface $form_state): void {
    $index = (int) $form_state->getTriggeringElement()['#row_index'];
    $links = $this->extractLinks($form_state);
    unset($links[$index]);
    $form_state->set('links', array_values($links));
    $form_state->setRebuild();
  }

  /**
   * AJAX callback: returns the refreshed rows wrapper.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    return $form['links_wrapper'];
  }

  /**
   * Extracts clean link rows from submitted form values.
   *
   * @return array[]
   *   List of link definitions: enabled, label, url, icon_type, icon, svg,
   *   nofollow, weight.
   */
  private function extractLinks(FormStateInterface $form_state): array {
    $links = [];
    foreach ($form_state->getValue('links') ?? [] as $row) {
      if (!is_array($row) || !isset($row['label'], $row['url'])) {
        continue;
      }
      $icon_type = in_array($row['icon_type'] ?? 'fontawesome', self::ICON_TYPES, TRUE)
        ? ($row['icon_type'] ?? 'fontawesome')
        : 'fontawesome';

      // The managed_file element returns a list of file IDs; keep the first.
      $svg = NULL;
      if ($icon_type === 'svg' && !empty($row['svg']) && is_array($row['svg'])) {
        $svg = (int) reset($row['svg']) ?: NULL;
      }

      $links[] = [
        'enabled' => !empty($row['enabled']),
        'label' => (string) $row['label'],
        'url' => (string) $row['url'],
        'icon_type' => $icon_type,
        'icon' => $icon_type === 'fontawesome' ? (string) ($row['icon'] ?? '') : '',
        'svg' => $svg,
        'nofollow' => (bool) ($row['nofollow'] ?? TRUE),
        'weight' => (int) ($row['weight'] ?? 0),
      ];
    }
    return $links;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $links = $this->extractLinks($form_state);
    usort($links, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);
    $links = array_values($links);

    $file_storage = $this->entityTypeManager->getStorage('file');
    $previous_fids = array_column($this->config('yu_social.settings')->get('links') ?? [], 'svg');
    $current_fids = array_column($links, 'svg');

    // Mark uploaded files as permanent and register usage so that cron
    // does not delete them, and release files that are no longer used.
    foreach ($current_fids as $fid) {
      $file = $fid ? $file_storage->load($fid) : NULL;
      if (!$file instanceof FileInterface) {
        continue;
      }
      if ($file->isTemporary()) {
        $file->setPermanent();
        $file->save();
      }
      \Drupal::service('file.usage')->add($file, 'yu_social', 'config', 'yu_social.settings');
    }
    foreach (array_diff($previous_fids, $current_fids) as $fid) {
      $file = $fid ? $file_storage->load($fid) : NULL;
      if (!$file instanceof FileInterface) {
        continue;
      }
      \Drupal::service('file.usage')->delete($file, 'yu_social', 'config', 'yu_social.settings');
    }

    // Default CSS classes: used when a block instance class field is empty.
    $default_classes = [];
    foreach (array_keys(self::DEFAULT_CLASSES) as $key) {
      $default_classes[$key] = trim((string) ($form_state->getValue(['default_classes', $key]) ?? ''));
    }

    $this->config('yu_social.settings')
      ->set('links', $links)
      ->set('default_classes', $default_classes)
      ->save();

    $this->messenger()->addStatus($this->t('Настройки ссылок сохранены.'));
  }

}
