<?php

namespace Biz\Task\Strategy;

use Biz\Task\Dao\TaskDao;
use Topxia\Common\ArrayToolkit;
use Biz\Task\Service\TaskService;
use Biz\Course\Dao\CourseChapterDao;
use Biz\Course\Service\CourseService;
use Biz\Task\Service\TaskResultService;
use Codeages\Biz\Framework\Context\Biz;
use Biz\Activity\Service\ActivityService;
use Codeages\Biz\Framework\Service\Exception\AccessDeniedException;
use Codeages\Biz\Framework\Service\Exception\InvalidArgumentException;

class BaseStrategy
{
    /**
     * @var Biz
     */
    protected $biz;

    public function __construct($biz)
    {
        $this->biz = $biz;
    }

    public function createTask($fields)
    {
        $fields = ArrayToolkit::parts($fields, array(
            'courseId',
            'seq',
            'mode',
            'categoryId',
            'activityId',
            'title',
            'type',
            'mediaSource',
            'isFree',
            'isOptional',
            'startTime',
            'endTime',
            'status',
            'createdUserId'
        ));
        return $this->getTaskDao()->create($fields);
    }

    public function updateTask($id, $fields)
    {
        $fields = ArrayToolkit::parts($fields, array(
            'title',
            'isFree',
            'isOptional',
            'startTime',
            'endTime',
            'status',
            'mediaSource'
        ));

        return $this->getTaskDao()->update($id, $fields);
    }

    protected function invalidTask($task)
    {
        if (!ArrayToolkit::requireds($task, array(
            'title',
            'fromCourseId'
        ))
        ) {
            return true;
        }

        return false;
    }

    protected function getTaskService()
    {
        return $this->biz->service('Task:TaskService');
    }

    protected function getTaskDao()
    {
        return $this->biz->dao('Task:TaskDao');
    }

    public function getCourseService()
    {
        return $this->biz->service('Course:CourseService');
    }

    protected function getChapterDao()
    {
        return $this->biz->dao('Course:CourseChapterDao');
    }

    protected function getTaskResultService()
    {
        return $this->biz->service('Task:TaskResultService');
    }

    public function getActivityService()
    {
        return $this->biz->service('Activity:ActivityService');
    }
}
