<?php // membuka tag PHP

if (session_status() === PHP_SESSION_NONE) session_start(); 
// session_status() = mengecek status session saat ini
// PHP_SESSION_NONE = artinya session belum aktif
// session_start() = memulai session agar data login di $_SESSION bisa dipakai


include "koneksi.php"; 
// include = memanggil file lain
// koneksi.php = file koneksi database MySQL

date_default_timezone_set("Asia/Jakarta"); 
// mengatur zona waktu server ke Asia/Jakarta (WIB)

// ==============================
// PROSES LOGOUT
// ==============================
if (isset($_GET['logout']) && $_GET['logout'] == "1") { 
    // isset($_GET['logout']) = cek apakah URL memiliki parameter logout
    // && = dan
    // $_GET['logout'] == "1" = nilainya harus 1
    session_destroy(); // session_destroy() = menghapus semua data session, artinya user logout
    header("Location: login_karyawan.php"); session_destroy(); 
    // session_destroy() = menghapus semua data session, artinya user logout
    exit; // header("Location: ...") = mengarahkan user ke halaman login_karyawan.php
}

// ==============================
// CEK APAKAH SUDAH LOGIN
// ==============================
if (!isset($_SESSION['karyawan_login']) || $_SESSION['karyawan_login'] !== true) {
    // !isset(...) = jika session karyawan_login tidak ada
    // || = atau
    // !== true = nilainya bukan true

    header("Location: login_karyawan.php"); // kalau belum login, paksa balik ke halaman login
    exit;  // hentikan script
}

// ==============================
// CEK ROLE HARUS KARYAWAN
// ==============================
if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'karyawan') {
    // cek apakah session role ada
    // strtolower() = ubah huruf jadi kecil semua
    // trim() = hapus spasi di depan dan belakang
    // harus sama dengan 'karyawan'
    header("Location: login_karyawan.php"); // kalau bukan role karyawan, kembalikan ke login
    exit;  // hentikan program
}


// ==============================
// AMBIL DATA DARI SESSION
// ==============================
$user_id  = trim($_SESSION['user_id'] ?? ''); 
// ambil user_id dari session
// ?? '' = kalau tidak ada, isi string kosong
// trim() = buang spasi

$nama     = trim($_SESSION['nama'] ?? ''); // ambil nama user dari session
$selfPage = basename($_SERVER['PHP_SELF']); 
// $_SERVER['PHP_SELF'] = nama file PHP yang sedang dibuka
// basename() = ambil nama filenya saja tanpa path

if ($user_id === '') {  // kalau user_id kosong
    die("Session user_id kosong. Silakan login ulang.");  // die() = hentikan program dan tampilkan pesan error
}

// ==============================
// NAMA TABEL DATABASE
// ==============================
$TABEL_ABSENSI = "karyawan_users"; // nama tabel absensi karyawan
$TABEL_USERS   = "users"; // nama tabel data user / profil user

// ==============================
// DATA WAKTU HARI INI
// ==============================
$tgl_hari_ini = date("Y-m-d"); // date("Y-m-d") = tanggal hari ini format tahun-bulan-hari

$jam_sekarang = date("H:i:s"); // date("H:i:s") = jam sekarang format jam:menit:detik

$serverMs     = (int) round(microtime(true) * 1000); 
// microtime(true) = mengambil waktu sekarang dalam detik pecahan
// * 1000 = diubah jadi milidetik
// round() = dibulatkan
// (int) = dijadikan bilangan bulat
// dipakai untuk jam realtime di JavaScript

$pesan        = ""; // variabel pesan notifikasi / error, default kosong

// ==============================
// FUNCTION HELPER ESCAPE HTML
// ==============================
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    // htmlspecialchars() = mengubah karakter khusus HTML jadi aman
    // contoh < menjadi &lt;
    // tujuannya mencegah XSS / script jahat tampil di browser
    // (string)$str = paksa jadi string
    // ENT_QUOTES = kutip tunggal dan ganda ikut di-escape
    // UTF-8 = encoding
}

// ==============================
// FUNCTION CEK JAM KOSONG
// ==============================
function isJamKosong($jam): bool
{
    $jam = trim((string)$jam); // pastikan nilai jam menjadi string dan spasinya dibuang
    return $jam === '' 
        || strtolower($jam) === 'null' 
        || $jam === '00:00:00' 
        || $jam === '00:00';
    // return true kalau:
    // - string kosong
    // - nilainya "null"
    // - nilainya 00:00:00
    // - nilainya 00:00
    // artinya jam dianggap belum ada / belum diisi
}

// ==============================
// FUNCTION EXECUTE QUERY ATAU ERROR
// ==============================
function executeOrDie($stmt, $label = 'Query gagal')
{
    if (!mysqli_stmt_execute($stmt)) { 
        // mysqli_stmt_execute() = menjalankan prepared statement
        // kalau gagal
        die($label . ': ' . mysqli_stmt_error($stmt)); 
        // tampilkan label error + detail error dari MySQL
    }
}

// ==============================
// FUNCTION AMBIL MAC ADDRESS BERDASARKAN IP
// ==============================
function getMacAddress($ip)
{
    $mac = ""; 
    // default MAC kosong

    if ($ip === "") return $mac; 
    // kalau IP kosong, langsung kembalikan string kosong

    @exec("ping -n 1 " . escapeshellarg($ip)); 
    // exec() = menjalankan perintah sistem
    // ping -n 1 = ping 1 kali (umumnya di Windows)
    // escapeshellarg($ip) = mengamankan IP agar aman dipakai di command
    // @ = menyembunyikan warning/error
    $arp = @shell_exec("arp -a"); 
    // shell_exec("arp -a") = ambil tabel ARP dari komputer/server
    // tujuannya mencari pasangan IP dan MAC Address

    if (!$arp) return $mac; 
    // kalau tidak ada hasil ARP, return kosong

    $lines = preg_split("/\r\n|\n|\r/", $arp); 
    // preg_split() = memecah string jadi array per baris
    // hasil arp -a dipisah berdasarkan enter

    foreach ($lines as $line) { 
        // loop setiap baris hasil arp
        if (stripos($line, $ip) !== false) { 
            // stripos() = cari posisi teks IP di dalam baris, case-insensitive
            // !== false artinya IP ditemukan
           $parts = preg_split('/\s+/', trim($line)); 
            // trim() = buang spasi awal/akhir
            // preg_split('/\s+/') = pecah baris berdasarkan spasi

            foreach ($parts as $part) { 
                // loop setiap potongan teks pada baris itu
                if (preg_match('/^([0-9a-f]{2}[-:]){5}[0-9a-f]{2}$/i', $part)) { 
                    // preg_match() = cek apakah $part cocok dengan pola MAC Address
                    // pola ini menerima format seperti:
                    // aa-bb-cc-dd-ee-ff
                    // aa:bb:cc:dd:ee:ff

                    return $part; 
                    // kalau ketemu MAC yang valid, langsung return
                }
            }
        }
    }

  return $mac; 
    // kalau tidak ditemukan, return kosong
}

// ==============================
// FUNCTION AMBIL DATA ABSENSI HARI INI
// ==============================
function getDataHariIni($conn, $table, $user_id, $tanggal)
{
    $stmt = mysqli_prepare($conn, "
        SELECT *
        FROM {$table}
        WHERE user_id = ?
          AND tanggal = ?
        LIMIT 1
    ");
    // mysqli_prepare() = membuat prepared statement
    // SELECT * = ambil semua kolom
    // FROM {$table} = dari tabel yang dikirim ke function
    // WHERE user_id = ? dan tanggal = ? = filter berdasarkan user dan tanggal
    // LIMIT 1 = ambil maksimal 1 data

   if (!$stmt) {
        die("Prepare getDataHariIni gagal: " . mysqli_error($conn));  // kalau prepare query gagal, hentikan program
    }

    mysqli_stmt_bind_param($stmt, "ss", $user_id, $tanggal); 
    // bind_param() = isi placeholder ?
    // "ss" = dua parameter string
    // parameter 1 = user_id
    // parameter 2 = tanggal
    executeOrDie($stmt, "Execute getDataHariIni gagal"); // jalankan query, kalau gagal tampilkan error
    $result = mysqli_stmt_get_result($stmt); // ambil hasil query
    $data = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
    // kalau hasil ada dan jumlah baris > 0
    // mysqli_fetch_assoc() = ambil data sebagai array associative
    // kalau tidak ada, isi null
    mysqli_stmt_close($stmt); // tutup statement
    return $data; // kembalikan hasil data
}

// ==============================
// FUNCTION HITUNG PERIODE 16 SAMPAI 15
// ==============================
function getPeriode16to15(?string $baseDate = null): array
{
    $baseDate = $baseDate ?: date('Y-m-d'); // kalau baseDate kosong/null, pakai tanggal hari ini
    $dt = new DateTime($baseDate); // buat object DateTime dari baseDate
    $day = (int)$dt->format('d'); // ambil angka tanggal saja, misalnya 07 atau 21
    if ($day >= 16) { // kalau hari sekarang tanggal 16 ke atas
        $start = new DateTime($dt->format('Y-m-16')); // awal periode = tanggal 16 bulan ini
        $end = new DateTime($start->format('Y-m-15')); // set tanggal 15 bulan yang sama dulu
        $end->modify('+1 month'); // lalu maju 1 bulan, jadi akhir periode = tanggal 15 bulan depan
    } else { // kalau tanggal sekarang kurang dari 16
        $start = new DateTime($dt->format('Y-m-16')); // awalnya set tanggal 16 bulan ini
        $start->modify('-1 month'); // mundur 1 bulan, jadi awal periode = tanggal 16 bulan sebelumnya
        $end = new DateTime($start->format('Y-m-15')); // set tanggal 15
        $end->modify('+1 month'); // maju 1 bulan, jadi akhir periode = tanggal 15 bulan ini
    }

    return [
        'start' => $start->format('Y-m-d'), // tanggal awal periode
        'end'   => $end->format('Y-m-d'),  // tanggal akhir periode
    ];
}

// ==============================
// FUNCTION MEMBUAT RANGE TANGGAL
// ==============================
function buildTanggalRange(string $startDate, string $endDate): array
{
    $dates = [];  // array kosong untuk menampung semua tanggal
    $start = new DateTime($startDate); // object DateTime awal
    $end   = new DateTime($endDate);  // object DateTime akhir
    while ($start <= $end) {  // loop selama tanggal start belum melewati end
        $dates[] = $start->format('Y-m-d'); // simpan tanggal ke array
        $start->modify('+1 day'); // maju 1 hari
    }

    return $dates; // kembalikan array semua tanggal
}
// ==============================
// FUNCTION GABUNGKAN RIWAYAT DENGAN RANGE TANGGAL
// ==============================
function buildMergedAbsensiRows(array $tanggalRange, array $rowsAsli): array
{
    $map = []; // map untuk menyimpan data absensi berdasarkan tanggal
    foreach ($rowsAsli as $r) { // loop semua data asli dari database
        $tgl = trim((string)($r['tanggal'] ?? '')); // ambil tanggal dari row
        if ($tgl !== '' && !isset($map[$tgl])) {  // kalau tanggal tidak kosong dan belum ada di map
            $map[$tgl] = $r; // simpan row ke map dengan key tanggal
        }
    }

   $merged = []; 
    // array hasil gabungan

    foreach ($tanggalRange as $tgl) { // loop semua tanggal dalam periode
        $merged[] = $map[$tgl] ?? [
            'tanggal'     => $tgl, // isi tanggal sesuai range
            'user_id'     => '', // kosong kalau tidak ada data
            'nama'        => '',
            'jam_masuk'   => '',
            'jam_keluar'  => '',
            'status'      => '',
            'keterangan'  => '',
            'mac_address' => ''
        ];
        // kalau tanggal itu ada datanya di map → pakai data asli
        // kalau tidak ada → buat row kosong
    }

    return $merged;  // kembalikan hasil gabungan
}

// ==============================
// FUNCTION HITUNG TOTAL STATUS
// ==============================
function getCountStatus($conn, $table, $user_id, $status)
{
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS total
        FROM {$table}
        WHERE user_id = ?
          AND LOWER(TRIM(COALESCE(status, ''))) = ?
    ");
    // COUNT(*) = menghitung jumlah baris
    // AS total = alias hasil hitung jadi total
    // COALESCE(status, '') = kalau status null, ganti string kosong
    // TRIM = hapus spasi
    // LOWER = ubah ke huruf kecil
    // dibandingkan dengan status yang dikirim

    if (!$stmt) {
        die("Prepare count status gagal: " . mysqli_error($conn)); // kalau prepare gagal
    }

    mysqli_stmt_bind_param($stmt, "ss", $user_id, $status); // bind user_id dan status

    executeOrDie($stmt, "Execute count status gagal"); // jalankan query

    $result = mysqli_stmt_get_result($stmt); // ambil hasil query

    $total = 0; // default total 0

    if ($result && $row = mysqli_fetch_assoc($result)) {  // kalau query berhasil dan ada row
        $total = (int)$row['total']; // ambil nilai total
    }

    mysqli_stmt_close($stmt); // tutup statement

    return $total;  // kembalikan total
}

// ==============================
// AMBIL IP DAN MAC ADDRESS USER
// ==============================
$ip = $_SERVER['REMOTE_ADDR'] ?? ''; // REMOTE_ADDR = IP address user yang mengakses

$mac_address = getMacAddress($ip); // panggil function getMacAddress berdasarkan IP

if ($mac_address === '') {  // kalau MAC tidak berhasil terbaca
    $mac_address = 'Tidak terbaca'; // isi default
}

/*
|--------------------------------------------------------------------------
| AMBIL PROFIL DARI USERS
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| AMBIL PROFIL DARI USERS
|--------------------------------------------------------------------------
*/
$stmtUser = mysqli_prepare($conn, "
    SELECT
        COALESCE(name, '') AS name,
        COALESCE(role, '') AS role,
        COALESCE(position, '') AS position,
        COALESCE(project, '') AS project,
        COALESCE(jenis_jam_kerja, '') AS jenis_jam_kerja
    FROM {$TABEL_USERS}
    WHERE user_id = ?
    LIMIT 1
");
// query ambil profil user dari tabel users
// COALESCE(...,'') = kalau null jadi string kosong
// filter berdasarkan user_id
// LIMIT 1 = ambil satu data

if (!$stmtUser) {
    die("Prepare user gagal: " . mysqli_error($conn)); // kalau prepare query gagal
}

mysqli_stmt_bind_param($stmtUser, "s", $user_id); // bind user_id ke placeholder query

executeOrDie($stmtUser, "Execute user gagal"); // jalankan query

$resultUser = mysqli_stmt_get_result($stmtUser); // ambil hasil query user

$userProfile = ($resultUser && mysqli_num_rows($resultUser) > 0) ? mysqli_fetch_assoc($resultUser) : [];
// kalau ada data → ambil row user
// kalau tidak ada → array kosong

mysqli_stmt_close($stmtUser); // tutup statement

if (!empty($userProfile['name'])) { // kalau name dari database tidak kosong
    $nama = trim($userProfile['name']); // update variabel nama dari database
    $_SESSION['nama'] = $nama; // simpan ulang ke session nama
    $_SESSION['name'] = $nama; // simpan juga ke session name
}

if (!empty($userProfile['role'])) { // kalau role tidak kosong
    $_SESSION['role'] = trim($userProfile['role']); // update role di session
}

if ($nama === '') { // kalau nama tetap kosong
    $nama = 'Karyawan'; // isi default
}

/*
|--------------------------------------------------------------------------
| DATA HARI INI
|--------------------------------------------------------------------------
*/
$dataHariIni = getDataHariIni($conn, $TABEL_ABSENSI, $user_id, $tgl_hari_ini); // ambil data absensi user untuk hari ini

/*
|--------------------------------------------------------------------------
| BLOK ABSENSI JIKA STATUS NON-HADIR
|--------------------------------------------------------------------------
*/
$statusHariIniTampil = strtolower(trim((string)($dataHariIni['status'] ?? ''))); 
// ambil status hari ini
// trim = buang spasi
// strtolower = ubah jadi huruf kecil

$blokAbsensi = in_array($statusHariIniTampil, ['izin', 'cuti', 'sakit', ], true); 
// in_array() = cek apakah status ada dalam array
// kalau status izin/cuti/sakit/ → absensi diblok

/*
|--------------------------------------------------------------------------
| PROSES ABSEN MASUK / PULANG
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi'])) { // kalau request dari form POST dan field aksi ada

    $aksi = trim((string)$_POST['aksi']); // ambil nilai aksi, misalnya "masuk" atau "pulang"

    $dataHariIniProses = getDataHariIni($conn, $TABEL_ABSENSI, $user_id, $tgl_hari_ini); // ambil ulang data hari ini untuk proses terbaru

    $statusHariIniProses = strtolower(trim((string)($dataHariIniProses['status'] ?? ''))); // ambil status terbaru

    if (in_array($statusHariIniProses, ['izin', 'cuti', 'sakit', 'lembur'], true)) { // kalau status termasuk non-hadir
        $pesan = "Hari ini status kamu {$statusHariIniProses}, jadi absensi dinonaktifkan."; // set pesan info bahwa absensi tidak bisa dipakai
    } else { // kalau status normal
        if ($aksi === 'masuk') {  // kalau tombol yang ditekan adalah absen masuk
            if ($dataHariIniProses && !isJamKosong($dataHariIniProses['jam_masuk'] ?? '')) { // kalau data hari ini sudah ada dan jam_masuk sudah terisi
                $pesan = "Absen masuk hari ini sudah dilakukan."; // tampilkan pesan bahwa sudah absen masuk
            } else { // kalau belum absen masuk
                if ($dataHariIniProses) { // kalau row hari ini sudah ada (mungkin dibuat dari izin/sakit/dll)
                    $idRow = (int)($dataHariIniProses['id'] ?? 0); // ambil id row untuk update
                    $stmtMasuk = mysqli_prepare($conn, "
                        UPDATE {$TABEL_ABSENSI}
                        SET
                            nama = ?,
                            jam_masuk = ?,
                            status = 'hadir',
                            mac_address = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                      // query UPDATE data absensi
                    // isi nama
                    // isi jam_masuk
                    // ubah status jadi hadir
                    // simpan mac_address
                    // updated_at pakai waktu sekarang database
                    // filter row berdasarkan id

                    if (!$stmtMasuk) {
                        die("Prepare update masuk gagal: " . mysqli_error($conn)); // kalau prepare gagal
                    }

                    mysqli_stmt_bind_param(
                        $stmtMasuk,
                        "sssi",
                        $nama,
                        $jam_sekarang,
                        $mac_address,
                        $idRow
                    ); // bind parameter:
                    // s = nama
                    // s = jam_sekarang
                    // s = mac_address
                    // i = idRow (integer)

                    executeOrDie($stmtMasuk, "Execute update masuk gagal"); // jalankan update
                    mysqli_stmt_close($stmtMasuk); // tutup statement

                    echo "<script>alert('Absen masuk berhasil diupdate'); window.location='" . e($selfPage) . "';</script>";
                     // tampilkan alert sukses
                    // lalu reload halaman yang sama
                    exit;  // hentikan script
                } else { // kalau row absensi hari ini belum ada sama sekali
                    $stmtMasuk = mysqli_prepare($conn, "
                        INSERT INTO {$TABEL_ABSENSI}
                        (
                            tanggal,
                            user_id,
                            nama,
                            jam_masuk,
                            jam_keluar,
                            status,
                            keterangan,
                            mac_address,
                            created_at,
                            updated_at
                        )
                        VALUES (?, ?, ?, ?, NULL, 'hadir', '', ?, NOW(), NOW())
                    ");
                        // query INSERT absensi masuk baru
                    // tanggal = hari ini
                    // user_id = id user
                    // nama = nama karyawan
                    // jam_masuk = jam sekarang
                    // jam_keluar = NULL
                    // status = hadir
                    // keterangan = kosong
                    // mac_address = hasil pembacaan
                    // created_at dan updated_at = NOW()

                    if (!$stmtMasuk) {
                        die("Prepare insert masuk gagal: " . mysqli_error($conn));// kalau prepare gagal
                    }

                    mysqli_stmt_bind_param(
                        $stmtMasuk,
                        "sssss",
                        $tgl_hari_ini,
                        $user_id,
                        $nama,
                        $jam_sekarang,
                        $mac_address
                    );
                    // bind 5 parameter string:
                    // tanggal
                    // user_id
                    // nama
                    // jam_masuk
                    // mac_address

                    executeOrDie($stmtMasuk, "Execute insert masuk gagal"); // jalankan insert
                    mysqli_stmt_close($stmtMasuk); // tutup statement
                    echo "<script>alert('Absen masuk berhasil disimpan'); window.location='" . e($selfPage) . "';</script>"; // alert sukses + reload halaman
                    exit; // hentikan script
                }
            }
        }

        if ($aksi === 'pulang') { // kalau aksi adalah absen pulang
            if (!$dataHariIniProses || isJamKosong($dataHariIniProses['jam_masuk'] ?? '')) { // kalau belum ada data hari ini atau jam_masuk kosong
                $pesan = "Kamu belum absen masuk hari ini.";   // tidak boleh pulang sebelum masuk
            } elseif (!isJamKosong($dataHariIniProses['jam_keluar'] ?? '')) { // kalau jam_keluar sudah terisi
                $pesan = "Absen pulang hari ini sudah dilakukan.";   // berarti sudah absen pulang
            } else {  // kalau boleh absen pulang
                $idRow = (int)($dataHariIniProses['id'] ?? 0);  // ambil id row

                $stmtPulang = mysqli_prepare($conn, "
                    UPDATE {$TABEL_ABSENSI}
                    SET
                        jam_keluar = ?,
                        mac_address = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                // query update untuk absen pulang
                // isi jam_keluar
                // update mac_address
                // update updated_at
                // filter by id

                if (!$stmtPulang) {
                    die("Prepare absen pulang gagal: " . mysqli_error($conn));  // kalau prepare gagal
                }

                mysqli_stmt_bind_param($stmtPulang, "ssi", $jam_sekarang, $mac_address, $idRow);
                  // bind:
                // s = jam_sekarang
                // s = mac_address
                // i = id row
                executeOrDie($stmtPulang, "Execute absen pulang gagal");  // jalankan update
                mysqli_stmt_close($stmtPulang); // tutup statement

                echo "<script>alert('Absen pulang berhasil'); window.location='" . e($selfPage) . "';</script>"; // alert sukses + reload halaman
                exit;  // hentikan program
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL ULANG DATA HARI INI
|--------------------------------------------------------------------------
*/
$dataHariIni = getDataHariIni($conn, $TABEL_ABSENSI, $user_id, $tgl_hari_ini); // ambil ulang data setelah proses masuk/pulang agar data terbaru tampil
$statusHariIniTampil = strtolower(trim((string)($dataHariIni['status'] ?? '')));// ambil ulang status terbaru hari ini
$blokAbsensi = in_array($statusHariIniTampil, ['izin', 'cuti', 'sakit'], true); // hitung ulang apakah tombol absensi harus diblok

/*
|--------------------------------------------------------------------------
| PERIODE & RIWAYAT
|--------------------------------------------------------------------------
*/
$periode = getPeriode16to15($tgl_hari_ini); // tentukan periode laporan dari tanggal 16 s.d. 15
$periodeStart = $periode['start']; // tanggal awal periode
$periodeEnd   = $periode['end'];   // tanggal akhir periode
$tanggalRange = buildTanggalRange($periodeStart, $periodeEnd); // buat array semua tanggal dalam periode tersebut
$datePeriode = date('d/m/Y', strtotime($periodeStart)) . ' - ' . date('d/m/Y', strtotime($periodeEnd));// buat format tampilan periode misalnya 16/03/2026 - 15/04/2026
$stmtPeriode = mysqli_prepare($conn, "
    SELECT
        tanggal,
        user_id,
        nama,
        jam_masuk,
        jam_keluar,
        status,
        keterangan,
        mac_address
    FROM {$TABEL_ABSENSI}
    WHERE user_id = ?
      AND tanggal BETWEEN ? AND ?
    ORDER BY tanggal ASC, id ASC
");
// query ambil riwayat absensi periode tertentu
// BETWEEN ? AND ? = dari tanggal awal sampai tanggal akhir
// ORDER BY tanggal ASC = urut dari tanggal paling awal

if (!$stmtPeriode) {
    die("Prepare periode gagal: " . mysqli_error($conn)); // kalau prepare gagal
}

mysqli_stmt_bind_param($stmtPeriode, "sss", $user_id, $periodeStart, $periodeEnd); // bind user_id, tanggal awal, tanggal akhir
executeOrDie($stmtPeriode, "Execute periode gagal"); // jalankan query periode
$resultPeriode = mysqli_stmt_get_result($stmtPeriode);// ambil hasil query

$rowsPeriodeAsli = []; // array kosong untuk menampung hasil asli dari database

while ($row = mysqli_fetch_assoc($resultPeriode)) { // loop semua row hasil query
    $rowsPeriodeAsli[] = $row;  // simpan tiap row ke array
}
mysqli_stmt_close($stmtPeriode); // tutup statement
$listPeriode = buildMergedAbsensiRows($tanggalRange, $rowsPeriodeAsli); 
// gabungkan data asli dengan seluruh range tanggal
// hasilnya: tanggal yang tidak ada absensi tetap muncul sebagai row kosong

/*
|--------------------------------------------------------------------------
| RINGKASAN
|--------------------------------------------------------------------------
*/
$hadir  = getCountStatus($conn, $TABEL_ABSENSI, $user_id, 'hadir'); 
// hitung total status hadir

$izin   = getCountStatus($conn, $TABEL_ABSENSI, $user_id, 'izin'); 
// hitung total status izin

$cuti   = getCountStatus($conn, $TABEL_ABSENSI, $user_id, 'cuti'); 
// hitung total status cuti

$sakit  = getCountStatus($conn, $TABEL_ABSENSI, $user_id, 'sakit'); 
// hitung total status sakit
?>
<!DOCTYPE html> <!-- deklarasi dokumen HTML5 -->
<html lang="id"> <!-- elemen root HTML, bahasa Indonesia -->
<head> <!-- bagian kepala HTML -->
<meta charset="UTF-8" /> <!-- encoding karakter UTF-8 -->
<meta name="viewport" content="width=device-width, initial-scale=1.0" /> <!-- agar responsive di HP -->
<title>Absensi Karyawan - PT MEINDO</title> <!-- judul tab browser -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"> <!-- import font Poppins -->

<style> /* awal CSS */
  :root{ /* variabel global CSS */
    --primary:#1f82ff; /* warna biru utama */
    --primary2:#0d67e6; /* warna biru kedua */
    --bg:#eef4fb; /* warna background */
    --text:#18202a; /* warna teks utama */
    --muted:#7c8ca5; /* warna teks abu */
    --white:#ffffff; /* warna putih */
    --line:#e7edf5; /* warna border/garis */
    --success:#21b35b; /* warna hijau sukses */
    --warning:#ffb020; /* warna oranye warning */
    --danger:#ef4444; /* warna merah danger */
    --shadow:0 12px 30px rgba(24, 54, 95, 0.10); /* bayangan card */
  }

 *{margin:0;padding:0;box-sizing:border-box} 
  /* reset semua elemen:
     margin 0
     padding 0
     box-sizing border-box agar ukuran lebih mudah diatur */

  html, body{width:100%;overflow-x:hidden} 
  /* html dan body lebar penuh
     overflow-x hidden agar tidak ada scroll horizontal */

  body{
    font-family:'Poppins',sans-serif; /* font utama */
    background:linear-gradient(180deg, #dfeefd 0%, #eef4fb 30%, #eef4fb 100%); /* background gradasi */
    color:var(--text); /* warna teks default */
    min-height:100vh; /* tinggi minimal 1 layar penuh */
  }

  .app{width:100%;min-height:100vh;background:var(--bg);position:relative;overflow-x:hidden}
  /* .app = pembungkus utama halaman
     width 100% = lebar penuh
     min-height 100vh = tinggi minimal 1 layar
     background = warna latar
     position relative = supaya bisa dipakai z-index/posisi relatif
     overflow-x hidden = hilangkan scroll samping */

  .topBlue{
    background:linear-gradient(180deg, var(--primary), var(--primary2)); /* header gradasi biru */
    padding:18px 0 95px; /* jarak dalam atas kanan/kiri bawah */
    border-bottom-left-radius:32px; /* sudut kiri bawah melengkung */
    border-bottom-right-radius:32px; /* sudut kanan bawah melengkung */
    color:#fff; /* warna teks putih */
    position:relative; /* posisi relatif */
  }

  .topInner,.mainContent{width:min(1600px, calc(100% - 40px));margin:0 auto}
  /* topInner dan mainContent:
     width = ambil yang lebih kecil antara 1600px atau 100%-40px
     margin 0 auto = rata tengah */

  .topRow{display:flex;justify-content:space-between;align-items:center;gap:12px}
  /* baris atas:
     display flex = susun horizontal
     justify-content space-between = kiri dan kanan berjauhan
     align-items center = rata tengah vertikal
     gap 12px = jarak antar elemen */

  .profileBox{display:flex;align-items:center;gap:12px;min-width:0}
  /* profileBox = kotak profil user di kiri atas */

  .avatar{
    width:48px;height:48px;border-radius:50%;
    background:rgba(255,255,255,.22);
    border:2px solid rgba(255,255,255,.35);
    display:flex;align-items:center;justify-content:center;
    font-size:18px;font-weight:800;flex-shrink:0;
  }
  /* avatar = lingkaran inisial user */

  .profileText h2{font-size:15px;font-weight:700;line-height:1.2}
  /* nama user */

  .profileText p{font-size:11px;opacity:.92;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  /* user_id user */
  .notif{
    width:40px;height:40px;border-radius:50%;
    background:rgba(255,255,255,.17);
    border:1px solid rgba(255,255,255,.22);
    display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;
  }
  /* icon notifikasi */

  .brandCenter{text-align:center;margin-top:16px}
  /* area judul tengah */

  .brandCenter .logoText{font-size:28px;font-weight:900;line-height:1;letter-spacing:.4px}
  /* judul besar */

  .brandCenter .logoSub{margin-top:6px;font-size:12px;opacity:.92}
  /* subjudul */

  .mainContent{margin:-74px auto 0;padding:0 0 24px;position:relative;z-index:2}
  /* konten utama dinaikkan ke atas menimpa header */

  .card{background:var(--white);border-radius:22px;box-shadow:var(--shadow)}
  /* style card umum */

  .summaryCard,.actionCard,.historyCard,.menuSection{width:100%}
  /* semua section lebar penuh */

  .summaryCard{padding:16px;margin-bottom:16px}
  /* card ringkasan */

  .summaryStatus{margin-bottom:14px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:#fbfdff}
  /* box status hari ini */

  .summaryStatus .label{font-size:11px;color:var(--muted);margin-bottom:4px}
  /* label kecil */

  .summaryStatus .value{font-size:15px;font-weight:800;color:#213247}
  /* nilai status */

  .progressWrap{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
  /* grid progress 2 kolom */

  .progressItem{background:#fbfdff;border:1px solid var(--line);border-radius:16px;padding:12px 10px;text-align:center}
  /* item progress */

  .progressItem .label{font-size:11px;color:var(--muted);margin-bottom:6px}
  /* label progress */

  .progressItem .value{font-size:18px;font-weight:800;color:#213247;margin-bottom:8px}
  /* angka progress */

  .bar{height:5px;border-radius:999px;background:#ebf1f8;overflow:hidden}
  /* background batang progress */

  .bar span{display:block;height:100%;border-radius:999px}
  /* isi batang progress */

  .green span{ background:var(--success); width:80%; }
  /* progress hijau, lebar 80% */

  .blue span{ background:var(--primary); width:45%; }
  /* progress biru */

  .orange span{ background:var(--warning); width:20%; }
  /* progress oranye */

  .red span{ background:#ef4444; width:55%; }
  /* progress merah */

  .menuSection{margin-bottom:16px}
  /* section menu */

  .sectionTitle{font-size:14px;font-weight:800;color:#2c3a4d;margin-bottom:12px;padding:0 2px}
  /* judul section */

  .menuGrid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
  /* grid menu 4 kolom */

  .menuItem{
    background:var(--white);border-radius:18px;padding:14px 8px;text-align:center;
    box-shadow:var(--shadow);text-decoration:none;color:var(--text);transition:.15s ease;
  }
  /* item menu */

  .menuItem:hover{transform:translateY(-2px)}
  /* hover menu naik sedikit */

  .menuIcon{
    width:46px;height:46px;border-radius:14px;margin:0 auto 8px;display:flex;
    align-items:center;justify-content:center;font-size:22px;
    background:linear-gradient(180deg,#eef6ff,#dcecff);border:1px solid #dce9f9;
  }
  /* icon menu */

  .menuItem span{font-size:11px;font-weight:600;color:#49586e;line-height:1.35;display:block}
  /* teks menu */

  .actionCard{padding:14px;margin-bottom:16px}
  /* card aksi absen */

  .clockBox{background:#f8fbff;border:1px solid var(--line);border-radius:16px;padding:12px;text-align:center;margin-bottom:12px}
  /* box jam server */

  .clockLabel{font-size:11px;color:var(--muted);margin-bottom:4px}
  /* label jam */

  .clockValue{font-size:16px;font-weight:800;color:#203349}
  /* nilai jam */

  .statusToday{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
  /* grid status masuk/pulang */

  .statusMini{background:#fbfdff;border:1px solid var(--line);border-radius:14px;padding:12px}
  /* box mini status */

  .statusMini .small{font-size:11px;color:var(--muted);margin-bottom:4px}
  /* label mini */

  .statusMini .big{font-size:14px;font-weight:800;color:#25384a}
  /* nilai mini */

  .alert{
    margin-bottom:12px;padding:12px 14px;border-radius:14px;background:#eef7ff;color:#1d4f8f;
    border:1px solid #d8eaff;font-size:12px;line-height:1.55;font-weight:500;
  }
  /* box alert / pesan */

  .actions{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  /* grid tombol absen */

  .btn{
    border:none;border-radius:16px;padding:14px;font-family:'Poppins',sans-serif;
    font-size:14px;font-weight:800;cursor:pointer;color:#fff;transition:.15s ease;
  }
  /* style tombol umum */

  .btn:hover{transform:translateY(-1px)}
  /* hover tombol */

  .btnMasuk{background:linear-gradient(180deg,#22c55e,#16a34a)}
  /* tombol masuk hijau */

  .btnPulang{background:linear-gradient(180deg,#fb7185,#ef4444)}
  /* tombol pulang merah */

  .btnDisabled{background:linear-gradient(180deg,#94a3b8,#64748b);cursor:not-allowed;opacity:.8}
  /* tombol nonaktif */

  .historyCard{padding:14px;margin-bottom:18px}
  /* card riwayat */

  .historyHead{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px}
  /* header riwayat */

  .historyHead h3{font-size:14px;font-weight:800;color:#2b3a4e}
  /* judul riwayat */

  .periodeInfo{
    margin-bottom:12px;
    padding:10px 12px;
    border-radius:12px;
    background:#f8fbff;
    border:1px solid var(--line);
    font-size:12px;
    color:#44556d;
    font-weight:600;
  }
  /* box info periode */

  .tableWrap{
    overflow-x:auto;
    border:1px solid var(--line);
    border-radius:16px;
    background:#fff;
  }
  /* pembungkus tabel agar bisa scroll horizontal */

  table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
    min-width:1200px;
  }
  /* tabel utama */

  th, td{ border:1px solid #e2e8f0; }
  /* border sel tabel */

  th{
    background:#e5e7eb;
    color:#374151;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.4px;
    padding:12px 10px;
    text-align:left;
  }
  /* header tabel */

  td{
    font-size:12px;
    color:#314256;
    padding:12px 10px;
    vertical-align:top;
  }
  /* isi tabel */

  tbody tr:nth-child(even){ background:#f9fbff; }
  /* warna selang-seling pada baris genap */

  tbody tr:hover td{ background:#eef6ff; }
  /* hover row tabel */

  .empty{text-align:center;color:var(--muted);padding:18px;font-size:12px}
  /* style kalau tabel kosong */

  .logoutWrap{text-align:center;margin:12px 0 26px}
  /* pembungkus tombol logout */

  .logoutBtn{
    text-decoration:none;display:inline-block;padding:12px 18px;border-radius:999px;background:#ffffff;
    border:1px solid var(--line);color:#ef4444;font-size:13px;font-weight:800;box-shadow:var(--shadow);
  }
  /* tombol logout */

  .footer{text-align:center;font-size:11px;color:var(--muted);padding-bottom:14px}
  /* footer bawah */

  .badge{
    display:inline-block;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;
    line-height:1.2;white-space:nowrap;
  }
  /* badge status */

  .badgeHadir{background:#e8fff1;color:#16a34a}
  /* badge hadir */

  .badgeIzin{background:#eaf3ff;color:#2563eb}
  /* badge izin */

  .badgeCuti{background:#fff5df;color:#d97706}
  /* badge cuti */

  .badgeSakit{background:#ffe9e9;color:#dc2626}
  /* badge sakit */

  .badgeDefault{background:#f1f5f9;color:#475569}
  /* badge default */

  @media (max-width: 991px){
    .topInner,.mainContent{width:calc(100% - 24px)}
    .mainContent{margin:-64px auto 0}
  }
  /* responsive tablet */

  @media (max-width:768px){
    .menuGrid{grid-template-columns:repeat(3,1fr)}
  }
  /* menu jadi 3 kolom */

  @media (max-width:576px){
    .menuGrid{grid-template-columns:repeat(2,1fr)}
    .actions{grid-template-columns:1fr}
    .statusToday,.progressWrap{grid-template-columns:1fr}
    table{min-width:900px}
  }
  /* responsive HP kecil */
</style>
</head>
<body>

<div class="app"> <!-- pembungkus utama -->
  <div class="topBlue"> <!-- header biru -->
    <div class="topInner"> <!-- isi header -->
      <div class="topRow"> <!-- baris atas -->
        <div class="profileBox"> <!-- box profil -->
          <div class="avatar"><?= e(strtoupper(substr(trim($nama), 0, 1))) ?></div> 
          <!-- avatar berisi huruf pertama nama -->
          <!-- trim($nama) = hapus spasi -->
          <!-- substr(...,0,1) = ambil 1 huruf pertama -->
          <!-- strtoupper() = jadikan huruf besar -->
          <!-- e() = amankan output -->

          <div class="profileText"> <!-- teks profil -->
            <h2><?= e($nama) ?></h2> <!-- tampilkan nama user -->
            <p><?= e($user_id) ?></p> <!-- tampilkan user_id -->
          </div>
        </div>
        <div class="notif">🔔</div> <!-- icon notifikasi -->
      </div>

      <div class="brandCenter"> <!-- judul tengah -->
        <div class="logoText">HALAMAN ABSEN KARYAWAN</div> <!-- judul -->
        <div class="logoSub">PT MEINDO ELANG INDAH</div> <!-- subjudul -->
      </div>
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama -->

    <div class="card summaryCard"> <!-- card ringkasan -->
      <div class="summaryStatus"> <!-- box status hari ini -->
        <div class="label">Status Hari Ini</div> <!-- label -->
        <div class="value">
          <?= !empty($dataHariIni['status']) ? e(ucfirst($dataHariIni['status'])) : '-' ?>
          <!-- kalau status hari ini ada, tampilkan dengan huruf awal besar -->
          <!-- ucfirst() = huruf pertama jadi besar -->
          <!-- kalau kosong, tampilkan - -->
        </div>
      </div>

      <div class="progressWrap"> <!-- pembungkus progress -->
        <div class="progressItem"><div class="label">Hadir</div><div class="value"><?= (int)$hadir ?> Hari</div><div class="bar green"><span></span></div></div>
        <!-- progress hadir -->

        <div class="progressItem"><div class="label">Izin</div><div class="value"><?= (int)$izin ?> Hari</div><div class="bar blue"><span></span></div></div>
        <!-- progress izin -->

        <div class="progressItem"><div class="label">Cuti</div><div class="value"><?= (int)$cuti ?> Hari</div><div class="bar orange"><span></span></div></div>
        <!-- progress cuti -->

        <div class="progressItem"><div class="label">Sakit</div><div class="value"><?= (int)$sakit ?> Hari</div><div class="bar red"><span></span></div></div>
        <!-- progress sakit -->
      </div>
    </div>

    <div class="menuSection"> <!-- section menu utama -->
      <div class="sectionTitle">Menu Utama</div> <!-- judul menu -->

      <div class="menuGrid"> <!-- grid menu -->
        <a href="halaman_izin.php" class="menuItem"><div class="menuIcon">📝</div><span>Izin</span></a>
        <!-- menu izin -->

        <a href="halaman_sakit.php" class="menuItem"><div class="menuIcon">🤒</div><span>Sakit</span></a>
        <!-- menu sakit -->

        <a href="halaman_cuti.php" class="menuItem"><div class="menuIcon">🏖️</div><span>Cuti</span></a>
        <!-- menu cuti -->

        <a href="download_fingerprint_report.php?format=pdf&base_date=<?= urlencode($periodeStart) ?>" class="menuItem" target="_blank">
          <!-- link download PDF -->
          <!-- urlencode() = mengamankan tanggal untuk URL -->
          <div class="menuIcon">⬇️</div>
          <span>Download PDF</span>
        </a>

        <a href="download_fingerprint_report.php?format=excel&base_date=<?= urlencode($periodeStart) ?>" class="menuItem" target="_blank">
          <!-- link download Excel -->
          <div class="menuIcon">📊</div>
          <span>Download Excel</span>
        </a>
      </div>
    </div>

    <div class="card actionCard"> <!-- card aksi absensi -->
      <div class="clockBox"> <!-- box waktu -->
        <div class="clockLabel">Waktu Server</div> <!-- label waktu -->
        <div class="clockValue" id="clockText" data-serverms="<?= (int)$serverMs ?>">Memuat waktu...</div>
        <!-- id=clockText = target JavaScript -->
        <!-- data-serverms = waktu server dalam milidetik -->
      </div>

      <div class="statusToday"> <!-- status masuk/pulang hari ini -->
        <div class="statusMini">
          <div class="small">Jam Masuk Hari Ini</div>
          <div class="big"><?= !isJamKosong($dataHariIni['jam_masuk'] ?? '') ? e($dataHariIni['jam_masuk']) : '-' ?></div>
          <!-- tampilkan jam masuk kalau ada -->
        </div>
        <div class="statusMini">
          <div class="small">Jam Pulang Hari Ini</div>
          <div class="big"><?= !isJamKosong($dataHariIni['jam_keluar'] ?? '') ? e($dataHariIni['jam_keluar']) : '-' ?></div>
          <!-- tampilkan jam pulang kalau ada -->
        </div>
      </div>

      <?php if ($pesan !== ""): ?> <!-- kalau ada pesan -->
        <div class="alert"><?= e($pesan) ?></div> <!-- tampilkan pesan -->
      <?php endif; ?>

      <?php if ($blokAbsensi): ?> <!-- kalau absensi diblok -->
        <div class="alert">
          Hari ini status kamu <b><?= e(ucfirst($statusHariIniTampil)) ?></b>, jadi tombol absensi dinonaktifkan.
        </div>

        <div class="actions">
          <button class="btn btnDisabled" type="button" disabled>Absen Masuk</button>
          <!-- tombol nonaktif -->

          <button class="btn btnDisabled" type="button" disabled>Absen Pulang</button>
          <!-- tombol nonaktif -->
        </div>
      <?php else: ?> <!-- kalau absensi tidak diblok -->
        <form method="POST" action="<?= e($selfPage) ?>" class="actions">
          <!-- form kirim POST ke halaman ini sendiri -->

          <button class="btn btnMasuk" type="submit" name="aksi" value="masuk">Absen Masuk</button>
          <!-- tombol absen masuk -->

          <button class="btn btnPulang" type="submit" name="aksi" value="pulang">Absen Pulang</button>
          <!-- tombol absen pulang -->
        </form>
      <?php endif; ?>
    </div>

    <div class="card historyCard"> <!-- card riwayat absensi -->
      <div class="historyHead">
        <h3>Riwayat Absensi</h3> <!-- judul riwayat -->
      </div>

      <div class="periodeInfo">
        Periode tabel: <b><?= e($datePeriode) ?></b>
        <!-- tampilkan rentang periode -->
      </div>

      <div class="tableWrap"> <!-- pembungkus tabel -->
        <table> <!-- tabel riwayat -->
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>User ID</th>
              <th>Nama</th>
              <th>Jam Masuk</th>
              <th>Jam Keluar</th>
              <th>Status</th>
              <th>Keterangan</th>
              <th>MAC</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($listPeriode)): ?> <!-- kalau data riwayat ada -->
              <?php foreach($listPeriode as $r): ?> <!-- loop setiap row -->
                <?php
                  $statusText = strtolower(trim((string)($r['status'] ?? '')));
                  // ambil status row

                  $badgeClass = 'badgeDefault';
                  // default badge

                  if ($statusText === 'hadir') $badgeClass = 'badgeHadir';
                  elseif ($statusText === 'izin') $badgeClass = 'badgeIzin';
                  elseif ($statusText === 'cuti') $badgeClass = 'badgeCuti';
                  elseif ($statusText === 'sakit') $badgeClass = 'badgeSakit';
                  // pilih warna badge sesuai status

                  $ket = trim((string)($r['keterangan'] ?? ''));
                  // ambil keterangan
                ?>
                <tr>
                  <td><?= e(date('d/m/Y', strtotime($r['tanggal'] ?? $tgl_hari_ini))) ?></td>
                  <!-- format tanggal -->

                  <td><?= e($r['user_id'] ?? '') ?></td>
                  <!-- user id -->

                  <td><?= e($r['nama'] ?? '') ?></td>
                  <!-- nama -->

                  <td><?= e(!isJamKosong($r['jam_masuk'] ?? '') ? $r['jam_masuk'] : '') ?></td>
                  <!-- jam masuk -->

                  <td><?= e(!isJamKosong($r['jam_keluar'] ?? '') ? $r['jam_keluar'] : '') ?></td>
                  <!-- jam keluar -->

                  <td>
                    <?php if (!empty($r['status'])): ?> <!-- kalau status ada -->
                      <span class="badge <?= e($badgeClass) ?>"><?= e($r['status']) ?></span>
                      <!-- tampilkan badge -->
                    <?php endif; ?>
                  </td>

                  <td><?= e($ket) ?></td>
                  <!-- keterangan -->

                  <td><?= e($r['mac_address'] ?? '') ?></td>
                  <!-- MAC address -->
                </tr>
              <?php endforeach; ?>
            <?php else: ?> <!-- kalau tidak ada data -->
              <tr>
                <td colspan="8" class="empty">Belum ada data absensi.</td>
                <!-- colspan=8 = gabung 8 kolom -->
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="logoutWrap"> <!-- area logout -->
      <a class="logoutBtn" href="?logout=1">Logout</a>
      <!-- link logout -->
    </div>

    <div class="footer">
      © <?= date("Y"); ?> Sistem Absensi
      <!-- tampilkan tahun otomatis -->
    </div>
  </div>
</div>

<script> 
(() => { 
  // function anonim langsung jalan otomatis (IIFE)

  const el = document.getElementById('clockText'); 
  // ambil elemen jam dari HTML

  if (!el) return; 
  // kalau elemen tidak ada, stop

  const serverMs = Number(el.dataset.serverms || 0); 
  // ambil nilai data-serverms dari HTML lalu ubah jadi angka

  const offset = serverMs - Date.now(); 
  // hitung selisih waktu server dengan waktu browser user

  const pad = (n) => String(n).padStart(2,'0'); 
  // function pad = menambah angka 0 di depan
  // contoh 7 jadi 07

  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; 
  // array nama bulan Indonesia

  function tick(){ 
    // function untuk update jam setiap detik
    const now = new Date(Date.now() + offset); 
    // waktu sekarang browser ditambah offset agar sinkron dengan server

    const dd = pad(now.getDate()); 
    // ambil tanggal

    const mm = bulan[now.getMonth()]; 
    // ambil nama bulan

    const yy = now.getFullYear(); 
    // ambil tahun

    const hh = pad(now.getHours()); 
    // ambil jam

    const mi = pad(now.getMinutes()); 
    // ambil menit

    const ss = pad(now.getSeconds()); 
    // ambil detik

    el.textContent = `${dd} ${mm} ${yy}, ${hh}:${mi}:${ss} WIB`; 
    // tampilkan hasil waktu ke elemen clockText
  }

  tick(); 
  // jalankan sekali langsung supaya waktu muncul

  const delay = 1000 - ((Date.now() + offset) % 1000); 
  // hitung sisa waktu menuju detik berikutnya
  // supaya update pas di pergantian detik

  setTimeout(() => { 
    // tunggu sampai detik pas
    tick(); 
    // update lagi

    setInterval(tick, 1000); 
    // lalu jalankan tick tiap 1 detik
  }, delay);
})();
</script>

</body> <!-- penutup body -->
</html> <!-- penutup html -->