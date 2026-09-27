<?php
/**
 * app/Services/StudyService.php — 检查数据聚合服务
 * 将 PACS 接口返回的原始数据规整为阅片器所需的统一结构，并补齐医院名称、
 * 默认窗宽窗位等展示字段。所有患者/检查/医院信息均来源于 PACS 接口。
 */
class PvStudyService {

    /** 检索（附加医院展示名、状态标签） */
    public static function search($keyword) {
        $list = PvPacsClient::search($keyword);
        $site = pvw_hospital();
        foreach ($list as &$row) {
            if (empty($row['institution'])) $row['institution'] = $site;
            $row['status_name'] = '已完成';
        }
        unset($row);
        return $list;
    }

    /** 调阅（补齐展示字段） */
    public static function study($uid) {
        $d = PvPacsClient::study($uid);
        $site = pvw_hospital();
        if (empty($d['study']['institution'])) $d['study']['institution'] = $site;
        if (empty($d['study']['station_name'])) $d['study']['station_name'] = ($d['study']['modality'] . '-ROOM');
        $d['study']['default_ww'] = (int)PvSettings::get('viewer_default_ww', '400');
        $d['study']['default_wl'] = (int)PvSettings::get('viewer_default_wl', '40');
        $d['meta'] = array(
            'source' => PvPacsClient::isRemote() ? 'remote' : 'demo',
            'mode'   => PvPacsClient::mode(),
        );
        return $d;
    }
}
