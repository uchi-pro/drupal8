<?php

namespace Drupal\uchi_pro\Form;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\uchi_pro\Exception\BadRoleException;
use Drupal\uchi_pro\Service\ImportCoursesService;
use Exception;
use UchiPro\ApiClient;
use UchiPro\Identity;

class SettingsForm extends ConfigFormBase {

  const SETTINGS = 'uchi_pro.settings';

  public function getFormId() {
    return 'uchi_pro_admin_settings';
  }

  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    $form['url'] = [
      '#type' => 'textfield',
      '#title' => 'URL СДО',
      '#default_value' => $config->get('url'),
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['access_token'] = [
      '#type' => 'textfield',
      '#title' => 'Токен',
      '#default_value' => $config->get('access_token'),
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['ignored_themes_ids'] = [
      '#type' => 'textarea',
      '#title' => 'Идентификаторы направлений, курсы по которым не должны импортироваться на сайт',
      '#rows' => 2,
      '#default_value' => $config->get('ignored_themes_ids'),
      '#description' => 'По одному идентификатору направления вида 00000000-0000-0000-C000-000000000000 в строку.',
    ];

    $form['publish_courses_on_import'] = [
      '#type' => 'checkbox',
      '#title' => 'Публиковать курсы при импорте',
      '#default_value' => $config->get('publish_courses_on_import'),
    ];

    $form['update_courses_titles'] = [
      '#type' => 'checkbox',
      '#title' => 'Обновлять названия курсов при импорте',
      '#default_value' => $config->get('update_courses_titles'),
    ];

    $form['update_courses_prices'] = [
      '#type' => 'checkbox',
      '#title' => 'Обновлять цены курсов при импорте',
      '#default_value' => $config->get('update_courses_prices'),
    ];

    $form['use_cron'] = [
      '#type' => 'checkbox',
      '#title' => 'Запускать импорт курсов по крону',
      '#default_value' => $config->get('use_cron'),
    ];

    $form['start_import'] = [
      '#type' => 'checkbox',
      '#title' => 'Запустить импорт после сохранения настроек',
      '#default_value' => true,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $url = $form_state->getValue('url');
    if (empty($url)) {
      return;
    }

    try {
      $url = ApiClient::prepareUrl($url);
    } catch (Exception $e) {
      $form_state->setErrorByName('url', 'Невалидный URL.');
      return;
    }
    $form_state->setValue('url', $url);

    $accessToken = $form_state->getValue('access_token');
    if (empty($accessToken)) {
      return;
    }

    try {
      $identity = Identity::createByAccessToken($url, $accessToken);

      if ($identity) {
        $apiClient = ApiClient::create($identity);

        $me = $apiClient->users()->getMe();

        if (!in_array($me->role->id, ['manager'])) {
          throw new BadRoleException();
        }
      }
    } catch (BadRoleException $e) {
      $form_state->setErrorByName('url', Markup::create("Укажите токен для доступа менеджера <a href=\"{$url}/vendors/128#other\" target=\"_blank\">со страницы настроек вендора</a>."));
    } catch (Exception $e) {
      watchdog_exception('error', $e);
      $form_state->setErrorByName('url', 'Не удалось подключиться к СДО.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $url = $form_state->getValue('url');
    $accessToken = $form_state->getValue('access_token');
    $ignoredThemesIds = $form_state->getValue('ignored_themes_ids');
    $publishCoursesOnImport = $form_state->getValue('publish_courses_on_import');
    $updateCoursesTitles = $form_state->getValue('update_courses_titles');
    $updateCoursesPrices = $form_state->getValue('update_courses_prices');
    $useCron = $form_state->getValue('use_cron');

    $ignoredThemesIds = implode("\n", array_map(function ($id) {
      return trim($id);
    }, explode("\n", trim($ignoredThemesIds))));

    $config = $this->configFactory->getEditable(static::SETTINGS);
    $config->set('url', $url);
    $config->set('access_token', $accessToken);
    $config->set('ignored_themes_ids', $ignoredThemesIds);
    $config->set('publish_courses_on_import', $publishCoursesOnImport);
    $config->set('update_courses_titles', $updateCoursesTitles);
    $config->set('update_courses_prices', $updateCoursesPrices);
    $config->set('use_cron', $useCron);
    $config->save();

    parent::submitForm($form, $form_state);

    $startImport = $form_state->getValue('start_import');
    if ($startImport) {
      try {
        $importCoursesService = new ImportCoursesService();
        $importCoursesService->importCourses();
      } catch (Exception $e) {
        Drupal::messenger()->addMessage($e->getMessage(), 'error');
      }
    }
  }
}
