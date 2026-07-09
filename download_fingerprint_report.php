<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include "koneksi.php";
date_default_timezone_set("Asia/Jakarta");

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// =========================
// PROTEKSI LOGIN
// =========================
$isAdmin = isset($_SESSION['admin_login']) &&
    $_SESSION['admin_login'] === true &&
    strtolower(trim($_SESSION['role'] ?? '')) === 'admin';

$isKaryawan = isset($_SESSION['karyawan_login']) &&
    $_SESSION['karyawan_login'] === true &&
    strtolower(trim($_SESSION['role'] ?? '')) === 'karyawan';

if (!$isAdmin && !$isKaryawan) {
    header("Location: login_karyawan.php");
    exit;
}

// =========================
// VALIDASI PARAMETER
// =========================
$userIdParam = trim($_GET['user_id'] ?? '');
$format      = strtolower(trim($_GET['format'] ?? 'pdf'));
$baseDate    = trim($_GET['base_date'] ?? date('Y-m-d'));

if (!in_array($format, ['pdf', 'excel'], true)) {
    $format = 'pdf';
}

if ($baseDate === '' || strtotime($baseDate) === false) {
    $baseDate = date('Y-m-d');
}

if ($isAdmin) {
    $userId = $userIdParam;
    if ($userId === '') {
        die("Parameter user_id wajib diisi.");
    }
} else {
    $userId = trim($_SESSION['user_id'] ?? '');
    if ($userId === '') {
        die("Session user_id kosong. Silakan login ulang.");
    }
}

$safeUserId = mysqli_real_escape_string($conn, $userId);

// =========================
// HELPER
// =========================
function tableExists(mysqli $conn, string $tableName): bool
{
    $tableEsc = mysqli_real_escape_string($conn, $tableName);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$tableEsc'");
    return $q && mysqli_num_rows($q) > 0;
}

function getPeriode16to15(?string $baseDate = null): array
{
    $baseDate = $baseDate ?: date('Y-m-d');
    $dt = new DateTime($baseDate);
    $day = (int)$dt->format('d');

    if ($day >= 16) {
        $start = new DateTime($dt->format('Y-m-16'));
        $end = new DateTime($start->format('Y-m-15'));
        $end->modify('+1 month');
    } else {
        $start = new DateTime($dt->format('Y-m-16'));
        $start->modify('-1 month');
        $end = new DateTime($start->format('Y-m-15'));
        $end->modify('+1 month');
    }

    return [
        'start' => $start->format('Y-m-d'),
        'end'   => $end->format('Y-m-d'),
    ];
}

function buildTanggalRange(string $startDate, string $endDate): array
{
    $dates = [];
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);

    while ($start <= $end) {
        $dates[] = $start->format('Y-m-d');
        $start->modify('+1 day');
    }

    return $dates;
}

function bulanIndonesia(string $dateYmd): string
{
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    $dt = new DateTime($dateYmd);
    return $bulan[(int)$dt->format('n')] . ' ' . $dt->format('Y');
}

// =========================
// RULE JAM KERJA
// overtime      = 06:00 - 15:00
// non overtime  = 07:00 - 17:00
// toleransi 15 menit
// =========================
function normalizeJenisJamKerja(string $jenisJamKerja): string
{
    $jenis = strtolower(trim($jenisJamKerja));
    $jenis = str_replace(['_', '-'], ' ', $jenis);
    $jenis = preg_replace('/\s+/', ' ', $jenis);

    if ($jenis === 'nonovertime') {
        $jenis = 'non overtime';
    }

    return $jenis;
}

function getWorkTimeByJenisJamKerja(string $jenisJamKerja): array
{
    $jenis = normalizeJenisJamKerja($jenisJamKerja);

    if ($jenis === 'overtime') {
        return [
            'jenis'            => 'overtime',
            'in'               => '06:00',
            'out'              => '15:00',
            'late_tolerance'   => '06:15',
            'early_tolerance'  => '14:45'
        ];
    }

    if ($jenis === 'non overtime') {
        return [
            'jenis'            => 'non overtime',
            'in'               => '07:00',
            'out'              => '17:00',
            'late_tolerance'   => '07:15',
            'early_tolerance'  => '16:45'
        ];
    }

    // default kalau data kosong / aneh
    return [
        'jenis'            => 'non overtime',
        'in'               => '07:00',
        'out'              => '17:00',
        'late_tolerance'   => '07:15',
        'early_tolerance'  => '16:45'
    ];
}

function normalizeJam(string $jam): string
{
    $jam = trim($jam);
    if ($jam === '') return '';

    // ubah 06:00:00 -> 06:00
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam)) {
        return substr($jam, 0, 5);
    }

    // kalau sudah HH:MM
    if (preg_match('/^\d{2}:\d{2}$/', $jam)) {
        return $jam;
    }

    return $jam;
}

function hitungLate(string $jamMasukReal, string $jamMasukWork, int $toleransiMenit = 15): string
{
    $jamMasukReal = normalizeJam($jamMasukReal);
    $jamMasukWork = normalizeJam($jamMasukWork);

    if ($jamMasukReal === '' || $jamMasukWork === '') return '';

    $real = strtotime($jamMasukReal);
    $work = strtotime($jamMasukWork);

    if ($real === false || $work === false) return '';

    $batas = $work + ($toleransiMenit * 60);

    if ($real <= $batas) return '';

    $selisih = $real - $work;
    return gmdate('H:i', $selisih);
}

function hitungEarlyOut(string $jamKeluarReal, string $jamKeluarWork, int $toleransiMenit = 15): string
{
    $jamKeluarReal = normalizeJam($jamKeluarReal);
    $jamKeluarWork = normalizeJam($jamKeluarWork);

    if ($jamKeluarReal === '' || $jamKeluarWork === '') return '';

    $real = strtotime($jamKeluarReal);
    $work = strtotime($jamKeluarWork);

    if ($real === false || $work === false) return '';

    $batas = $work - ($toleransiMenit * 60);

    if ($real >= $batas) return '';

    $selisih = $work - $real;
    return gmdate('H:i', $selisih);
}

function getRemarksFromRow(array $r): string
{
    $status = strtolower(trim($r['status'] ?? ''));
    $keterangan = trim($r['keterangan'] ?? '');
    $tanggal = trim($r['tanggal'] ?? '');

    if ($tanggal !== '' && strtotime($tanggal) !== false) {
        $dayNum = date('w', strtotime($tanggal));
        if ((int)$dayNum === 0) return 'Sunday';
    }

    if ($status === 'cuti') return 'Leave';
    if ($status === 'izin') return 'Permission';
    if ($status === 'sakit') return 'Sick';
    if ($status === 'lembur') return 'Overtime';
    if ($status === 'shift') return 'Shift';
    if ($status === 'hadir') return '';

    return $keterangan !== '' ? $keterangan : '';
}

// =========================
// CARI TABEL ABSENSI USER
// =========================
$tabelAbsensi = '';

$qCekAdmin = mysqli_query($conn, "SELECT 1 FROM admin_users WHERE TRIM(user_id)=TRIM('$safeUserId') LIMIT 1");
$qCekKar   = mysqli_query($conn, "SELECT 1 FROM karyawan_users WHERE TRIM(user_id)=TRIM('$safeUserId') LIMIT 1");

if ($qCekAdmin && mysqli_num_rows($qCekAdmin) > 0) {
    $tabelAbsensi = 'admin_users';
} elseif ($qCekKar && mysqli_num_rows($qCekKar) > 0) {
    $tabelAbsensi = 'karyawan_users';
} else {
    if (tableExists($conn, 'karyawan_users')) {
        $tabelAbsensi = 'karyawan_users';
    } elseif (tableExists($conn, 'admin_users')) {
        $tabelAbsensi = 'admin_users';
    } else {
        die("Tabel absensi tidak ditemukan.");
    }
}

// =========================
// AMBIL PROFIL USER
// =========================
$qUserProfile = mysqli_query($conn, "
    SELECT 
        user_id,
        COALESCE(name, '') AS name,
        COALESCE(position, '') AS position,
        COALESCE(project, '') AS project,
        COALESCE(jenis_jam_kerja, '') AS jenis_jam_kerja,
        COALESCE(role, '') AS role
    FROM users
    WHERE TRIM(user_id)=TRIM('$safeUserId')
    LIMIT 1
");

$userProfile = ($qUserProfile && mysqli_num_rows($qUserProfile) > 0)
    ? mysqli_fetch_assoc($qUserProfile)
    : [];

$reportName       = trim($userProfile['name'] ?? '');
$reportPosition   = trim($userProfile['position'] ?? '');
$reportDepartemen = '-';
$reportProject    = trim($userProfile['project'] ?? '');
$reportJenisJam   = trim($userProfile['jenis_jam_kerja'] ?? '');
$reportBadge      = $userId;

if ($reportName === '') $reportName = $userId;
if ($reportPosition === '') $reportPosition = '-';
if ($reportDepartemen === '') $reportDepartemen = '-';
if ($reportProject === '') $reportProject = '-';
if ($reportJenisJam === '') $reportJenisJam = 'non overtime';
if ($reportBadge === '') $reportBadge = $userId;

$workTime = getWorkTimeByJenisJamKerja($reportJenisJam);

// =========================
// PERIODE
// =========================
$periode = getPeriode16to15($baseDate);
$periodeStart = $periode['start'];
$periodeEnd   = $periode['end'];
$tanggalRange = buildTanggalRange($periodeStart, $periodeEnd);

$bulanReport = bulanIndonesia($periodeStart);
$datePeriode = date('d/m/Y', strtotime($periodeStart)) . ' - ' . date('d/m/Y', strtotime($periodeEnd));

// =========================
// DATA ABSENSI PERIODE
// =========================
$qDataPeriode = mysqli_query($conn, "
    SELECT 
        tanggal,
        user_id,
        nama,
        jam_masuk,
        jam_keluar,
        status,
        keterangan,
        mac_address
    FROM $tabelAbsensi
    WHERE TRIM(user_id)=TRIM('$safeUserId')
      AND tanggal BETWEEN '$periodeStart' AND '$periodeEnd'
    ORDER BY tanggal ASC, id ASC
");

if (!$qDataPeriode) {
    die("SQL Error: " . mysqli_error($conn));
}

$mapRows = [];
while ($row = mysqli_fetch_assoc($qDataPeriode)) {
    $tgl = trim($row['tanggal'] ?? '');
    if ($tgl !== '') {
        $mapRows[$tgl] = $row;
    }
}

$rowsPeriode = [];
foreach ($tanggalRange as $tgl) {
    $rowsPeriode[] = $mapRows[$tgl] ?? [
        'tanggal'     => $tgl,
        'user_id'     => $userId,
        'nama'        => $reportName,
        'jam_masuk'   => '',
        'jam_keluar'  => '',
        'status'      => '',
        'keterangan'  => '',
        'mac_address' => '',
    ];
}

// =========================
// EXPORT EXCEL
// =========================
if ($format === 'excel') {
    $filename = "employee_fingerprint_report_" . preg_replace('/[^A-Za-z0-9_-]/', '', $userId) . "_" . date("Ymd_His") . ".xlsx";

    while (ob_get_level()) ob_end_clean();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Fingerprint Report');

    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(24);
    $sheet->getColumnDimension('C')->setWidth(10);
    $sheet->getColumnDimension('D')->setWidth(14);
    $sheet->getColumnDimension('E')->setWidth(14);
    $sheet->getColumnDimension('F')->setWidth(18);
    $sheet->getColumnDimension('G')->setWidth(24);
    $sheet->getColumnDimension('H')->setWidth(14);
    $sheet->getColumnDimension('I')->setWidth(14);
    $sheet->getColumnDimension('J')->setWidth(24);

    $logoPath = __DIR__ . '/assets/logo-meindo.png';
    if (file_exists($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(62);
        $drawing->setCoordinates('B1');
        $drawing->setOffsetX(12);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    }

    $sheet->mergeCells('C2:I2');
    $sheet->setCellValue('C2', 'EMPLOYEE FINGERPRINT REPORT');
    $sheet->getStyle('C2')->getFont()->setBold(true)->setSize(26);
    $sheet->getStyle('C2')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(2)->setRowHeight(34);

    $sheet->mergeCells('B5:D5');
    $sheet->mergeCells('G5:I5');
    $sheet->mergeCells('B6:D6');
    $sheet->mergeCells('G6:I6');
    $sheet->mergeCells('B7:D7');
    $sheet->mergeCells('G7:I7');
    $sheet->mergeCells('B8:D8');
    $sheet->mergeCells('G8:I8');

    $sheet->setCellValue('A5', 'NAME');
    $sheet->setCellValue('B5', ': ' . $reportName);

    $sheet->setCellValue('F5', 'PROJECT');
    $sheet->setCellValue('G5', ': ' . $reportProject);

    $sheet->setCellValue('A6', 'POSITION');
    $sheet->setCellValue('B6', ': ' . $reportPosition);

    $sheet->setCellValue('F6', 'MONTH');
    $sheet->setCellValue('G6', ': ' . $bulanReport);

    $sheet->setCellValue('A7', 'DEPARTEMENT');
    $sheet->setCellValue('B7', ': ' . $reportDepartemen);

    $sheet->setCellValue('F7', 'DATE PERIODE');
    $sheet->setCellValue('G7', ': ' . $datePeriode);

    $sheet->setCellValue('A8', 'NO. BADGE');
    $sheet->setCellValue('B8', ': ' . $reportBadge);

    $sheet->setCellValue('F8', 'TIMESHEET');
    $sheet->setCellValue('G8', ': ' . $workTime['jenis']);

    $sheet->getStyle('A5:A8')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('F5:F8')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('B5:I8')->getFont()->setSize(14);

    $sheet->getStyle('A5:I8')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle('B5:D8')->getAlignment()->setWrapText(true);
    $sheet->getStyle('G5:I8')->getAlignment()->setWrapText(true);

    $sheet->getRowDimension(5)->setRowHeight(24);
    $sheet->getRowDimension(6)->setRowHeight(24);
    $sheet->getRowDimension(7)->setRowHeight(24);
    $sheet->getRowDimension(8)->setRowHeight(24);

    $sheet->mergeCells('A10:A11');
    $sheet->mergeCells('B10:C10');
    $sheet->mergeCells('D10:D11');
    $sheet->mergeCells('E10:F10');
    $sheet->mergeCells('G10:G11');
    $sheet->mergeCells('H10:H11');
    $sheet->mergeCells('I10:I11');
    $sheet->mergeCells('J10:J11');

    $sheet->setCellValue('A10', 'Tanggal');
    $sheet->setCellValue('B10', 'WORK TIME');
    $sheet->setCellValue('D10', 'SIGN');
    $sheet->setCellValue('E10', 'REAL TIME');
    $sheet->setCellValue('G10', 'SIGN');
    $sheet->setCellValue('H10', 'LATE');
    $sheet->setCellValue('I10', 'EARLY OUT');
    $sheet->setCellValue('J10', 'REMARKS');

    $sheet->setCellValue('B11', 'IN');
    $sheet->setCellValue('C11', 'OUT');
    $sheet->setCellValue('E11', 'IN');
    $sheet->setCellValue('F11', 'OUT');

    $sheet->getStyle('A10:J11')->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'EFEFEF']
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ]
    ]);

    $sheet->getRowDimension(10)->setRowHeight(28);
    $sheet->getRowDimension(11)->setRowHeight(24);

    $rowExcel = 12;
    foreach ($rowsPeriode as $r) {
        $realIn   = normalizeJam($r['jam_masuk'] ?? '');
        $realOut  = normalizeJam($r['jam_keluar'] ?? '');
        $late     = hitungLate($realIn, $workTime['in'], 15);
        $earlyOut = hitungEarlyOut($realOut, $workTime['out'], 15);
        $remarks  = getRemarksFromRow($r);

        $sheet->setCellValue('A' . $rowExcel, date('m/d/Y', strtotime($r['tanggal'])));
        $sheet->setCellValue('B' . $rowExcel, $workTime['in']);
        $sheet->setCellValue('C' . $rowExcel, $workTime['out']);
        $sheet->setCellValue('D' . $rowExcel, '');
        $sheet->setCellValue('E' . $rowExcel, $realIn);
        $sheet->setCellValue('F' . $rowExcel, $realOut);
        $sheet->setCellValue('G' . $rowExcel, '');
        $sheet->setCellValue('H' . $rowExcel, $late);
        $sheet->setCellValue('I' . $rowExcel, $earlyOut);
        $sheet->setCellValue('J' . $rowExcel, $remarks);

        $sheet->getStyle("A{$rowExcel}:J{$rowExcel}")->applyFromArray([
            'font' => ['size' => 12],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);

        $sheet->getStyle("J{$rowExcel}")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($rowExcel)->setRowHeight(24);

        $rowExcel++;
    }

    $ttdTitleRow = $rowExcel + 4;
    $ttdNameRow  = $ttdTitleRow + 5;
    $ttdRoleRow  = $ttdNameRow + 1;

    $sheet->mergeCells("B{$ttdTitleRow}:C{$ttdTitleRow}");
    $sheet->mergeCells("B{$ttdNameRow}:C{$ttdNameRow}");
    $sheet->mergeCells("B{$ttdRoleRow}:C{$ttdRoleRow}");

    $sheet->mergeCells("E{$ttdTitleRow}:F{$ttdTitleRow}");
    $sheet->mergeCells("E{$ttdNameRow}:F{$ttdNameRow}");
    $sheet->mergeCells("E{$ttdRoleRow}:F{$ttdRoleRow}");

    $sheet->mergeCells("H{$ttdTitleRow}:I{$ttdTitleRow}");
    $sheet->mergeCells("H{$ttdNameRow}:I{$ttdNameRow}");
    $sheet->mergeCells("H{$ttdRoleRow}:I{$ttdRoleRow}");

    $sheet->setCellValue("B{$ttdTitleRow}", 'Checked by,');
    $sheet->setCellValue("E{$ttdTitleRow}", 'Checked by,');
    $sheet->setCellValue("H{$ttdTitleRow}", 'Checked by,');

    $sheet->setCellValue("B{$ttdNameRow}", 'Aditya Kurniawan');
    $sheet->setCellValue("E{$ttdNameRow}", 'Ifkar Amir');
    $sheet->setCellValue("H{$ttdNameRow}", 'Johar Edi S');

    $sheet->setCellValue("B{$ttdRoleRow}", 'ADMIN / HR');
    $sheet->setCellValue("E{$ttdRoleRow}", 'MANAGER - HR');
    $sheet->setCellValue("H{$ttdRoleRow}", 'MANAGER - CONST');

    $sheet->getStyle("B{$ttdTitleRow}:I{$ttdRoleRow}")->applyFromArray([
        'font' => ['size' => 13],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);

    $sheet->getRowDimension($ttdTitleRow)->setRowHeight(24);
    $sheet->getRowDimension($ttdNameRow)->setRowHeight(24);
    $sheet->getRowDimension($ttdRoleRow)->setRowHeight(22);

    $sheet->getPageSetup()->setPrintArea("A1:J{$ttdRoleRow}");
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);

    $sheet->getPageMargins()->setTop(0.25);
    $sheet->getPageMargins()->setRight(0.2);
    $sheet->getPageMargins()->setLeft(0.2);
    $sheet->getPageMargins()->setBottom(0.25);

    $sheet->freezePane('A12');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// =========================
// EXPORT PDF
// =========================
require_once __DIR__ . "/fpdf/fpdf.php";

while (ob_get_level()) ob_end_clean();

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

$logoPath = __DIR__ . "/assets/logo-meindo.png";
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 14, 10, 35);
}

$pdf->SetFont('Arial', 'B', 14);
$pdf->SetXY(0, 15);
$pdf->Cell(0, 8, 'EMPLOYEE FINGERPRINT REPORT', 0, 1, 'C');
$pdf->Line(58, 24, 152, 24);

$leftX = 17;
$rightX = 101;
$yInfo = 30;

$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($leftX, $yInfo);
$pdf->Cell(24, 6, 'NAME', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(36, 6, $reportName, 0, 0, 'L');

$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($rightX, $yInfo);
$pdf->Cell(25, 6, 'PROJECT', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(0, 6, $reportProject, 0, 1, 'L');

$yInfo += 5.5;
$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($leftX, $yInfo);
$pdf->Cell(24, 6, 'POSITION', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(36, 6, $reportPosition, 0, 0, 'L');

$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($rightX, $yInfo);
$pdf->Cell(25, 6, 'MONTH', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(0, 6, $bulanReport, 0, 1, 'L');

$yInfo += 5.5;
$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($leftX, $yInfo);
$pdf->Cell(24, 6, 'DEPARTEMENT', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(36, 6, $reportDepartemen, 0, 0, 'L');

$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($rightX, $yInfo);
$pdf->Cell(25, 6, 'DATE PERIODE', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(0, 6, $datePeriode, 0, 1, 'L');

$yInfo += 5.5;
$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($leftX, $yInfo);
$pdf->Cell(24, 6, 'NO. BADGE', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(36, 6, $reportBadge, 0, 0, 'L');

$pdf->SetFont('Arial', 'B', 8.7);
$pdf->SetXY($rightX, $yInfo);
$pdf->Cell(25, 6, 'TIMESHEET', 0, 0, 'L');
$pdf->Cell(4, 6, ':', 0, 0, 'C');
$pdf->SetFont('Arial', '', 8.7);
$pdf->Cell(0, 6, $workTime['jenis'], 0, 1, 'L');

$wTanggal = 22;
$wWorkIn  = 14;
$wWorkOut = 14;
$wSign1   = 12;
$wRealIn  = 14;
$wRealOut = 17;
$wSign2   = 12;
$wLate    = 12;
$wEarly   = 17;
$wRemarks = 30;

$totalTableWidth = $wTanggal + $wWorkIn + $wWorkOut + $wSign1 + $wRealIn + $wRealOut + $wSign2 + $wLate + $wEarly + $wRemarks;
$tableX = (210 - $totalTableWidth) / 2;
$tableY = 60;

$headerH1 = 8;
$headerH2 = 6;
$rowH = 5.05;

$pdf->SetFont('Arial', 'B', 6.9);

$pdf->SetXY($tableX, $tableY);
$pdf->Cell($wTanggal, $headerH1 + $headerH2, 'Tanggal', 1, 0, 'C');
$pdf->Cell($wWorkIn + $wWorkOut, $headerH1, 'WORK TIME', 1, 0, 'C');
$pdf->Cell($wSign1, $headerH1 + $headerH2, 'SIGN', 1, 0, 'C');
$pdf->Cell($wRealIn + $wRealOut, $headerH1, 'REAL TIME', 1, 0, 'C');
$pdf->Cell($wSign2, $headerH1 + $headerH2, 'SIGN', 1, 0, 'C');
$pdf->Cell($wLate, $headerH1 + $headerH2, 'LATE', 1, 0, 'C');
$pdf->Cell($wEarly, $headerH1 + $headerH2, 'EARLY OUT', 1, 0, 'C');
$pdf->Cell($wRemarks, $headerH1 + $headerH2, 'REMARKS', 1, 1, 'C');

$pdf->SetXY($tableX + $wTanggal, $tableY + $headerH1);
$pdf->Cell($wWorkIn, $headerH2, 'IN', 1, 0, 'C');
$pdf->Cell($wWorkOut, $headerH2, 'OUT', 1, 0, 'C');

$pdf->SetXY($tableX + $wTanggal + $wWorkIn + $wWorkOut + $wSign1, $tableY + $headerH1);
$pdf->Cell($wRealIn, $headerH2, 'IN', 1, 0, 'C');
$pdf->Cell($wRealOut, $headerH2, 'OUT', 1, 1, 'C');

$pdf->SetFont('Arial', '', 6.15);
$y = $tableY + $headerH1 + $headerH2;

foreach ($rowsPeriode as $r) {
    $realIn   = normalizeJam($r['jam_masuk'] ?? '');
    $realOut  = normalizeJam($r['jam_keluar'] ?? '');
    $late     = hitungLate($realIn, $workTime['in'], 15);
    $earlyOut = hitungEarlyOut($realOut, $workTime['out'], 15);
    $remarks  = getRemarksFromRow($r);

    $x = $tableX;
    $pdf->SetXY($x, $y);
    $pdf->Cell($wTanggal, $rowH, date('m/d/Y', strtotime($r['tanggal'])), 1, 0, 'C'); $x += $wTanggal;
    $pdf->Cell($wWorkIn, $rowH, $workTime['in'], 1, 0, 'C'); $x += $wWorkIn;
    $pdf->Cell($wWorkOut, $rowH, $workTime['out'], 1, 0, 'C'); $x += $wWorkOut;
    $pdf->Cell($wSign1, $rowH, '', 1, 0, 'C'); $x += $wSign1;
    $pdf->Cell($wRealIn, $rowH, $realIn, 1, 0, 'C'); $x += $wRealIn;
    $pdf->Cell($wRealOut, $rowH, $realOut, 1, 0, 'C'); $x += $wRealOut;
    $pdf->Cell($wSign2, $rowH, '', 1, 0, 'C'); $x += $wSign2;
    $pdf->Cell($wLate, $rowH, $late, 1, 0, 'C'); $x += $wLate;
    $pdf->Cell($wEarly, $rowH, $earlyOut, 1, 0, 'C'); $x += $wEarly;
    $pdf->Cell($wRemarks, $rowH, $remarks, 1, 1, 'C');

    $y += $rowH;
}

$ttdY = 244;
$pdf->SetY($ttdY);
$pdf->SetFont('Arial', '', 7.6);

$pdf->Cell(63, 4, 'Checked by,', 0, 0, 'C');
$pdf->Cell(63, 4, 'Checked by,', 0, 0, 'C');
$pdf->Cell(63, 4, 'Checked by,', 0, 1, 'C');

$pdf->Ln(10);

$pdf->Cell(63, 4, 'Aditya Kurniawan', 0, 0, 'C');
$pdf->Cell(63, 4, 'Ifkar Amir', 0, 0, 'C');
$pdf->Cell(63, 4, 'Johar Edi S', 0, 1, 'C');

$pdf->Cell(63, 4, 'ADMIN / HR', 0, 0, 'C');
$pdf->Cell(63, 4, 'MANAGER - HR', 0, 0, 'C');
$pdf->Cell(63, 4, 'MANAGER - CONST', 0, 1, 'C');

$filename = "employee_fingerprint_report_" . preg_replace('/[^A-Za-z0-9_-]/', '', $userId) . "_" . date("Ymd_His") . ".pdf";
$pdf->Output('D', $filename);
exit;