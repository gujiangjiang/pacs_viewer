<?php
/**
 * schema/008_acquisition_visit.php — 摄片记录补就诊类型 / 住院号
 *
 * PACS 侧按就诊类型动态显示就诊标识：
 *  - visit_type  ：outpatient / emergency / inpatient（来源 FHIR Encounter.class
 *                  或 AN 住院号标识推断）
 *  - inpatient_no：住院号（FHIR 标识 urn:his:identifier:admission，type=AN）
 * 门诊/急诊患者显示「门诊号」（outpatient_no=就诊号），住院显示「住院号」。
 */
if (!isset($pdo) || !($pdo instanceof PDO)) return;

$cols = $pdo->query("PRAGMA table_info(mock_acquisitions)")->fetchAll(PDO::FETCH_ASSOC);
$names = array();
foreach ($cols as $c) { if (isset($c['name'])) $names[$c['name']] = true; }
if (!isset($names['visit_type'])) {
    $pdo->exec("ALTER TABLE mock_acquisitions ADD COLUMN visit_type TEXT DEFAULT ''");
}
if (!isset($names['inpatient_no'])) {
    $pdo->exec("ALTER TABLE mock_acquisitions ADD COLUMN inpatient_no TEXT DEFAULT ''");
}
