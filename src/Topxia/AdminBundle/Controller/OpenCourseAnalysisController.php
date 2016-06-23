<?php
namespace Topxia\AdminBundle\Controller;

use Topxia\Common\ArrayToolkit;
use Symfony\Component\HttpFoundation\Request;

class OpenCourseAnalysisController extends BaseController
{
    public function indexAction(Request $request)
    {
        return $this->redirect($this->generateUrl('admin_opencourse_analysis_referer'));
    }

    public function refererAction(Request $request)
    {
        $query      = $request->query->all();
        $timeRange  = $this->getTimeRange($query);
        $conditions = array_merge($timeRange, array('targetType' => 'openCourse'));

        //根据refererHost分组统计数据总数
        $refererlogDatas        = $this->getRefererLogService()->searchAnalysisRefererLogSum($conditions, $groupBy = 'refererHost');
        $refererlogAnalysisList = $this->prepareAnalysisDatas($refererlogDatas);
        $analysisDataNames      = json_encode(ArrayToolkit::column($refererlogAnalysisList, 'name'));
        return $this->render('TopxiaAdminBundle:OpenCourseAnalysis/Referer:index.html.twig', array(
            'dateRange'               => $this->getDataInfo($timeRange),
            'refererlogAnalysisList'  => $refererlogAnalysisList,
            'refererlogAnalysisDatas' => json_encode($refererlogAnalysisList),
            'analysisDataNames'       => $analysisDataNames
        ));
    }

    public function refererListAction(Request $request)
    {
        $query      = $request->query->all();
        $timeRange  = $this->getTimeRange($query);
        $conditions = array_merge($timeRange, array('targetType' => 'openCourse'));

        return $this->render('TopxiaAdminBundle:OpenCourseAnalysis/Referer:list.html.twig', array(
            'dateRange' => $this->getDataInfo($timeRange)
        ));
    }

    private function prepareAnalysisDatas($refererlogDatas)
    {
        if (empty($refererlogDatas)) {
            return array();
        }
        $lenght = 6;

        $analysisDatas      = array_slice($refererlogDatas, 0, $lenght);
        $otherAnalysisDatas = count($refererlogDatas) >= $lenght ? array_slice($refererlogDatas, $lenght) : array();

        $totoalCount      = array_sum(ArrayToolkit::column($refererlogDatas, 'value'));
        $otherTotoalCount = array_sum(ArrayToolkit::column($otherAnalysisDatas, 'value'));

        array_push($analysisDatas, array('value' => $otherTotoalCount, 'name' => '其他'));
        array_walk($analysisDatas, function ($data, $key, $totoalCount) use (&$analysisDatas) {
            $analysisDatas[$key]['percent'] = round($data['value'] / $totoalCount * 100, 2).'%';
        }, $totoalCount);

        return $analysisDatas;
    }

    protected function getDataInfo($timeRange)
    {
        return array(
            'startTime'      => date("Y-m-d", $timeRange['startTime']),
            'endTime'        => date("Y-m-d", $timeRange['endTime']),
            'yesterdayStart' => date("Y-m-d", strtotime(date("Y-m-d", time())) - 24 * 3600),
            'yesterdayEnd'   => date("Y-m-d", strtotime(date("Y-m-d", time()))),
            'lastWeekStart'  => date("Y-m-d", strtotime(date("Y-m-d", strtotime("-1 week")))),
            'lastWeekEnd'    => date("Y-m-d", strtotime(date("Y-m-d", time()))),
            'lastMonthStart' => date("Y-m-d", strtotime(date("Y-m-d", time())) - 30 * 24 * 3600),
            'lastMonthEnd'   => date("Y-m-d", strtotime(date("Y-m-d", time())) - 24 * 3600)
        );
    }

    protected function getTimeRange($fields)
    {
        if (empty($fields['startTime']) && empty($fields['endTime'])) {
            return array('startTime' => strtotime(date("Y-m-d", time())) - 24 * 3600, 'endTime' => strtotime(date("Y-m-d", time())));
        }
        return array('startTime' => strtotime($fields['startTime']), 'endTime' => (strtotime($fields['endTime']) + 24 * 3600));
    }

    protected function getRefererLogService()
    {
        return $this->getServiceKernel()->createService('RefererLog.RefererLogService');
    }
}
