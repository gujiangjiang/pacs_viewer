<?php
/**
 * schema/007_mock_acquisitions.php — 模拟服务器摄片记录（mock_acquisitions）
 *
 * 模拟 PACS 完成「摄片」后落库：为该检查分配 StudyInstanceUID 并规划序列，
 * 使其经 DICOMweb 可被检出、取像（模拟设备已完成检查并上传影像）。
 * 与 Clinic 的 imaging_tasks（工作流）对应：本表是影像产生方（PACS）侧账。
 */
if (!isset($pdo) || !($pdo instanceof PDO)) return;

$pdo->exec("CREATE TABLE IF NOT EXISTS mock_acquisitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_ref TEXT DEFAULT '',
    accession_no TEXT DEFAULT '',
    study_uid TEXT DEFAULT '',
    patient_id TEXT DEFAULT '',
    name TEXT DEFAULT '',
    gender TEXT DEFAULT '',
    birth_date TEXT DEFAULT '',
    outpatient_no TEXT DEFAULT '',
    modality TEXT DEFAULT '',
    description TEXT DEFAULT '',
    series_json TEXT DEFAULT '',
    registered_at TEXT DEFAULT '',
    acquired_at TEXT DEFAULT '',
    operator TEXT DEFAULT '',
    created_at TEXT DEFAULT ''
)");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_mock_acq_study ON mock_acquisitions(study_uid)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_mock_acq_accession ON mock_acquisitions(accession_no)");
