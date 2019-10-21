<?php

namespace Drupal\uchi_pro\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
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
    ];

    $form['login'] = [
      '#type' => 'textfield',
      '#title' => 'Логин',
      '#default_value' => $config->get('login'),
    ];

    $form['password'] = [
      '#type' => 'password',
      '#title' => 'Пароль',
      '#default_value' => $config->get('password'),
    ];

    $form['access_token'] = [
      '#type' => 'textfield',
      '#title' => 'Токен',
      '#default_value' => $config->get('access_token'),
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
    try {
      $url = ApiClient::prepareUrl($url);
    } catch (Exception $e) {
      $form_state->setErrorByName('url', 'Невалидный URL.');
      return;
    }
    $form_state->setValue('url', $url);

    $login = $form_state->getValue('login');
    $password = $form_state->getValue('password');
    $accessToken = $form_state->getValue('access_token');

    if (empty($password)) {
      $config = $this->config(static::SETTINGS);
      $password = $config->get('password');
    }

    try {
      $identity = null;
      if ($accessToken) {
        $identity = Identity::createByAccessToken($url, $accessToken);
      } elseif ($login) {
        $identity = Identity::createByLogin($url, $login, $password);
      }

      if ($identity) {
        $apiClient = ApiClient::create($identity);

        $me = $apiClient->users()->getMe();

        if (!in_array($me->role->id, ['administrator', 'manager'])) {
          throw new BadRoleException();
        }
      }
    } catch (BadRoleException $e) {
      $form_state->setErrorByName('url', 'Нужны данные администратора или менеджера.');
    } catch (Exception $e) {
      watchdog_exception('error', $e);
      $form_state->setErrorByName('url', 'Не удалось подключиться к СДО.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $url = $form_state->getValue('url');
    $login = $form_state->getValue('login');
    $password = $form_state->getValue('password');
    $accessToken = $form_state->getValue('access_token');
    $publishCoursesOnImport = $form_state->getValue('publish_courses_on_import');
    $updateCoursesTitles = $form_state->getValue('update_courses_titles');
    $updateCoursesPrices = $form_state->getValue('update_courses_prices');

    $config = $this->configFactory->getEditable(static::SETTINGS);
    $config->set('url', $url);
    $config->set('login', $login);
    if ($password) {
      $config->set('password', $password);
    }
    $config->set('access_token', $accessToken);
    $config->set('publish_courses_on_import', $publishCoursesOnImport);
    $config->set('update_courses_titles', $updateCoursesTitles);
    $config->set('update_courses_prices', $updateCoursesPrices);
    $config->save();

    parent::submitForm($form, $form_state);

    $startImport = $form_state->getValue('start_import');
    if ($startImport) {
      try {
        $importCoursesService = new ImportCoursesService();
        $importCoursesService->importCourses();
      } catch (Exception $e) {
        watchdog_exception('error', $e);
        drupal_set_message('Не удалось обновить курсы.');
      }
    }
  }
}
