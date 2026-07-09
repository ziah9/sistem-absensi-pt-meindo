<?php // membuka tag PHP

if (session_status() === PHP_SESSION_NONE) session_start(); // cek apakah session belum aktif, jika belum maka mulai session
include "koneksi.php"; // memanggil file koneksi database
date_default_timezone_set("Asia/Jakarta"); // mengatur zona waktu ke Asia/Jakarta (WIB)

// =========================
// PROTEKSI HALAMAN (ADMIN)
// =========================
if (!isset($_SESSION['admin_login']) || $_SESSION['admin_login'] !== true) { // cek apakah session admin_login tidak ada atau nilainya bukan true
    header("Location: login_admin.php"); // kalau belum login admin, arahkan ke halaman login_admin.php
    exit; // hentikan eksekusi program
}
if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'admin') { // cek apakah role tidak ada atau bukan admin
    session_destroy(); // hapus session jika role tidak valid
    header("Location: login_admin.php"); // arahkan lagi ke login admin
    exit; // hentikan program
}

// =========================
// SETTING CUT OFF ABSENSI
// =========================
$cutoffMulai   = 16; // tanggal mulai periode absensi, yaitu tanggal 16
$cutoffSelesai = 15; // tanggal akhir periode absensi, yaitu tanggal 15 bulan berikutnya

// =========================
// HELPER UMUM
// =========================
function namaBulan($b) { // function untuk mengubah angka bulan menjadi nama bulan Indonesia
    $arr = [ // array pasangan angka bulan dan nama bulan
        1 => "Januari", // bulan 1 = Januari
        2 => "Februari", // bulan 2 = Februari
        3 => "Maret", // bulan 3 = Maret
        4 => "April", // bulan 4 = April
        5 => "Mei", // bulan 5 = Mei
        6 => "Juni", // bulan 6 = Juni
        7 => "Juli", // bulan 7 = Juli
        8 => "Agustus", // bulan 8 = Agustus
        9 => "September", // bulan 9 = September
        10 => "Oktober", // bulan 10 = Oktober
        11 => "November", // bulan 11 = November
        12 => "Desember" // bulan 12 = Desember
    ];
    return $arr[$b] ?? $b; // kembalikan nama bulan jika ada, kalau tidak ada kembalikan nilai aslinya
}

function getPeriodeCutoff($bulan, $tahun, $cutoffMulai = 16, $cutoffSelesai = 15) { // function untuk membuat periode absensi dari tgl 16 sampai tgl 15 bulan berikutnya
    $bulan = (int)$bulan; // ubah nilai bulan menjadi integer
    $tahun = (int)$tahun; // ubah nilai tahun menjadi integer

    $tanggalAwal = sprintf('%04d-%02d-%02d', $tahun, $bulan, $cutoffMulai); // buat tanggal awal format YYYY-MM-DD

    $nextMonth = $bulan + 1; // hitung bulan berikutnya
    $nextYear  = $tahun; // default tahun berikutnya sama dengan tahun sekarang

    if ($nextMonth > 12) { // kalau bulan berikutnya lebih dari 12
        $nextMonth = 1; // ubah menjadi Januari
        $nextYear++; // tahun bertambah 1
    }

    $tanggalAkhir = sprintf('%04d-%02d-%02d', $nextYear, $nextMonth, $cutoffSelesai); // buat tanggal akhir format YYYY-MM-DD

    return [$tanggalAwal, $tanggalAkhir]; // kembalikan array berisi tanggal awal dan tanggal akhir
}

function formatPeriodeLabelBulan($bulan, $tahun, $cutoffMulai = 16, $cutoffSelesai = 15) { // function untuk membuat label tampilan periode
    list($awal, $akhir) = getPeriodeCutoff($bulan, $tahun, $cutoffMulai, $cutoffSelesai); // ambil tanggal awal dan akhir periode
    return namaBulan((int)$bulan) . " " . $tahun . " (" . date('d/m/Y', strtotime($awal)) . " - " . date('d/m/Y', strtotime($akhir)) . ")"; // hasil contoh: April 2026 (16/04/2026 - 15/05/2026)
}

function statusAksiAbsensi($jamMasuk, $jamKeluar) { // function untuk menentukan status aksi absensi berdasarkan jam masuk dan jam keluar
    $jamMasuk  = trim((string)$jamMasuk); // ubah jam masuk menjadi string lalu hapus spasi
    $jamKeluar = trim((string)$jamKeluar); // ubah jam keluar menjadi string lalu hapus spasi

    if ($jamMasuk === '') { // kalau jam masuk kosong
        return ['label' => 'Belum Absen', 'class' => 'aksi-belum', 'key' => 'belum_absen']; // status belum absen
    }

    if ($jamMasuk !== '' && $jamKeluar === '') { // kalau jam masuk ada tapi jam keluar kosong
        return ['label' => 'Sudah Masuk', 'class' => 'aksi-masuk', 'key' => 'sudah_masuk']; // status sudah masuk
    }

    return ['label' => 'Sudah Lengkap', 'class' => 'aksi-lengkap', 'key' => 'sudah_lengkap']; // kalau masuk dan keluar ada maka status lengkap
}

function isTerlambat($jamMasuk, $batasTerlambat = '07:15:00') { // function untuk cek apakah jam masuk terlambat
    $jamMasuk = trim((string)$jamMasuk); // rapikan jam masuk
    if ($jamMasuk === '') return false; // kalau kosong, dianggap tidak terlambat

    $tsMasuk = strtotime($jamMasuk); // ubah jam masuk menjadi timestamp
    $tsBatas = strtotime($batasTerlambat); // ubah batas terlambat menjadi timestamp

    if ($tsMasuk === false || $tsBatas === false) return false; // kalau gagal diubah, return false

    return $tsMasuk > $tsBatas; // true kalau jam masuk lebih besar dari batas
}

function lolosFilterStatus($jamMasuk, $jamKeluar, $statusFilter) { // function untuk mengecek data apakah lolos filter status
    $jamMasuk  = trim((string)$jamMasuk); // rapikan jam masuk
    $jamKeluar = trim((string)$jamKeluar); // rapikan jam keluar

    switch ($statusFilter) { // percabangan berdasarkan filter yang dipilih
        case 'sudah_absen': // kalau filter sudah_absen
            return $jamMasuk !== ''; // lolos jika jam masuk tidak kosong
        case 'belum_absen': // kalau filter belum_absen
            return $jamMasuk === ''; // lolos jika jam masuk kosong
        case 'sudah_masuk': // kalau filter sudah_masuk
            return $jamMasuk !== '' && $jamKeluar === ''; // lolos jika sudah masuk tapi belum pulang
        case 'sudah_lengkap': // kalau filter sudah_lengkap
            return $jamMasuk !== '' && $jamKeluar !== ''; // lolos jika masuk dan pulang sudah terisi
        case 'semua': // kalau filter semua
        default: // kondisi default
            return true; // semua data lolos
    }
}

// =========================
// HELPER DATABASE DINAMIS
// =========================
function tableExists($conn, $tableName) { // function cek apakah tabel ada di database
    $tableEsc = mysqli_real_escape_string($conn, $tableName); // amankan nama tabel
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$tableEsc'"); // query cek tabel
    return $q && mysqli_num_rows($q) > 0; // return true jika tabel ada
}

function getTableColumns($conn, $tableName) { // function ambil semua nama kolom dari sebuah tabel
    static $cache = []; // variabel static agar hasil bisa disimpan sementara dan tidak query berulang

    if (isset($cache[$tableName])) { // kalau kolom tabel sudah pernah diambil
        return $cache[$tableName]; // langsung pakai dari cache
    }

    $columns = []; // siapkan array kosong
    if (!tableExists($conn, $tableName)) { // kalau tabel tidak ada
        $cache[$tableName] = $columns; // simpan hasil kosong ke cache
        return $columns; // return array kosong
    }

    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$tableName`"); // query tampilkan kolom tabel
    if ($q) { // kalau query berhasil
        while ($row = mysqli_fetch_assoc($q)) { // loop tiap kolom
            $columns[] = $row['Field']; // simpan nama kolom
        }
    }

    $cache[$tableName] = $columns; // simpan hasil kolom ke cache
    return $columns; // return array nama kolom
}

function pickFirstExistingColumn($columns, $candidates) { // function memilih kolom pertama yang ada dari daftar kandidat
    foreach ($candidates as $col) { // loop semua kandidat nama kolom
        if (in_array($col, $columns, true)) { // cek apakah kandidat ada di daftar kolom
            return $col; // kalau ada, langsung return nama kolom tersebut
        }
    }
    return null; // kalau tidak ada satupun, return null
}

function buildIdentityCondition($conn, $tableName, $userId, $nama) { // function membangun kondisi WHERE berdasarkan user_id atau nama
    $columns = getTableColumns($conn, $tableName); // ambil kolom tabel
    $parts = []; // array penampung potongan query

    $userIdCol = pickFirstExistingColumn($columns, ['user_id', 'userid', 'id_karyawan', 'id_user', 'badge']); // cari nama kolom user id yang cocok
    $namaCol   = pickFirstExistingColumn($columns, ['nama', 'nama_karyawan', 'nama_pegawai', 'username', 'name']); // cari nama kolom nama yang cocok

    $userIdEsc = mysqli_real_escape_string($conn, trim((string)$userId)); // amankan user id
    $namaEsc   = mysqli_real_escape_string($conn, trim((string)$nama)); // amankan nama

    if ($userIdCol && $userIdEsc !== '') { // kalau kolom user id ada dan nilainya tidak kosong
        $parts[] = "TRIM(`$userIdCol`) = TRIM('$userIdEsc')"; // tambahkan kondisi pencocokan user id
    }

    if ($namaCol && $namaEsc !== '') { // kalau kolom nama ada dan nilainya tidak kosong
        $parts[] = "`$namaCol` LIKE '%$namaEsc%'"; // tambahkan kondisi pencarian nama
    }

    if (empty($parts)) { // kalau tidak ada kondisi sama sekali
        return "1=0"; // return false query agar tidak mengambil semua data
    }

    return '(' . implode(' OR ', $parts) . ')'; // gabungkan kondisi dengan OR
}

function buildSingleDateCondition($conn, $tableName, $columns, $tanggal) { // function membuat kondisi tanggal untuk satu hari tertentu
    $tanggalEsc = mysqli_real_escape_string($conn, trim((string)$tanggal)); // amankan tanggal
    $parts = []; // array penampung kondisi

    foreach (['tanggal', 'tgl', 'tanggal_izin', 'tgl_izin', 'tanggal_sakit', 'tgl_sakit', 'tanggal_pengajuan'] as $col) { // loop daftar kemungkinan nama kolom tanggal
        if (in_array($col, $columns, true)) { // kalau kolom itu ada
            $parts[] = "`$col` = '$tanggalEsc'"; // tambahkan kondisi tanggal sama persis
        }
    }

    $startCol = pickFirstExistingColumn($columns, ['tanggal_mulai', 'tgl_mulai', 'mulai', 'start_date', 'dari_tanggal']); // cari kolom tanggal mulai
    $endCol   = pickFirstExistingColumn($columns, ['tanggal_selesai', 'tgl_selesai', 'selesai', 'end_date', 'sampai_tanggal']); // cari kolom tanggal selesai

    if ($startCol && $endCol) { // kalau ada kolom mulai dan selesai
        $parts[] = "'$tanggalEsc' BETWEEN `$startCol` AND `$endCol`"; // tambahkan kondisi tanggal berada di rentang
    }

    if ($startCol && !$endCol) { // kalau hanya ada kolom mulai
        $parts[] = "`$startCol` = '$tanggalEsc'"; // samakan dengan tanggal mulai
    }

    if (!$startCol && $endCol) { // kalau hanya ada kolom selesai
        $parts[] = "`$endCol` = '$tanggalEsc'"; // samakan dengan tanggal selesai
    }

    if (empty($parts)) { // kalau tidak ada kondisi tanggal
        return "1=0"; // return false query
    }

    return '(' . implode(' OR ', $parts) . ')'; // gabungkan semua kondisi tanggal dengan OR
}

function getShiftValue($conn, $userId, $nama, $tanggal) { // function ambil nilai shift dari beberapa kemungkinan tabel
    $shiftTables = ['shift_karyawan', 'jadwal_shift', 'shift_users', 'master_shift']; // daftar nama tabel shift
    foreach ($shiftTables as $tableName) { // loop semua tabel shift
        if (!tableExists($conn, $tableName)) continue; // kalau tabel tidak ada, lanjut ke tabel berikutnya

        $columns = getTableColumns($conn, $tableName); // ambil kolom tabel shift
        $shiftCol = pickFirstExistingColumn($columns, ['shift', 'nama_shift', 'kode_shift', 'jam_shift']); // cari kolom yang menyimpan shift
        if (!$shiftCol) continue; // kalau tidak ada kolom shift, lanjut

        $whereIdentity = buildIdentityCondition($conn, $tableName, $userId, $nama); // buat kondisi identitas user
        $whereTanggal  = buildSingleDateCondition($conn, $tableName, $columns, $tanggal); // buat kondisi tanggal

        $sql = "
            SELECT `$shiftCol` AS nilai_shift
            FROM `$tableName`
            WHERE $whereIdentity
              AND $whereTanggal
            LIMIT 1
        "; // query ambil 1 nilai shift

        $q = mysqli_query($conn, $sql); // jalankan query
        if ($q && mysqli_num_rows($q) > 0) { // kalau data ditemukan
            $d = mysqli_fetch_assoc($q); // ambil row
            $val = trim((string)($d['nilai_shift'] ?? '')); // ambil nilai shift
            if ($val !== '') return $val; // kalau tidak kosong, return shift
        }
    }

    return '-'; // kalau tidak ketemu shift, return tanda -
}

function adaDataPadaTanggal($conn, $tableName, $userId, $nama, $tanggal) { // function cek apakah ada data user di tabel tertentu pada tanggal tertentu
    if (!tableExists($conn, $tableName)) return false; // kalau tabel tidak ada, return false

    $columns = getTableColumns($conn, $tableName); // ambil kolom
    if (empty($columns)) return false; // kalau kolom kosong, return false

    $whereIdentity = buildIdentityCondition($conn, $tableName, $userId, $nama); // buat kondisi identitas user
    $whereTanggal  = buildSingleDateCondition($conn, $tableName, $columns, $tanggal); // buat kondisi tanggal

    $sql = "
        SELECT 1
        FROM `$tableName`
        WHERE $whereIdentity
          AND $whereTanggal
        LIMIT 1
    "; // query cek apakah ada data

    $q = mysqli_query($conn, $sql); // jalankan query
    return $q && mysqli_num_rows($q) > 0; // return true kalau ada minimal 1 data
}

function ambilStatusTambahan($conn, $userId, $nama, $tanggal) { // function ambil status tambahan: izin, sakit, cuti, shift
    $hasil = [
        'izin'   => '-', // default izin belum ada
        'sakit'  => '-', // default sakit belum ada
        'cuti'   => '-', // default cuti belum ada
        'shift'  => '-' // default shift belum ada
    ];

    if (adaDataPadaTanggal($conn, 'izin_karyawan', $userId, $nama, $tanggal)) { // cek data izin
        $hasil['izin'] = 'Ya'; // kalau ada data izin, isi Ya
    }

    if (adaDataPadaTanggal($conn, 'sakit_karyawan', $userId, $nama, $tanggal)) { // cek data sakit
        $hasil['sakit'] = 'Ya'; // kalau ada data sakit, isi Ya
    }

    if (adaDataPadaTanggal($conn, 'pengajuan_cuti', $userId, $nama, $tanggal)) { // cek data cuti
        $hasil['cuti'] = 'Ya'; // kalau ada data cuti, isi Ya
    }


    $hasil['shift'] = getShiftValue($conn, $userId, $nama, $tanggal); // isi nilai shift

    return $hasil; // return semua status tambahan
}

// =========================
// FILTER
// =========================
$bulan        = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m'); // ambil bulan dari URL, kalau tidak ada pakai bulan sekarang
$tahun        = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y'); // ambil tahun dari URL, kalau tidak ada pakai tahun sekarang
$namaCari     = isset($_GET['nama']) ? preg_replace('/\s+/', ' ', trim($_GET['nama'])) : ''; // ambil nama pencarian dan rapikan spasi
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'semua'; // ambil status filter, default semua
$tampilData   = isset($_GET['tampil']) ? (int)$_GET['tampil'] : 0; // ambil parameter tampil, default 0

if ($bulan < 1 || $bulan > 12) $bulan = (int)date('m'); // validasi bulan harus 1-12
if ($tahun < 2000 || $tahun > 2100) $tahun = (int)date('Y'); // validasi tahun

$statusAllowed = ['semua', 'sudah_absen', 'belum_absen', 'sudah_masuk', 'sudah_lengkap']; // daftar filter yang valid
if (!in_array($statusFilter, $statusAllowed, true)) { // kalau filter tidak valid
    $statusFilter = 'semua'; // kembalikan ke default
}

list($periodeAwal, $periodeAkhir) = getPeriodeCutoff($bulan, $tahun, $cutoffMulai, $cutoffSelesai); // tentukan awal dan akhir periode
$periodeJudul = formatPeriodeLabelBulan($bulan, $tahun, $cutoffMulai, $cutoffSelesai); // buat label periode

$namaCariEsc = mysqli_real_escape_string($conn, $namaCari); // amankan keyword nama
$filterNamaKaryawan = ""; // default filter nama untuk tabel karyawan kosong
$filterNamaAdmin    = ""; // default filter nama untuk tabel admin kosong

if ($namaCari !== "") { // kalau user mengisi nama pencarian
    $filterNamaKaryawan = " 
        AND (
            ku.nama LIKE '%$namaCariEsc%'
            OR u.name LIKE '%$namaCariEsc%'
        )
    "; // filter nama untuk tabel karyawan
    $filterNamaAdmin = " 
        AND (
            au.nama LIKE '%$namaCariEsc%'
            OR u2.name LIKE '%$namaCariEsc%'
        )
    "; // filter nama untuk tabel admin
}

// =========================
// QUERY DETAIL
// =========================
$queryDetail = "
    SELECT * FROM (
        SELECT 
            ku.user_id,
            COALESCE(NULLIF(TRIM(u.name), ''), ku.nama) AS nama,
            ku.tanggal,
            ku.jam_masuk,
            ku.jam_keluar,
            ku.mac_address,
            COALESCE(u.role, 'karyawan') AS role
        FROM karyawan_users ku
        LEFT JOIN users u ON TRIM(u.user_id) = TRIM(ku.user_id)
        WHERE ku.tanggal BETWEEN '$periodeAwal' AND '$periodeAkhir'
          $filterNamaKaryawan

        UNION ALL

        SELECT
            au.user_id,
            COALESCE(NULLIF(TRIM(u2.name), ''), au.nama) AS nama,
            au.tanggal,
            au.jam_masuk,
            au.jam_keluar,
            au.mac_address,
            COALESCE(u2.role, 'admin') AS role
        FROM admin_users au
        LEFT JOIN users u2 ON TRIM(u2.user_id) = TRIM(au.user_id)
        WHERE au.tanggal BETWEEN '$periodeAwal' AND '$periodeAkhir'
          $filterNamaAdmin
    ) x
    ORDER BY x.tanggal DESC, x.jam_masuk DESC
"; // query gabungan absensi karyawan dan admin

$dataDetail = mysqli_query($conn, $queryDetail); // jalankan query detail
if (!$dataDetail) { // kalau query gagal
    die("Query error (detail): " . mysqli_error($conn)); // hentikan dan tampilkan error
}

$rows = []; // array penampung data detail final
while ($r = mysqli_fetch_assoc($dataDetail)) { // loop setiap hasil query
    if (lolosFilterStatus($r['jam_masuk'] ?? '', $r['jam_keluar'] ?? '', $statusFilter)) { // cek apakah row lolos filter status
        $tambahan = ambilStatusTambahan(
            $conn,
            $r['user_id'] ?? '',
            $r['nama'] ?? '',
            $r['tanggal'] ?? ''
        ); // ambil status tambahan

        $r['izin']   = $tambahan['izin']; // tambahkan data izin ke row
        $r['sakit']  = $tambahan['sakit']; // tambahkan data sakit ke row
        $r['cuti']   = $tambahan['cuti']; // tambahkan data cuti ke row
        $r['shift']  = $tambahan['shift']; // tambahkan data shift ke row

        $rows[] = $r; // masukkan row final ke array rows
    }
}

// =========================
// REKAP
// =========================
$totalData     = count($rows); // hitung total data detail
$totalAdmin    = 0; // default total admin
$totalKaryawan = 0; // default total karyawan
$totalMasuk    = 0; // default total masuk
$totalPulang   = 0; // default total pulang
$uniqueUsers   = []; // array untuk menghitung user unik

foreach ($rows as $r) { // loop semua row
    $role = strtolower(trim($r['role'] ?? 'karyawan')); // ambil role dan ubah ke huruf kecil

    if ($role === 'admin') $totalAdmin++; // kalau admin tambah total admin
    else $totalKaryawan++; // selain itu tambah total karyawan

    $uidKey = trim($r['user_id'] ?? ''); // ambil user id
    if ($uidKey === '') $uidKey = trim($r['nama'] ?? ''); // kalau user id kosong, pakai nama sebagai key
    $uniqueUsers[$uidKey] = true; // simpan key user unik

    if (!empty($r['jam_masuk'])) $totalMasuk++; // kalau ada jam masuk tambah total masuk
    if (!empty($r['jam_keluar'])) $totalPulang++; // kalau ada jam pulang tambah total pulang
}

$totalUserUnik = count($uniqueUsers); // hitung total user unik

$rekapPerNama = []; // array rekap per nama
foreach ($rows as $r) { // loop semua row
    $nama = trim((string)($r['nama'] ?? '-')); // ambil nama
    $role = trim((string)($r['role'] ?? 'karyawan')); // ambil role
    $key  = strtolower($nama . '||' . $role); // buat key unik per nama+role

    if (!isset($rekapPerNama[$key])) { // kalau key belum ada
        $rekapPerNama[$key] = [
            'nama'         => $nama, // simpan nama
            'role'         => $role, // simpan role
            'total_absen'  => 0, // total absen awal
            'total_masuk'  => 0, // total masuk awal
            'total_pulang' => 0, // total pulang awal
            'total_izin'   => 0, // total izin awal
            'total_sakit'  => 0, // total sakit awal
            'total_cuti'   => 0, // total cuti awal
            'user_id'      => trim((string)($r['user_id'] ?? '')) // simpan user_id
        ];
    }

    $rekapPerNama[$key]['total_absen']++; // tambah total absen row ini

    if (!empty($r['jam_masuk'])) $rekapPerNama[$key]['total_masuk']++; // kalau ada jam masuk tambah total masuk
    if (!empty($r['jam_keluar'])) $rekapPerNama[$key]['total_pulang']++; // kalau ada jam pulang tambah total pulang
    if (($r['izin'] ?? '-') === 'Ya') $rekapPerNama[$key]['total_izin']++; // kalau izin Ya tambah total izin
    if (($r['sakit'] ?? '-') === 'Ya') $rekapPerNama[$key]['total_sakit']++; // kalau sakit Ya tambah total sakit
    if (($r['cuti'] ?? '-') === 'Ya') $rekapPerNama[$key]['total_cuti']++; // kalau cuti Ya tambah total cuti
}

$rekapPerNama = array_values($rekapPerNama); // ubah array associative menjadi array numerik

usort($rekapPerNama, function($a, $b) { // urutkan rekap per nama
    $roleA = strtolower($a['role']); // ambil role A
    $roleB = strtolower($b['role']); // ambil role B

    if ($roleA === $roleB) { // kalau role sama
        return strcasecmp($a['nama'], $b['nama']); // urutkan berdasarkan nama
    }
    return strcmp($roleA, $roleB); // kalau role beda, urutkan berdasarkan role
});

// =========================
// DATA CHART
// =========================
$chartStatusLabels = ['Cuti', 'Sakit', 'Izin', 'Absen', 'Terlambat']; // label chart status
$chartStatusData   = [0, 0, 0, 0, 0, 0]; // data awal chart status


$totalCutiChart      = 0; // total cuti chart
$totalSakitChart     = 0; // total sakit chart
$totalIzinChart      = 0; // total izin chart
$totalAbsenChart     = 0; // total absen chart
$totalTerlambatChart = 0; // total terlambat chart

foreach ($rows as $r) { // loop semua data
    if (($r['cuti'] ?? '-') === 'Ya') $totalCutiChart++; // hitung cuti
    if (($r['sakit'] ?? '-') === 'Ya') $totalSakitChart++; // hitung sakit
    if (($r['izin'] ?? '-') === 'Ya') $totalIzinChart++; // hitung izin
    if (isTerlambat($r['jam_masuk'] ?? '')) $totalTerlambatChart++; // hitung terlambat
}

$startDate = new DateTime($periodeAwal); // object tanggal awal periode
$endDate   = new DateTime($periodeAkhir); // object tanggal akhir periode
$endDate->modify('+1 day'); // tambahkan 1 hari agar perhitungan interval inklusif
$interval = $startDate->diff($endDate); // hitung selisih hari
$totalHariPeriode = (int)$interval->days; // total hari dalam periode
$totalUserPeriode = count($uniqueUsers); // total user unik dalam periode
$totalSlotAbsen   = $totalHariPeriode * $totalUserPeriode; // total slot absensi ideal
$totalDataHadir   = count($rows); // total data hadir aktual
$totalAbsenChart  = max(0, $totalSlotAbsen - $totalDataHadir); // hitung perkiraan absen/tidak hadir

$chartStatusData = [
    $totalCutiChart, // isi chart cuti
    $totalSakitChart, // isi chart sakit
    $totalIzinChart, // isi chart izin
    $totalAbsenChart, // isi chart absen
    $totalTerlambatChart // isi chart terlambat
];

$chartBulanLabels = []; // label chart bulanan
$chartBulanData   = []; // data chart bulanan

for ($i = 1; $i <= 12; $i++) { // loop bulan Januari-Desember
    list($chartAwal, $chartAkhir) = getPeriodeCutoff($i, $tahun, $cutoffMulai, $cutoffSelesai); // ambil periode cutoff tiap bulan
    $chartBulanLabels[] = namaBulan($i) . "\n" . date('d/m', strtotime($chartAwal)) . "-" . date('d/m', strtotime($chartAkhir)); // buat label chart per bulan

    $qChartBulan = mysqli_query($conn, "
        SELECT COUNT(*) AS total FROM (
            SELECT tanggal FROM karyawan_users WHERE tanggal BETWEEN '$chartAwal' AND '$chartAkhir'
            UNION ALL
            SELECT tanggal FROM admin_users WHERE tanggal BETWEEN '$chartAwal' AND '$chartAkhir'
        ) t
    "); // query hitung total absen per bulan dari tabel karyawan dan admin

    $totalChartBulan = 0; // default 0
    if ($qChartBulan && $dChartBulan = mysqli_fetch_assoc($qChartBulan)) { // kalau query berhasil
        $totalChartBulan = (int)($dChartBulan['total'] ?? 0); // ambil total
    }

    $chartBulanData[] = $totalChartBulan; // simpan ke array chart bulan
}

$chartTahunLabels = []; // label chart tahunan
$chartTahunData   = []; // data chart tahunan

$tahunAwal  = (int)date('Y') - 4; // tahun awal chart = 4 tahun sebelum sekarang
$tahunAkhir = (int)date('Y'); // tahun akhir chart = tahun sekarang

for ($th = $tahunAwal; $th <= $tahunAkhir; $th++) { // loop dari tahun awal sampai akhir
    $chartTahunLabels[] = (string)$th; // simpan label tahun

    $qChartTahun = mysqli_query($conn, "
        SELECT COUNT(*) AS total FROM (
            SELECT tanggal FROM karyawan_users WHERE YEAR(tanggal) = $th
            UNION ALL
            SELECT tanggal FROM admin_users WHERE YEAR(tanggal) = $th
        ) t
    "); // query hitung total absen per tahun

    $totalChartTahun = 0; // default total 0
    if ($qChartTahun && $dChartTahun = mysqli_fetch_assoc($qChartTahun)) { // kalau query berhasil
        $totalChartTahun = (int)($dChartTahun['total'] ?? 0); // ambil total
    }

    $chartTahunData[] = $totalChartTahun; // simpan ke data chart tahun
}
?>
<!DOCTYPE html> <!-- deklarasi HTML5 -->
<html lang="id"> <!-- awal dokumen HTML dengan bahasa Indonesia -->
<head>
<meta charset="UTF-8"> <!-- encoding UTF-8 -->
<meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- responsive viewport -->
<title>Dashboard Admin - PT Meindo</title> <!-- judul tab browser -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"> <!-- import font Poppins -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- import library Chart.js -->
<style>
*{box-sizing:border-box;margin:0;padding:0} /* reset semua elemen */
body{
    font-family:'Poppins',sans-serif; /* font utama */
    background:#e9f0f4; /* background body */
    color:#2d3748; /* warna teks body */
    padding:22px; /* jarak dalam body */
}
.container{max-width:1280px;margin:0 auto} /* container utama lebar maksimal 1280px dan rata tengah */
.card{
    background:#fff; /* background putih */
    border:1px solid #dbe4ec; /* border tipis */
    border-radius:16px; /* sudut melengkung */
    padding:22px; /* jarak dalam */
    box-shadow:0 10px 25px rgba(0,0,0,.06); /* bayangan */
}
.header-top{
    display:flex; /* flexbox */
    justify-content:space-between; /* kiri-kanan */
    align-items:center; /* tengah vertikal */
    margin-bottom:18px; /* jarak bawah */
    gap:12px; /* jarak */
    flex-wrap:wrap; /* kalau sempit turun */
}
h1{
    font-size:22px; /* ukuran judul */
    color:#0b4ea2; /* warna biru */
    font-weight:800; /* tebal */
}
.subtext{
    color:#64748b; /* abu */
    font-size:13px; /* ukuran kecil */
    margin-top:4px; /* jarak atas */
}
.btn-back{
    background:#0b4ea2; /* biru */
    color:#fff; /* putih */
    text-decoration:none; /* hilangkan underline */
    padding:10px 16px; /* jarak dalam */
    border-radius:10px; /* sudut */
    font-size:14px; /* ukuran */
    font-weight:600; /* tebal */
}
.btn-back:hover{background:#083b79} /* hover tombol kembali */
.filters{
    display:grid; /* grid */
    grid-template-columns:repeat(5,1fr); /* 5 kolom */
    gap:14px; /* jarak */
    margin-bottom:18px; /* jarak bawah */
}
.field{
    display:flex; /* flex */
    flex-direction:column; /* vertikal */
    gap:6px; /* jarak */
}
.field label{
    font-size:13px; /* ukuran */
    font-weight:600; /* tebal */
    color:#334155; /* warna */
}
input,select{
    width:100%; /* lebar penuh */
    padding:11px 13px; /* jarak dalam */
    border:1px solid #d6dde6; /* border */
    border-radius:10px; /* sudut */
    background:#fff; /* putih */
    font-family:'Poppins',sans-serif; /* font */
    font-size:14px; /* ukuran */
    outline:none; /* hilangkan outline default */
}
input:focus,select:focus{
    border-color:#0b4ea2; /* border fokus */
    box-shadow:0 0 0 4px rgba(11,78,162,.10); /* efek fokus */
}
.action-row{
    display:flex; /* flex */
    gap:10px; /* jarak */
    align-items:end; /* rata bawah */
    flex-wrap:wrap; /* bisa turun */
}
.btn{
    border:none; /* tanpa border */
    cursor:pointer; /* kursor tangan */
    padding:11px 16px; /* jarak dalam */
    border-radius:10px; /* sudut */
    font-family:'Poppins',sans-serif; /* font */
    font-size:14px; /* ukuran */
    font-weight:600; /* tebal */
    text-decoration:none; /* hilangkan underline */
    display:inline-flex; /* flex inline */
    align-items:center; /* tengah */
    justify-content:center; /* tengah */
    gap:8px; /* jarak */
}
.btn-filter{
    background:#0b4ea2; /* biru */
    color:#fff; /* putih */
}
.btn-filter:hover{background:#083b79} /* hover filter */
.btn-show,.btn-hide{
    background:#fff; /* putih */
    color:#334155; /* abu gelap */
    border:1px solid #d6dde6; /* border */
}
.btn-show:hover,.btn-hide:hover{
    background:#f8fafc; /* hover */
}
.btn-show{
    color:#0b4ea2; /* biru */
    border-color:#bcd2ee; /* border biru muda */
    background:#eef5ff; /* background biru muda */
}
.btn-hide{
    color:#b91c1c; /* merah */
    border-color:#fecaca; /* border merah muda */
    background:#fff5f5; /* background merah muda */
}
.summary{
    display:grid; /* grid */
    grid-template-columns:repeat(5,1fr); /* 5 kolom */
    gap:12px; /* jarak */
    margin:18px 0; /* jarak atas bawah */
}
.box{
    background:linear-gradient(180deg,#f8fbff,#eef5fc); /* gradasi */
    border:1px solid #d6e4f2; /* border */
    border-radius:14px; /* sudut */
    padding:14px; /* jarak dalam */
}
.box .k{
    font-size:12px; /* label kecil */
    color:#64748b; /* abu */
}
.box .v{
    font-size:20px; /* angka besar */
    font-weight:800; /* tebal */
    color:#0b4ea2; /* biru */
    margin-top:6px; /* jarak atas */
}
.chart-grid{
    display:grid; /* grid */
    grid-template-columns:1fr 1fr; /* 2 kolom */
    gap:16px; /* jarak */
    margin:18px 0 0; /* jarak atas */
}
.chart-card{
    background:#fff; /* putih */
    border:1px solid #dbe4ec; /* border */
    border-radius:14px; /* sudut */
    padding:16px; /* jarak dalam */
    box-shadow:0 8px 18px rgba(0,0,0,.04); /* bayangan */
}
.chart-title{
    font-size:15px; /* ukuran */
    font-weight:700; /* tebal */
    color:#0f172a; /* warna */
    margin-bottom:10px; /* jarak bawah */
}
.chart-wrap{
    position:relative; /* posisi relatif */
    height:320px; /* tinggi chart */
}
.section-head{
    display:flex; /* flex */
    justify-content:space-between; /* kiri kanan */
    align-items:center; /* tengah */
    margin-top:18px; /* jarak atas */
    margin-bottom:10px; /* jarak bawah */
    gap:10px; /* jarak */
    flex-wrap:wrap; /* bisa turun */
}
.section-head h2{
    font-size:16px; /* ukuran */
    color:#1e293b; /* warna */
}
.badge{
    background:#edf5ff; /* latar */
    color:#0b4ea2; /* teks */
    border:1px solid #cfe0f5; /* border */
    padding:7px 10px; /* jarak dalam */
    border-radius:999px; /* kapsul */
    font-size:12px; /* ukuran */
    font-weight:600; /* tebal */
}
.hidden-box{
    border:1px dashed #cbd5e1; /* border putus-putus */
    background:#f8fafc; /* latar */
    border-radius:12px; /* sudut */
    padding:26px; /* jarak dalam */
    text-align:center; /* rata tengah */
    color:#64748b; /* warna */
    margin-top:10px; /* jarak atas */
}
.hidden-box strong{color:#0b4ea2} /* warna teks strong */
.table-wrap{
    overflow:auto; /* scroll kalau penuh */
    border:1px solid #dbe4ec; /* border */
    border-radius:12px; /* sudut */
    background:#fff; /* putih */
}
table{
    width:100%; /* lebar penuh */
    border-collapse:collapse; /* border tabel menyatu */
    min-width:1300px; /* lebar minimum */
}
th{
    background:#f8fafc; /* latar header */
    color:#111827; /* warna teks */
    font-size:13px; /* ukuran */
    font-weight:700; /* tebal */
    padding:12px; /* jarak */
    text-align:left; /* rata kiri */
    border-bottom:1px solid #dbe4ec; /* garis bawah */
}
td{
    padding:12px; /* jarak */
    font-size:13px; /* ukuran */
    color:#334155; /* warna */
    border-bottom:1px solid #edf2f7; /* garis bawah */
    vertical-align:top; /* rata atas */
}
tr:hover td{background:#f9fbfd} /* hover row */
.badge-aksi{
    display:inline-flex; /* flex inline */
    align-items:center; /* tengah */
    justify-content:center; /* tengah */
    min-width:120px; /* lebar minimum */
    padding:7px 10px; /* jarak */
    border-radius:999px; /* kapsul */
    font-size:12px; /* ukuran */
    font-weight:700; /* tebal */
    text-align:center; /* rata tengah */
    border:1px solid transparent; /* border default */
}
.aksi-belum{
    background:#fee2e2; /* merah muda */
    color:#b91c1c; /* merah */
    border-color:#fecaca; /* border */
}
.aksi-masuk{
    background:#fef3c7; /* kuning muda */
    color:#b45309; /* coklat */
    border-color:#fde68a; /* border */
}
.aksi-lengkap{
    background:#dcfce7; /* hijau muda */
    color:#166534; /* hijau */
    border-color:#bbf7d0; /* border */
}
.periode-box{
    background:#f8fbff; /* latar */
    border:1px solid #d6e4f2; /* border */
    border-radius:12px; /* sudut */
    padding:14px 16px; /* jarak dalam */
    margin-bottom:18px; /* jarak bawah */
}
.periode-title{
    font-size:12px; /* ukuran */
    color:#64748b; /* abu */
    margin-bottom:4px; /* jarak bawah */
}
.periode-value{
    font-size:16px; /* ukuran */
    font-weight:700; /* tebal */
    color:#0b4ea2; /* biru */
}
.report-btn{
    display:inline-block; /* tampil inline-block */
    font-size:11px; /* ukuran kecil */
    font-weight:700; /* tebal */
    text-decoration:none; /* tanpa underline */
    color:#fff; /* putih */
    padding:6px 9px; /* jarak */
    border-radius:7px; /* sudut */
    line-height:1.2; /* tinggi baris */
}
.report-pdf{background:#0b4ea2} /* tombol pdf */
.report-pdf:hover{background:#083b79} /* hover pdf */
.report-xls{background:#16a34a} /* tombol excel */
.report-xls:hover{background:#15803d} /* hover excel */
.rekap-links{
    display:flex; /* flex */
    gap:6px; /* jarak */
    flex-wrap:wrap; /* bisa turun */
}
@media(max-width:1100px){
    .filters{grid-template-columns:1fr 1fr} /* filter jadi 2 kolom */
    .chart-grid{grid-template-columns:1fr} /* chart jadi 1 kolom */
}
@media(max-width:980px){
    .summary{grid-template-columns:1fr 1fr} /* summary jadi 2 kolom */
}
@media(max-width:640px){
    body{padding:12px} /* padding body kecil */
    .filters{grid-template-columns:1fr} /* filter 1 kolom */
    .summary{grid-template-columns:1fr} /* summary 1 kolom */
    .chart-wrap{height:260px} /* tinggi chart lebih kecil */
}
</style>
</head>
<body>
<div class="container"> <!-- container utama -->
    <div class="card"> <!-- card utama -->
        <div class="header-top"> <!-- header atas -->
            <div>
                <h1>Dashboard Admin Absensi</h1> <!-- judul -->
                <div class="subtext">Laporan absensi bulanan PT Meindo</div> <!-- subjudul -->
            </div>
            <a href="admin.php" class="btn-back">← Kembali</a> <!-- tombol kembali -->
        </div>

        <div class="periode-box"> <!-- box periode -->
            <div class="periode-title">Periode aktif</div> <!-- label -->
            <div class="periode-value"><?= htmlspecialchars($periodeJudul) ?></div> <!-- tampilkan judul periode -->
        </div>

        <form method="GET"> <!-- form filter menggunakan GET -->
            <div class="filters"> <!-- grid filter -->
                <div class="field">
                    <label>Bulan Periode</label> <!-- label bulan -->
                    <select name="bulan"> <!-- dropdown bulan -->
                        <?php for($b=1;$b<=12;$b++): ?> <!-- loop bulan 1-12 -->
                            <option value="<?= $b ?>" <?= ($b == $bulan ? 'selected' : '') ?>> <!-- option bulan -->
                                <?= namaBulan($b) ?> <!-- tampil nama bulan -->
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Tahun</label> <!-- label tahun -->
                    <select name="tahun"> <!-- dropdown tahun -->
                        <?php for($t=(int)date('Y')-3; $t<=(int)date('Y')+3; $t++): ?> <!-- loop tahun dari -3 sampai +3 -->
                            <option value="<?= $t ?>" <?= ($t == $tahun ? 'selected' : '') ?>> <!-- option tahun -->
                                <?= $t ?> <!-- tampil tahun -->
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Cari Nama Pegawai</label> <!-- label cari nama -->
                    <input type="text" name="nama" placeholder="Contoh: Andi / Siti / Budi" value="<?= htmlspecialchars($namaCari) ?>"> <!-- input pencarian nama -->
                </div>

                <div class="field">
                    <label>Status Absensi</label> <!-- label status -->
                    <select name="status"> <!-- dropdown status -->
                        <option value="semua" <?= $statusFilter === 'semua' ? 'selected' : '' ?>>Semua</option> <!-- semua -->
                        <option value="sudah_absen" <?= $statusFilter === 'sudah_absen' ? 'selected' : '' ?>>Sudah Absen</option> <!-- sudah absen -->
                        <option value="belum_absen" <?= $statusFilter === 'belum_absen' ? 'selected' : '' ?>>Belum Absen</option> <!-- belum absen -->
                        <option value="sudah_masuk" <?= $statusFilter === 'sudah_masuk' ? 'selected' : '' ?>>Sudah Masuk</option> <!-- sudah masuk -->
                        <option value="sudah_lengkap" <?= $statusFilter === 'sudah_lengkap' ? 'selected' : '' ?>>Sudah Lengkap</option> <!-- sudah lengkap -->
                    </select>
                </div>

                <div class="field action-row">
                    <input type="hidden" name="tampil" value="<?= $tampilData ?>"> <!-- hidden untuk simpan status tampil data -->
                    <button class="btn btn-filter" type="submit">Filter</button> <!-- tombol filter -->
                </div>
            </div>
        </form>

        <div class="action-row" style="margin-bottom:18px;"> <!-- baris tombol tampil/sembunyi -->
            <a class="btn btn-show" href="?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>&nama=<?= urlencode($namaCari) ?>&status=<?= urlencode($statusFilter) ?>&tampil=1">Tampilkan Data</a> <!-- tombol tampil -->
            <a class="btn btn-hide" href="?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>&nama=<?= urlencode($namaCari) ?>&status=<?= urlencode($statusFilter) ?>&tampil=0">Sembunyikan Data</a> <!-- tombol sembunyikan -->
        </div>

        <div class="summary"> <!-- section ringkasan -->
            <div class="box"><div class="k">Total Data</div><div class="v"><?= $totalData ?></div></div> <!-- total data -->
            <div class="box"><div class="k">User Unik</div><div class="v"><?= $totalUserUnik ?></div></div> <!-- total user unik -->
            <div class="box"><div class="k">Total Admin</div><div class="v"><?= $totalAdmin ?></div></div> <!-- total admin -->
            <div class="box"><div class="k">Total Karyawan</div><div class="v"><?= $totalKaryawan ?></div></div> <!-- total karyawan -->
            <div class="box"><div class="k">Masuk / Pulang</div><div class="v"><?= $totalMasuk ?> / <?= $totalPulang ?></div></div> <!-- total masuk dan pulang -->
        </div>

        <?php if ($tampilData !== 1): ?> <!-- kalau tampilData bukan 1 -->
            <div class="hidden-box">
                <strong>Isi tabel sedang disembunyikan.</strong><br> <!-- pesan utama -->
                Klik tombol <strong>Tampilkan Data</strong> untuk melihat rekap, detail absensi, dan chart. <!-- petunjuk -->
            </div>
        <?php else: ?> <!-- kalau tampilData = 1 -->

            <div class="section-head">
                <h2>Rekap Per Nama</h2> <!-- judul rekap -->
                <span class="badge"><?= htmlspecialchars($periodeJudul) ?></span> <!-- badge periode -->
            </div>

            <div class="table-wrap"> <!-- bungkus tabel rekap -->
                <table>
                    <thead>
                        <tr>
                            <th>No</th> <!-- nomor -->
                            <th>Nama Pegawai</th> <!-- nama -->
                            <th>Role</th> <!-- role -->
                            <th>Total Absen</th> <!-- total absen -->
                            <th>Total Masuk</th> <!-- total masuk -->
                            <th>Total Pulang</th> <!-- total pulang -->
                            <th>Total Izin</th> <!-- total izin -->
                            <th>Total Sakit</th> <!-- total sakit -->
                            <th>Total Cuti</th> <!-- total cuti -->
                            <th>Report</th> <!-- report -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($rekapPerNama)): ?> <!-- kalau rekap ada -->
                            <?php $no = 1; foreach ($rekapPerNama as $r): ?> <!-- loop rekap -->
                                <tr>
                                    <td><?= $no++ ?></td> <!-- nomor -->
                                    <td><?= htmlspecialchars($r['nama'] ?? '-') ?></td> <!-- nama -->
                                    <td><?= htmlspecialchars(ucfirst(strtolower($r['role'] ?? '-'))) ?></td> <!-- role -->
                                    <td><?= (int)($r['total_absen'] ?? 0) ?></td> <!-- total absen -->
                                    <td><?= (int)($r['total_masuk'] ?? 0) ?></td> <!-- total masuk -->
                                    <td><?= (int)($r['total_pulang'] ?? 0) ?></td> <!-- total pulang -->
                                    <td><?= (int)($r['total_izin'] ?? 0) ?></td> <!-- total izin -->
                                    <td><?= (int)($r['total_sakit'] ?? 0) ?></td> <!-- total sakit -->
                                    <td><?= (int)($r['total_cuti'] ?? 0) ?></td> <!-- total cuti -->
                                    <td>
                                        <?php if (!empty($r['user_id'])): ?> <!-- kalau ada user id -->
                                            <div class="rekap-links">
                                                <a class="report-btn report-pdf" target="_blank" href="download_fingerprint_report.php?user_id=<?= urlencode($r['user_id']) ?>&format=pdf&base_date=<?= urlencode($periodeAwal) ?>">PDF</a> <!-- link report pdf -->
                                                <a class="report-btn report-xls" target="_blank" href="download_fingerprint_report.php?user_id=<?= urlencode($r['user_id']) ?>&format=excel&base_date=<?= urlencode($periodeAwal) ?>">Excel</a> <!-- link report excel -->
                                            </div>
                                        <?php else: ?> <!-- kalau tidak ada user id -->
                                            - <!-- tampilkan strip -->
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?> <!-- kalau tidak ada data -->
                            <tr><td colspan="11" style="text-align:center;">Tidak ada data.</td></tr> <!-- pesan kosong -->
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-head">
                <h2>Data Detail Absensi</h2> <!-- judul detail -->
                <span class="badge"><?= htmlspecialchars(date('d/m/Y', strtotime($periodeAwal)) . ' - ' . date('d/m/Y', strtotime($periodeAkhir))) ?></span> <!-- badge periode detail -->
            </div>

            <div class="table-wrap"> <!-- bungkus tabel detail -->
                <table>
                    <thead>
                        <tr>
                            <th>No</th> <!-- nomor -->
                            <th>Nama Pegawai</th> <!-- nama -->
                            <th>Role</th> <!-- role -->
                            <th>Tanggal</th> <!-- tanggal -->
                            <th>Masuk</th> <!-- jam masuk -->
                            <th>Pulang</th> <!-- jam pulang -->
                            <th>MAC Address</th> <!-- mac -->
                            <th>Izin</th> <!-- izin -->
                            <th>Sakit</th> <!-- sakit -->
                            <th>Cuti</th> <!-- cuti -->
                            <th>Shift</th> <!-- shift -->
                            <th>Aksi</th> <!-- status aksi -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($totalData > 0): ?> <!-- kalau total data > 0 -->
                            <?php $no2 = 1; foreach($rows as $r): ?> <!-- loop data detail -->
                                <?php $aksi = statusAksiAbsensi($r['jam_masuk'] ?? '', $r['jam_keluar'] ?? ''); ?> <!-- hitung status aksi -->
                                <tr>
                                    <td><?= $no2++ ?></td> <!-- nomor -->
                                    <td><?= htmlspecialchars($r['nama'] ?? '-') ?></td> <!-- nama -->
                                    <td><?= htmlspecialchars(ucfirst(strtolower($r['role'] ?? 'karyawan'))) ?></td> <!-- role -->
                                    <td><?= htmlspecialchars(!empty($r['tanggal']) ? date('d/m/Y', strtotime($r['tanggal'])) : '-') ?></td> <!-- tanggal -->
                                    <td><?= htmlspecialchars(!empty($r['jam_masuk']) ? $r['jam_masuk'] : '-') ?></td> <!-- masuk -->
                                    <td><?= htmlspecialchars(!empty($r['jam_keluar']) ? $r['jam_keluar'] : '-') ?></td> <!-- pulang -->
                                    <td><?= htmlspecialchars(!empty($r['mac_address']) ? $r['mac_address'] : '-') ?></td> <!-- MAC -->
                                    <td><?= htmlspecialchars($r['izin'] ?? '-') ?></td> <!-- izin -->
                                    <td><?= htmlspecialchars($r['sakit'] ?? '-') ?></td> <!-- sakit -->
                                    <td><?= htmlspecialchars($r['cuti'] ?? '-') ?></td> <!-- cuti -->
                                    <td><?= htmlspecialchars($r['shift'] ?? '-') ?></td> <!-- shift -->
                                    <td><span class="badge-aksi <?= $aksi['class'] ?>"><?= htmlspecialchars($aksi['label']) ?></span></td> <!-- badge aksi -->
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?> <!-- kalau data kosong -->
                            <tr><td colspan="13" style="text-align:center;">Tidak ada data absensi untuk filter ini.</td></tr> <!-- pesan kosong -->
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-head">
                <h2>Chart Absensi</h2> <!-- judul chart -->
            </div>

            <div class="chart-grid"> <!-- grid chart -->
                <div class="chart-card">
                    <div class="chart-title">Chart Status Periode Aktif</div> <!-- judul chart 1 -->
                    <div class="chart-wrap">
                        <canvas id="chartStatus"></canvas> <!-- canvas chart status -->
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-title">Chart Total Absen Per Bulan & Per Tahun</div> <!-- judul chart 2 -->
                    <div class="chart-wrap" style="height:280px; margin-bottom:14px;">
                        <canvas id="chartBulanan"></canvas> <!-- canvas chart bulanan -->
                    </div>
                    <div class="chart-wrap" style="height:280px;">
                        <canvas id="chartTahunan"></canvas> <!-- canvas chart tahunan -->
                    </div>
                </div>
            </div>

        <?php endif; ?> <!-- penutup kondisi tampilData -->
    </div>
</div>

<script>
const chartStatusLabels = <?= json_encode($chartStatusLabels, JSON_UNESCAPED_UNICODE) ?>;
const chartStatusData   = <?= json_encode($chartStatusData) ?>;

const chartBulanLabels = <?= json_encode($chartBulanLabels, JSON_UNESCAPED_UNICODE) ?>;
const chartBulanData   = <?= json_encode($chartBulanData) ?>;

const chartTahunLabels = <?= json_encode($chartTahunLabels, JSON_UNESCAPED_UNICODE) ?>;
const chartTahunData   = <?= json_encode($chartTahunData) ?>;

// =========================
// CHART STATUS
// =========================
const ctxStatus = document.getElementById('chartStatus');
if (ctxStatus) {
    new Chart(ctxStatus, {
        type: 'bar',
        data: {
            labels: chartStatusLabels,
            datasets: [
                {
                    label: 'Cuti',
                    data: [chartStatusData[0], null, null, null, null],
                    backgroundColor: 'rgba(59, 130, 246, 0.75)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    label: 'Sakit',
                    data: [null, chartStatusData[1], null, null, null],
                    backgroundColor: 'rgba(239, 68, 68, 0.75)',
                    borderColor: 'rgba(239, 68, 68, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    label: 'Izin',
                    data: [null, null, chartStatusData[2], null, null],
                    backgroundColor: 'rgba(245, 158, 11, 0.75)',
                    borderColor: 'rgba(245, 158, 11, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    label: 'Absen',
                    data: [null, null, null, chartStatusData[3], null],
                    backgroundColor: 'rgba(107, 114, 128, 0.75)',
                    borderColor: 'rgba(107, 114, 128, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    label: 'Terlambat',
                    data: [null, null, null, null, chartStatusData[4]],
                    backgroundColor: 'rgba(34, 197, 94, 0.75)',
                    borderColor: 'rgba(34, 197, 94, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });
}

// =========================
// CHART BULANAN
// =========================
const ctxBulanan = document.getElementById('chartBulanan');
if (ctxBulanan) {
    new Chart(ctxBulanan, {
        type: 'bar',
        data: {
            labels: chartBulanLabels,
            datasets: [{
                label: 'Total Absen Per Bulan',
                data: chartBulanData,
                backgroundColor: 'rgba(11, 78, 162, 0.75)',
                borderColor: 'rgba(11, 78, 162, 1)',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true } },
            scales: {
                x: {
                    ticks: {
                        callback: function(value) {
                            const label = this.getLabelForValue(value);
                            return String(label).split('\n');
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });
}

// =========================
// CHART TAHUNAN
// =========================
const ctxTahunan = document.getElementById('chartTahunan');
if (ctxTahunan) {
    new Chart(ctxTahunan, {
        type: 'line',
        data: {
            labels: chartTahunLabels,
            datasets: [{
                label: 'Total Absen Per Tahun',
                data: chartTahunData,
                fill: true,
                tension: 0.35,
                backgroundColor: 'rgba(34, 197, 94, 0.18)',
                borderColor: 'rgba(34, 197, 94, 1)',
                borderWidth: 3,
                pointRadius: 4,
                pointBackgroundColor: 'rgba(34, 197, 94, 1)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });
}
</script>
</body>
</html>