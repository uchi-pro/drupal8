<?php

namespace Drupal\uchi_pro\Service;

use Drupal;
use Drupal\Core\Render\Markup;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
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
      throw new Exception('Не заполнены настройки для импорта курсов.');
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
    $accessToken = $settings->get('access_token');
    return !empty($url) && !empty($accessToken);
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
    $accessToken = $settings->get('access_token');

    $identity = Identity::createByAccessToken($url, $accessToken);
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

  /**
   * @return NodeInterface[]
   */
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

  /**
   * @return NodeInterface[]
   */
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

    $settings = $this->getSettings();
    $ignoredThemesIds = explode("\n", $settings->get('ignored_themes_ids'));

    foreach ($apiCourses as $apiCourse) {
      $isTheme = $apiCourse->depth === 0 && $apiCourse->lessonsCount === 0 && $apiCourse->childrenCount > 0;
      if (!$isTheme) {
        continue;
      }
      if (in_array($apiCourse->id, $ignoredThemesIds)) {
        $this->warning("Направление <a href=\"{$this->getApiCourseUrl($apiCourse)}\" target='_blank'>{$apiCourse->title}</a> пропущено согласно настройкам интеграции.");
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

    $coursesNodesByIds = $this->getCoursesNodes();
    $themesNodesByIds = $this->getThemesNodes();

    $coursesForUnpublishIds = array_keys($coursesNodesByIds);

    $suitableApiCourses = array_filter($apiCourses, function (ApiCourse $apiCourse) use ($themesNodesByIds) {
      $isParentTheme = isset($themesNodesByIds[$apiCourse->parentId]);
      if (!$isParentTheme) {
        return FALSE;
      }
      $hasLessons = $apiCourse->lessonsCount > 0;
      if (!$hasLessons) {
        return FALSE;
      }
      $isPriceEmpty = !is_numeric($apiCourse->price);
      if ($isPriceEmpty) {
        $this->warning("Курс <a href=\"{$this->getApiCourseUrl($apiCourse)}\" target=\"_blank\">{$apiCourse->title}</a> пропущен: не указана стоимость обучения.");
        return FALSE;
      }
      return TRUE;
    });

    $updatedCount = 0;
    foreach ($suitableApiCourses as $apiCourse) {
      $apiCourse = clone $apiCourse;
      $apiCourse->title = mb_substr($apiCourse->title, 0, 2000);

      $courseNode = null;
      if (isset($coursesNodesByIds[$apiCourse->id])) {
        $courseNode = $coursesNodesByIds[$apiCourse->id];
        unset($coursesForUnpublishIds[array_search($apiCourse->id, $coursesForUnpublishIds)]);
      };
      $isNew = empty($courseNode);
      $needSave = FALSE;

      $previousApiCourse = $courseNode ? $this->createApiCourseByNode($courseNode) : new ApiCourse();

      $shortTitle = mb_substr($apiCourse->title, 0, 250);

      $plan = $apiCourse->academicPlan;

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
        $courseTheme = $themesNodesByIds[$apiCourse->parentId];
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

      $serializedPlan = $this->getSerializedPlan($apiCourse);
      $previousSerializedPlan = $courseNode->get('field_course_plan')->getString();
      if ($serializedPlan != $previousSerializedPlan) {
        $needSave = true;
        $courseNode->set('field_course_plan', ['value' => $serializedPlan]);
      }

      if ($needSave) {
        $updatedCount++;

        $courseNode->save();
        $coursesNodesByIds[$apiCourse->id] = $courseNode;

        $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> " . ($isNew ? ' импортирован' : 'обновлен') . '.');
      }

      $coursesNodesByIds[$apiCourse->id] = $courseNode;
    }

    $unpublishedCount = 0;
    foreach ($coursesForUnpublishIds as $courseId) {
      $courseNode = $coursesNodesByIds[$courseId];

      if ($courseNode->isPublished()) {
        $unpublishedCount++;
        $courseNode->setUnpublished();
        $courseNode->save();

        $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> снят с публикации.");
      }
    }

    $this->log("Обновлено курсов: {$updatedCount}");
    $this->log("Снято с публикации курсов: {$unpublishedCount}");

    return $coursesNodesByIds;
  }

  private function status($messageText)
  {
    $message = Markup::create($messageText);
    $messanger = Drupal::messenger();
    $messanger->addMessage($message, $messanger::TYPE_STATUS);
  }

  private function warning($messageText)
  {
    $message = Markup::create($messageText);
    $messanger = Drupal::messenger();
    $messanger->addMessage($message, $messanger::TYPE_WARNING);
  }

  private function log($message)
  {
    $this->status($message);
    Drupal::logger('uchi_pro')->info($message);
  }

  private function getApiCourseUrl(ApiCourse $apiCourse)
  {
    $settings = $this->getSettings();

    $url = $settings->get('url');

    return "{$url}/courses/{$apiCourse->id}";
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

  private function getSerializedPlan(ApiCourse $apiCourse)
  {
    $plan = [];

    if (!empty($apiCourse->academicPlan)) {
      foreach ($apiCourse->academicPlan->items as $item) {
        $plan[] = [
          'title' => $item->title,
          'hours' => $item->hours,
          'type' => $item->type->title,
        ];
      }
    }

    return serialize($plan);
  }
}
