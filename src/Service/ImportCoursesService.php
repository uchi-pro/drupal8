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
    $courses = $this->fetchCourses();

    $this->updateThemes($courses);
    $this->updateCourses($courses);
  }

  private function getConfig()
  {
    return Drupal::config(SettingsForm::SETTINGS);
  }

  /**
   * @return array|ApiCourse[]
   *
   * @throws Exception
   */
  protected function fetchCourses()
  {
    $config = $this->getConfig();

    $url = $config->get('url');
    $login = $config->get('login');
    $password = $config->get('password');
    $accessToken = $config->get('access_token');

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
      $themeId = $node->get('field_theme_id')->getString();
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
    $config = $this->getConfig();

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

    $apiCourse->id = $node->get('field_course_id')->getString();
    $apiCourse->parentId = $node->get('field_course_theme')->entity->get('field_theme_id')->getString();
    $apiCourse->title = $node->get('field_course_title')->getString();
    $apiCourse->price = $node->get('field_course_price')->getString();
    $apiCourse->hours = $node->get('field_course_hours')->getString();

    return $apiCourse;
  }
}
