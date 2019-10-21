<?php

namespace Drupal\uchi_pro\Form;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\node\Entity\Node;
use Drupal\uchi_pro\Exception\BadRoleException;
use Exception;
use UchiPro\ApiClient;
use UchiPro\Courses\Course as ApiCourse;
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

    $this->importCourses();
  }

  public function importCourses()
  {
    try {
      $courses = $this->fetchCourses();

      $this->updateThemes($courses);
      $this->updateCourses($courses);

    } catch (Exception $e) {
      watchdog_exception('error', $e);
      drupal_set_message('Не удалось обновить курсы.');
    }
  }

  /**
   * @return array|ApiCourse[]
   *
   * @throws Exception
   */
  protected function fetchCourses()
  {
    $config = $this->config(static::SETTINGS);

    $url = $config->get('url');
    $login = $config->get('login');
    $password = $config->get('password');

    $identity = Identity::createByLogin($url, $login, $password);
    $apiClient = ApiClient::create($identity);

    return $apiClient->courses()->findBy();
  }

  protected function getThemesNodes()
  {
    $nids = Drupal::entityQuery('node')->condition('type','theme')->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $themeId = $node->get('field_theme_uuid')->getString();
      $nodesByIds[$themeId] = $node;
    }

    return $nodesByIds;
  }

  protected function getCoursesNodes()
  {
    $nids = Drupal::entityQuery('node')->condition('type','course')->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $courseId = $node->get('field_course_uuid')->getString();
      $nodesByIds[$courseId] = $node;
    }

    return $nodesByIds;
  }

  /**
   * @param array|ApiCourse[] $apiCourses
   *
   * @return array|Node[]
   *
   * @throws Exception
   */
  protected function updateThemes(array $apiCourses)
  {
    $themesNodes = $this->getThemesNodes();

    foreach ($apiCourses as $apiCourse) {
      $isTheme = $apiCourse->depth === 0 && $apiCourse->lessonsCount === 0 && $apiCourse->childrenCount > 0;
      if (!$isTheme) {
        continue;
      }

      if (isset($themesNodes[$apiCourse->id])) {
        $node = $themesNodes[$apiCourse->id];
      } else {
        $node = Node::create([
          'type' => 'theme',
          'title' => mb_substr($apiCourse->title, 0, 250),
          'field_theme_uuid' => ['value' => $apiCourse->id],
        ]);
      }

      $node->save();
    }

    return $themesNodes;
  }

  /**
   * @param array|ApiCourse[] $apiCourses
   *
   * @return array|Node[]
   *
   * @throws Exception
   */
  protected function updateCourses(array $apiCourses)
  {
    $config = $this->config(static::SETTINGS);

    $needPublishCoursesOnImport = $config->get('publish_courses_on_import');
    $needUpdateCoursesTitles = $config->get('update_courses_titles');
    $needUpdateCoursesPrices = $config->get('update_courses_prices');

    $coursesNodes = $this->getCoursesNodes();
    $themesNodes = $this->getThemesNodes();

    foreach ($apiCourses as $apiCourse) {
      $isParentTheme = isset($themesNodes[$apiCourse->parentId]);
      if (!$isParentTheme) {
        continue;
      }
      $hasLessons = $apiCourse->lessonsCount > 0;
      if (!$hasLessons) {
        continue;
      }

      $apiCourse = clone $apiCourse;
      $apiCourse->title = mb_substr($apiCourse->title, 0, 2000);

      $courseNode = isset($coursesNodes[$apiCourse->id]) ? $coursesNodes[$apiCourse->id] : null;
      $isNew = empty($courseNode);
      $needSave = false;

      $previousApiCourse = $this->createApiCourseByNode($courseNode);

      $shortTitle = mb_substr($apiCourse->title, 0, 250);

      if (!$courseNode) {
        $needSave = true;
        $courseNode = Node::create([
          'type' => 'course',
          'status' => $needPublishCoursesOnImport ? 1 : 0,
          'title' => $shortTitle,
          'field_course_title' => ['value' => $apiCourse->title],
          'field_course_uuid' => ['value' => $apiCourse->id],
          'field_course_price' => ['value' => $apiCourse->price],
        ]);
      }

      if ($apiCourse->parentId != $previousApiCourse->parentId) {
        $needSave = true;
        $courseTheme = $themesNodes[$apiCourse->parentId];
        $courseNode->set('field_course_theme', [
          'entity' => $courseTheme,
        ]);
      }

      if ($needUpdateCoursesTitles) {
        if ($apiCourse->title != $previousApiCourse->title) {
          $needSave = true;
          $courseNode->set('title', $shortTitle);
          $courseNode->set('field_course_title', $apiCourse->title);
        }
      }

      if ($needUpdateCoursesPrices) {
        if ($apiCourse->price != $previousApiCourse->price) {
          $needSave = true;
          $courseNode->set('field_course_price', ['value' => $apiCourse->price]);
        }
      }

      if ($apiCourse->hours != $previousApiCourse->hours) {
        $needSave = true;
        $courseNode->set('field_course_hours', ['value' => $apiCourse->hours]);
      }

      if ($needSave) {
        $courseNode->save();
        $coursesNodes[$apiCourse->id] = $courseNode;

        $messageText = "Курс <a href=\"/node/{$courseNode->id()}/edit\">{$apiCourse->title}</a> " . ($isNew ? ' импортирован' : 'обновлен') . '.';
        $message = Markup::create($messageText);
        Drupal::messenger()->addMessage($message);
      }
    }

    return $coursesNodes;
  }

  private function createApiCourseByNode(Node $node)
  {
    $apiCourse = new ApiCourse();

    $apiCourse->id = $node->get('field_course_uuid')->getString();
    $apiCourse->parentId = $node->get('field_course_theme')->entity->get('field_theme_uuid')->getString();
    $apiCourse->title = $node->get('field_course_title')->getString();
    $apiCourse->price = $node->get('field_course_price')->getString();
    $apiCourse->hours = $node->get('field_course_hours')->getString();

    return $apiCourse;
  }
}
