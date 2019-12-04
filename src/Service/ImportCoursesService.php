<?php

namespace Drupal\uchi_pro\Service;

use Drupal;
use Drupal\Core\Render\Markup;
use Drupal\node\Entity\Node;
use Drupal\uchi_pro\Form\SettingsForm;
use Exception;
use UchiPro\ApiClient;
use UchiPro\Courses\Course as ApiCourse;
use UchiPro\Identity;

class ImportCoursesService
{
  /**
   * @throws Exception
   */
  public function importCourses()
  {
    if (!$this->settingsExists()) {
      throw new Exception('Не указаны настройки для импорта курсов.');
    }

    try {
      $apiCourses = $this->fetchApiCourses();

      $this->updateTypes($apiCourses);
      $this->updateThemes($apiCourses);
      $this->updateCourses($apiCourses);
    } catch (Exception $exception) {
      $lastException = $exception;
      watchdog_exception('error', $exception);
      while ($exception = $exception->getPrevious()) {
        watchdog_exception('error', $exception);
      }
      throw new Exception('Не удалось импортировать курсы.', 0, $lastException);
    }
  }

  private function getSettings()
  {
    return Drupal::config(SettingsForm::SETTINGS);
  }

  /**
   * @return bool
   */
  public function settingsExists()
  {
    $settings = $this->getSettings();
    $url = $settings->get('url');
    return !empty($url);
  }

  /**
   * @return array|ApiCourse[]
   *
   * @throws Exception
   */
  protected function fetchApiCourses()
  {
    $settings = $this->getSettings();

    $url = $settings->get('url');
    $login = $settings->get('login');
    $password = $settings->get('password');
    $accessToken = $settings->get('access_token');

    $identity = !empty($accessToken)
      ? Identity::createByAccessToken($url, $accessToken)
      : Identity::createByLogin($url, $login, $password);
    $apiClient = ApiClient::create($identity);

    return $apiClient->courses()->findBy();
  }

  protected function getTypesNodes()
  {
    $nids = Drupal::entityQuery('node')->condition('type','training_type')->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $themeId = $node->get('field_training_type_id')->getString();
      $nodesByIds[$themeId] = $node;
    }

    return $nodesByIds;
  }

  protected function getThemesNodes()
  {
    $nids = Drupal::entityQuery('node')->condition('type','theme')->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $themeId = $node->get('field_theme_id')->getString();
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
      $courseId = $node->get('field_course_id')->getString();
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
  protected function updateTypes(array $apiCourses)
  {
    $typesNodes = $this->getTypesNodes();

    foreach ($apiCourses as $apiCourse) {
      if (empty($apiCourse->type->id)) {
        continue;
      }

      $type = $apiCourse->type;

      if (isset($typesNodes[$type->id])) {
        $node = $typesNodes[$type->id];
      } else {
        $node = Node::create([
          'type' => 'training_type',
          'title' => $type->title,
          'field_training_type_id' => ['value' => $type->id],
        ]);
      }

      $node->save();

      $typesNodes[$type->id] = $node;
    }

    return $typesNodes;
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
          'field_theme_id' => ['value' => $apiCourse->id],
        ]);
      }

      $node->save();

      $themesNodes[$apiCourse->id] = $node;
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
    $settings = $this->getSettings();

    $needPublishCoursesOnImport = $settings->get('publish_courses_on_import');
    $needUpdateCoursesTitles = $settings->get('update_courses_titles');
    $needUpdateCoursesPrices = $settings->get('update_courses_prices');

    $coursesNodes = $this->getCoursesNodes();
    $themesNodes = $this->getThemesNodes();

    $suitableApiCourses = array_filter($apiCourses, function (ApiCourse $apiCourse) use ($themesNodes) {
      $isParentTheme = isset($themesNodes[$apiCourse->parentId]);
      if (!$isParentTheme) {
        return FALSE;
      }
      $hasLessons = $apiCourse->lessonsCount > 0;
      if (!$hasLessons) {
        return FALSE;
      }
      $isPriceEmpty = !is_numeric($apiCourse->price);
      if ($isPriceEmpty) {
        return FALSE;
      }
      return TRUE;
    });

    $count = 0;
    foreach ($suitableApiCourses as $apiCourse) {
      $apiCourse = clone $apiCourse;
      $apiCourse->title = mb_substr($apiCourse->title, 0, 2000);

      $courseNode = isset($coursesNodes[$apiCourse->id]) ? $coursesNodes[$apiCourse->id] : null;
      $isNew = empty($courseNode);
      $needSave = FALSE;

      $previousApiCourse = $courseNode ? $this->createApiCourseByNode($courseNode) : new ApiCourse();

      $shortTitle = mb_substr($apiCourse->title, 0, 250);

      if (!$courseNode) {
        $needSave = true;
        $courseNode = Node::create([
          'type' => 'course',
          'status' => $needPublishCoursesOnImport ? 1 : 0,
          'title' => $shortTitle,
          'field_course_title' => ['value' => $apiCourse->title],
          'field_course_id' => ['value' => $apiCourse->id],
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
        $count++;

        $courseNode->save();
        $coursesNodes[$apiCourse->id] = $courseNode;

        $messageText = "Курс <a href=\"/node/{$courseNode->id()}/edit\">{$apiCourse->title}</a> " . ($isNew ? ' импортирован' : 'обновлен') . '.';
        $message = Markup::create($messageText);
        Drupal::messenger()->addMessage($message);
      }

      $coursesNodes[$apiCourse->id] = $courseNode;
    }

    Drupal::messenger()->addMessage("Обновлено курсов: {$count}");
    Drupal::logger('uchi_pro')->info("Обновлено курсов: {$count}");

    return $coursesNodes;
  }

  private function createApiCourseByNode(Node $node)
  {
    $apiCourse = new ApiCourse();

    $apiCourse->id = $node->get('field_course_id')->getString();
    $apiCourse->parentId = $node->get('field_course_theme')->entity->get('field_theme_id')->getString();
    $apiCourse->title = $node->get('field_course_title')->getString();
    $apiCourse->price = $node->get('field_course_price')->getString();
    $apiCourse->hours = $node->get('field_course_hours')->getString();

    return $apiCourse;
  }
}
