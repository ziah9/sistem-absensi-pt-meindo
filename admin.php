<?php // membuka tag PHP

//libraru yang digunakan
require __DIR__ . '/vendor/autoload.php'; // require = memanggil file wajib, __DIR__ = folder file saat ini, /vendor/autoload.php = file autoload Composer untuk memuat library otomatis
use PhpOffice\PhpSpreadsheet\Spreadsheet; // memakai class Spreadsheet dari library PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Writer\Xlsx; // memakai class Writer Xlsx untuk membuat file Excel .xlsx
use PhpOffice\PhpSpreadsheet\Style\Alignment; // memakai class Alignment untuk pengaturan perataan teks di Excel
use PhpOffice\PhpSpreadsheet\Style\Border; // memakai class Border untuk pengaturan garis/border di Excel
use PhpOffice\PhpSpreadsheet\Style\Fill; // memakai class Fill untuk pengaturan warna latar sel di Excel
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing; // memakai class Drawing untuk menambahkan gambar/logo ke worksheet Excel

//cek session
if (session_status() === PHP_SESSION_NONE) session_start(); // cek apakah session belum aktif, kalau belum maka aktifkan session


include "koneksi.php"; // memanggil file koneksi database
date_default_timezone_set("Asia/Jakarta"); // mengatur zona waktu ke WIB

// LOGOUT
if (isset($_GET['logout']) && $_GET['logout'] == "1") { // cek apakah ada parameter logout di URL dan nilainya 1
    session_destroy(); // hapus semua session user
    header("Location: login_admin.php"); // arahkan kembali ke halaman login admin
    exit; // hentikan program
}

// PROTEKSI LOGIN ADMIN
if (!isset($_SESSION['admin_login']) || $_SESSION['admin_login'] !== true) { // cek apakah admin belum login
    header("Location: login_admin.php"); // kalau belum login, paksa ke halaman login
    exit; // hentikan program
}

//penegcekan role
if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'admin') { // cek apakah role bukan admin
    header("Location: login_admin.php"); // kalau bukan admin, arahkan ke login admin
    exit; // hentikan program
}

//mengetahui siapa yang login dan halaman apa yang sedang dibuka
$user_id = trim($_SESSION['user_id'] ?? ''); //ambil user_id dari session, kalau tidak ada isi kosong, lalu trim buang spasi
$nama    = trim($_SESSION['nama'] ?? ''); //ambil nama dari session
$selfPage = basename($_SERVER['PHP_SELF']); //ambil nama file PHP yang sedang dibuka, misalnya halaman_absen_admin.php

//pengaman supaya sistem tidak jalan kalau tidak ada user yang login atau session user_id hilang.
if ($user_id === '') { // kalau user_id kosong
    die("Session user_id kosong. Silakan login ulang."); // hentikan program dan tampilkan pesan error
}

//mengamankan data sebelum dimasukkan ke query SQL.
$safeUserId = mysqli_real_escape_string($conn, $user_id); // amankan user_id dari karakter khusus SQL


// AMBIL NAMA ASLI DARI USERS
//$qUserSession = mysqli_query($conn, " --menjalankan perintah SQL ke database menggunakan koneksi $conn
$qUserSession = mysqli_query($conn, " 
    SELECT 
    COALESCE(name, '') AS name,            -- ambil kolom name, kalau NULL jadi ''
    COALESCE(role, '') AS role,            -- ambil role, kalau NULL jadi ''
    COALESCE(position, '') AS position,    -- ambil jabatan/posisi user
    COALESCE(project, '') AS project,      -- ambil project user
    COALESCE(jenis_jam_kerja, '') AS jenis_jam_kerja  -- ambil jenis jam kerja
FROM users                                  -- dari tabel users
WHERE TRIM(user_id)=TRIM('$safeUserId')     -- cari user berdasarkan user_id (hapus spasi)
LIMIT 1                                     -- ambil hanya 1 data saja
");

//menyimpan data user yang ditemukan dari database ke dalam array.
$userProfile = []; // siapkan array kosong untuk profil user
if ($qUserSession && mysqli_num_rows($qUserSession) > 0) { // kalau query berhasil dan data user ditemukan
    $userProfile = mysqli_fetch_assoc($qUserSession); // ambil data user sebagai associative array
    
    //mengambil nama dari database lalu memperbarui session.
    $namaDb = trim($userProfile['name'] ?? ''); // ambil nama dari database
    $roleDb = trim($userProfile['role'] ?? ''); // ambil role dari database

    if ($namaDb !== '') { // kalau nama dari database tidak kosong
        $nama = $namaDb; // pakai nama dari database
        $_SESSION['nama'] = $namaDb; // simpan/update session nama
        $_SESSION['name'] = $namaDb; // simpan/update session name
    }
    //memperbarui role di session sesuai data terbaru dari database
    if ($roleDb !== '') { // kalau role dari database tidak kosong
        $_SESSION['role'] = $roleDb; // update role di session
    }
}

//memberi nama cadangan (default) kalau variabel $nama masih kosong
if ($nama === '') { // kalau nama masih kosong
    $nama = 'Admin'; // isi default nama menjadi Admin
}

//menyiapkan data yang akan dipakai di halaman absensi.
$tgl_hari_ini = date("Y-m-d"); // ambil tanggal hari ini format tahun-bulan-hari
$jam_sekarang = date("H:i:s"); // ambil jam saat ini format jam:menit:detik
$TABEL_ABSENSI = "admin_users"; // nama tabel absensi admin
$pesan = ""; // variabel pesan notifikasi/error
$serverMs = (int) round(microtime(true) * 1000); // ambil waktu server dalam milidetik untuk sinkronisasi jam realtime

// AMBIL IP + MAC ADDRESS
$ip = $_SERVER['REMOTE_ADDR'] ?? ''; // ambil IP address user yang mengakses
//mencari MAC Address berdasarkan IP Address.
function getMacAddress($ip) // function untuk mencoba membaca MAC address berdasarkan IP
{
    $mac = ""; // default MAC kosong, Kalau nanti MAC Address tidak ditemukan, yang dikembalikan tetap kosong.

    if ($ip === "") return $mac; // kalau IP kosong langsung return kosong, kalau IP kosong, langsung keluar dari function.

    @exec("ping -n 1 " . escapeshellarg($ip)); // ping IP 1 kali supaya masuk ke tabel ARP, @ untuk sembunyikan warning
    $arp = @shell_exec("arp -a"); // baca tabel ARP komputer/server

    //mencari MAC Address di hasil perintah
    if (!$arp) return $mac; // kalau output arp kosong, return kosong

    $lines = preg_split("/\r\n|\n|\r/", $arp); // pecah output ARP menjadi per baris

    foreach ($lines as $line) { // loop setiap baris
        if (stripos($line, $ip) !== false) { // kalau baris mengandung IP user
            $parts = preg_split('/\s+/', trim($line)); // pecah baris berdasarkan spasi
            foreach ($parts as $part) { // loop setiap potongan kata
                if (preg_match('/^([0-9a-f]{2}[-:]){5}[0-9a-f]{2}$/i', $part)) { // cek apakah formatnya MAC address
                    return $part; // kalau cocok, return MAC address
                }
            }
        }
    }

    return $mac; // kalau tidak ketemu, return kosong
}

//menjalankan function pencari MAC Address lalu memberi nilai default kalau gagal ditemukan.
$mac_address = getMacAddress($ip); // panggil function untuk baca MAC address
if ($mac_address === '') { // kalau hasil MAC kosong
    $mac_address = 'Tidak terbaca'; // isi default
}

// HELPER

//menentukan periode absensi dari tanggal 16 sampai 15 bulan berikutnya.
function getPeriode16to15(?string $baseDate = null): array // function untuk menentukan periode dari tanggal 16 sampai 15 bulan berikutnya
{
    $baseDate = $baseDate ?: date('Y-m-d'); // kalau baseDate kosong, pakai tanggal hari ini
    $dt = new DateTime($baseDate); // buat object tanggal
    $day = (int)$dt->format('d'); // ambil angka hari/tanggal

    if ($day >= 16) { // kalau tanggal sekarang 16 atau lebih
        $start = new DateTime($dt->format('Y-m-16')); // awal periode = tanggal 16 bulan ini
        $end = new DateTime($start->format('Y-m-15')); // set tanggal 15
        $end->modify('+1 month'); // akhir periode = tanggal 15 bulan depan
    } else { // kalau tanggal sekarang kurang dari 16
        $start = new DateTime($dt->format('Y-m-16')); // set tanggal 16 bulan ini
        $start->modify('-1 month'); // mundur 1 bulan = tanggal 16 bulan sebelumnya
        $end = new DateTime($start->format('Y-m-15')); // set tanggal 15
        $end->modify('+1 month'); // jadi akhir periode = tanggal 15 bulan ini
    }

    return [
        'start' => $start->format('Y-m-d'), // return tanggal awal periode
        'end'   => $end->format('Y-m-d'), // return tanggal akhir periode
    ];
}

//membuat daftar semua tanggal dalam periode.
function buildTanggalRange(string $startDate, string $endDate): array // function untuk membuat daftar semua tanggal dalam sebuah periode
{
    $dates = []; // array kosong untuk menampung tanggal
    $start = new DateTime($startDate); // tanggal awal
    $end = new DateTime($endDate); // tanggal akhir

    while ($start <= $end) { // loop selama start belum melewati end
        $dates[] = $start->format('Y-m-d'); // simpan tanggal ke array
        $start->modify('+1 day'); // maju 1 hari
    }

    return $dates; // kembalikan array daftar tanggal
}

//menggabungkan daftar tanggal dengan data absensi dari database.
function buildMergedAbsensiRows(array $tanggalRange, array $rowsAsli): array // function untuk menggabungkan daftar tanggal dengan data absensi asli
{
    $map = []; // array map untuk menyimpan data absensi berdasarkan tanggal
    foreach ($rowsAsli as $r) { // loop semua data absensi asli
        $tgl = trim($r['tanggal'] ?? ''); // ambil tanggal dari row
        if ($tgl !== '') { // kalau tanggal tidak kosong
            $map[$tgl] = $r; // simpan row dengan key tanggal
        }
    }

    $merged = []; // array hasil akhir
    foreach ($tanggalRange as $tgl) { // loop semua tanggal dalam periode
        $merged[] = $map[$tgl] ?? [ // kalau tanggal ada datanya, pakai data itu, kalau tidak ada pakai row kosong
            'tanggal' => $tgl, // isi tanggal
            'user_id' => '', // user_id kosong
            'nama' => '', // nama kosong
            'jam_masuk' => '', // jam masuk kosong
            'jam_keluar' => '', // jam keluar kosong
            'status' => '', // status kosong
            'keterangan' => '', // keterangan kosong
            'mac_address' => '', // MAC kosong
            'name' => '', // name kosong
            'position' => '', // position kosong
            'departemen' => '', // departemen kosong
            'project' => '', // project kosong
            'jenis_jam_kerja' => '', // jenis jam kerja kosong
            'nik' => '' // nik kosong
        ];
    }

    return $merged; // kembalikan hasil gabungan
}

//mengubah tanggal menjadi nama bulan Indonesia.
function bulanIndonesia(string $dateYmd): string // function ubah tanggal jadi nama bulan Indonesia
{
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ]; // array nama bulan

    $dt = new DateTime($dateYmd); // buat object tanggal
    return $bulan[(int)$dt->format('n')] . ' ' . $dt->format('Y'); // hasil misalnya April 2026
}

//mengecek apakah jam masih kosong.
function isJamKosong($jam): bool // function cek apakah jam kosong
{
    $jam = trim((string)$jam); // pastikan jadi string dan hilangkan spasi
    return $jam === '' || $jam === '00:00:00' || $jam === '00:00'; // dianggap kosong kalau string kosong atau nol
}

// AMBIL DATA HARI INI AWAL
//melihat apakah hari ini sudah ada data absensi.
$qTodayAwal = mysqli_query($conn, "
    SELECT *
    FROM $TABEL_ABSENSI
    WHERE TRIM(user_id)=TRIM('$safeUserId')
      AND tanggal='$tgl_hari_ini'
    LIMIT 1
"); // query ambil data absensi admin hari ini
$dataHariIni = ($qTodayAwal && mysqli_num_rows($qTodayAwal) > 0) ? mysqli_fetch_assoc($qTodayAwal) : null; // kalau ada data ambil, kalau tidak null

// STATUS BLOK ABSEN
$statusHariIniTampil = strtolower(trim($dataHariIni['status'] ?? '')); // ambil status hari ini dan ubah jadi huruf kecil
$blokAbsensi = in_array($statusHariIniTampil, ['izin', 'cuti', 'sakit'], true); // kalau status izin/cuti/sakit maka tombol absen diblok

// PROSES ABSEN MASUK / PULANG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi'])) { // kalau form dikirim dengan POST dan ada field aksi
    $aksi = trim($_POST['aksi']); // ambil nilai aksi: masuk atau pulang

    if ($blokAbsensi) { // kalau status hari ini memblok absensi
        $pesan = "Hari ini status kamu $statusHariIniTampil, jadi absensi dinonaktifkan."; // isi pesan
    } else { // kalau absensi boleh dipakai
        $qTodayProses = mysqli_query($conn, "
            SELECT *
            FROM $TABEL_ABSENSI
            WHERE TRIM(user_id)=TRIM('$safeUserId')
              AND tanggal='$tgl_hari_ini'
            LIMIT 1
        "); // ambil ulang data hari ini untuk proses
        $dataHariIniProses = ($qTodayProses && mysqli_num_rows($qTodayProses) > 0) ? mysqli_fetch_assoc($qTodayProses) : null; // ambil row kalau ada

        if ($aksi === 'masuk') { // kalau aksi adalah masuk
            if ($dataHariIniProses && !isJamKosong($dataHariIniProses['jam_masuk'] ?? '')) { // kalau jam masuk sudah terisi
                $pesan = "Absen masuk hari ini sudah dilakukan."; // beri pesan sudah absen masuk
            } else { // kalau belum absen masuk
                if ($dataHariIniProses) { // kalau row hari ini sudah ada
                    $idRow = (int)($dataHariIniProses['id'] ?? 0); // ambil id row

                    $stmt = mysqli_prepare($conn, "
                        UPDATE $TABEL_ABSENSI
                        SET jam_masuk = ?, nama = ?, status = 'hadir', mac_address = ?
                        WHERE id = ?
                    "); // query update jam masuk
                    if (!$stmt) {
                        die("Prepare update masuk gagal: " . mysqli_error($conn)); // error kalau prepare gagal
                    }

                    mysqli_stmt_bind_param($stmt, "sssi", $jam_sekarang, $nama, $mac_address, $idRow); // bind jam masuk, nama, MAC, dan id
                    mysqli_stmt_execute($stmt); // jalankan query update
                    mysqli_stmt_close($stmt); // tutup statement
                } else { // kalau row hari ini belum ada
                    $stmt = mysqli_prepare($conn, "
                        INSERT INTO $TABEL_ABSENSI (
                            tanggal,
                            user_id,
                            nama,
                            jam_masuk,
                            jam_keluar,
                            status,
                            keterangan,
                            mac_address
                        ) VALUES (?, ?, ?, ?, NULL, 'hadir', '', ?)
                    "); // query insert absensi masuk baru
                    if (!$stmt) {
                        die("Prepare insert masuk gagal: " . mysqli_error($conn)); // error kalau prepare gagal
                    }

                    mysqli_stmt_bind_param($stmt, "sssss", $tgl_hari_ini, $user_id, $nama, $jam_sekarang, $mac_address); // bind parameter insert
                    mysqli_stmt_execute($stmt); // jalankan insert
                    mysqli_stmt_close($stmt); // tutup statement
                }

                echo "<script>alert('Absen masuk berhasil'); window.location='" . $selfPage . "';</script>"; // tampilkan alert sukses lalu reload halaman
                exit; // hentikan program
            }
        } elseif ($aksi === 'pulang') { // kalau aksi adalah pulang
            if (!$dataHariIniProses || isJamKosong($dataHariIniProses['jam_masuk'] ?? '')) { // kalau belum absen masuk
                $pesan = "Kamu belum absen masuk hari ini."; // tampilkan pesan
            } elseif (!isJamKosong($dataHariIniProses['jam_keluar'] ?? '')) { // kalau jam pulang sudah terisi
                $pesan = "Absen pulang hari ini sudah dilakukan."; // tampilkan pesan
            } else { // kalau boleh absen pulang
                $idRow = (int)($dataHariIniProses['id'] ?? 0); // ambil id row

                $stmt = mysqli_prepare($conn, "
                    UPDATE $TABEL_ABSENSI
                    SET jam_keluar = ?, mac_address = ?
                    WHERE id = ?
                "); // query update jam pulang
                if (!$stmt) {
                    die("Prepare update pulang gagal: " . mysqli_error($conn)); // error kalau prepare gagal
                }

                mysqli_stmt_bind_param($stmt, "ssi", $jam_sekarang, $mac_address, $idRow); // bind jam pulang, MAC, dan id
                mysqli_stmt_execute($stmt); // eksekusi update
                mysqli_stmt_close($stmt); // tutup statement

                echo "<script>alert('Absen pulang berhasil'); window.location='" . $selfPage . "';</script>"; // alert sukses dan reload
                exit; // hentikan program
            }
        }
    }
}

// AMBIL ULANG DATA HARI INI SETELAH PROSES
$qToday = mysqli_query($conn, "
    SELECT *
    FROM $TABEL_ABSENSI
    WHERE TRIM(user_id)=TRIM('$safeUserId')
      AND tanggal='$tgl_hari_ini'
    LIMIT 1
"); // ambil ulang data hari ini setelah proses masuk/pulang
$dataHariIni = ($qToday && mysqli_num_rows($qToday) > 0) ? mysqli_fetch_assoc($qToday) : null; // ambil row kalau ada

$statusHariIniTampil = strtolower(trim($dataHariIni['status'] ?? '')); // ambil ulang status hari ini
$blokAbsensi = in_array($statusHariIniTampil, ['izin', 'cuti', 'sakit'], true); // cek ulang status blok absensi

// PERIODE
$periode = getPeriode16to15($tgl_hari_ini); // tentukan periode 16 sampai 15
$periodeStart = $periode['start']; // tanggal awal periode
$periodeEnd   = $periode['end']; // tanggal akhir periode
$tanggalRange = buildTanggalRange($periodeStart, $periodeEnd); // buat semua tanggal dalam periode

// DATA PERIODE
$qDataPeriode = mysqli_query($conn, "
   SELECT 
    a.tanggal,              -- tanggal absensi
    a.user_id,              -- id user
    a.nama,                 -- nama user
    a.jam_masuk,            -- jam masuk
    a.jam_keluar,           -- jam keluar
    a.status,               -- status (hadir/izin/dll)
    a.keterangan,           -- keterangan tambahan
    a.mac_address,          -- mac address device
    u.name,                 -- nama dari tabel users
    u.position,             -- jabatan
    u.project,              -- project
    u.jenis_jam_kerja       -- jenis jam kerja
FROM $TABEL_ABSENSI a       -- tabel absensi admin
LEFT JOIN users u ON TRIM(u.user_id) = TRIM(a.user_id)  -- gabung ke tabel users
WHERE TRIM(a.user_id)=TRIM('$safeUserId')               -- filter user login
AND a.tanggal BETWEEN '$periodeStart' AND '$periodeEnd' -- filter tanggal
ORDER BY a.tanggal ASC, a.id ASC                        -- urutkan data
"); // query ambil riwayat absensi periode + join data user
if (!$qDataPeriode) {
    die("SQL Error (periode): " . mysqli_error($conn)); // kalau query gagal hentikan
}

$rowsPeriodeAsli = []; // array kosong untuk simpan hasil query periode
while ($row = mysqli_fetch_assoc($qDataPeriode)) { // loop semua hasil query
    $rowsPeriodeAsli[] = $row; // masukkan tiap row ke array
}
$rowsPeriode = buildMergedAbsensiRows($tanggalRange, $rowsPeriodeAsli); // gabungkan dengan range tanggal agar tanggal tanpa data tetap muncul

$datePeriode = date('d/m/Y', strtotime($periodeStart)) . ' - ' . date('d/m/Y', strtotime($periodeEnd)); // format tampilan periode

// HITUNG RINGKASAN
$hadir = $izin = $cuti = $sakit = 0; // default semua total 0

$qHadir = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM $TABEL_ABSENSI
    WHERE TRIM(user_id)=TRIM('$safeUserId')
      AND LOWER(TRIM(COALESCE(status, '')))='hadir'
"); // hitung total status hadir
if ($qHadir && $r = mysqli_fetch_assoc($qHadir)) $hadir = (int)$r['total']; // ambil total hadir

$qIzin = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM $TABEL_ABSENSI
    WHERE TRIM(user_id)=TRIM('$safeUserId')
      AND LOWER(TRIM(COALESCE(status, '')))='izin'
"); // hitung total izin
if ($qIzin && $r = mysqli_fetch_assoc($qIzin)) $izin = (int)$r['total']; // ambil total izin

$qCuti = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM $TABEL_ABSENSI
    WHERE TRIM(user_id)=TRIM('$safeUserId')
      AND LOWER(TRIM(COALESCE(status, '')))='cuti'
"); // hitung total cuti
if ($qCuti && $r = mysqli_fetch_assoc($qCuti)) $cuti = (int)$r['total']; // ambil total cuti

$qSakit = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM $TABEL_ABSENSI
    WHERE TRIM(user_id)=TRIM('$safeUserId')
      AND LOWER(TRIM(COALESCE(status, '')))='sakit'
"); // hitung total sakit
if ($qSakit && $r = mysqli_fetch_assoc($qSakit)) $sakit = (int)$r['total']; // ambil total sakit

// LIST DATA UNTUK HALAMAN
$listPeriode = $rowsPeriode; // data riwayat final yang akan ditampilkan di halaman
?>
<!DOCTYPE html> <!-- deklarasi dokumen HTML5 -->
<html lang="id"> <!-- awal HTML dengan bahasa Indonesia -->
<head>
<meta charset="UTF-8" /> <!-- encoding karakter UTF-8 -->
<meta name="viewport" content="width=device-width, initial-scale=1.0" /> <!-- supaya responsive -->
<title>Absensi Admin - PT MEINDO</title> <!-- judul tab browser -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"> <!-- import font Poppins -->

<style>
  :root{
    --primary:#1f82ff; /* warna biru utama */
    --primary2:#0d67e6; /* biru gelap */
    --bg:#eef4fb; /* background utama */
    --text:#18202a; /* warna teks utama */
    --muted:#7c8ca5; /* warna teks sekunder */
    --white:#ffffff; /* putih */
    --line:#e7edf5; /* warna garis */
    --success:#21b35b; /* hijau */
    --warning:#ffb020; /* oranye */
    --danger:#ef4444; /* merah */
    --shadow:0 12px 30px rgba(24, 54, 95, 0.10); /* bayangan */
  }

  *{margin:0;padding:0;box-sizing:border-box} /* reset seluruh elemen */
  html, body{width:100%;overflow-x:hidden} /* lebar penuh dan cegah scroll horizontal */
  body{
    font-family:'Poppins',sans-serif; /* font utama */
    background:linear-gradient(180deg, #dfeefd 0%, #eef4fb 30%, #eef4fb 100%); /* gradasi background */
    color:var(--text); /* warna teks */
    min-height:100vh; /* tinggi minimal 1 layar */
  }

  .app{width:100%;min-height:100vh;background:var(--bg);position:relative;overflow-x:hidden} /* pembungkus utama halaman */
  .topBlue{
    background:linear-gradient(180deg, var(--primary), var(--primary2)); /* header gradasi biru */
    padding:18px 0 95px; /* jarak dalam header */
    border-bottom-left-radius:32px; /* sudut kiri bawah */
    border-bottom-right-radius:32px; /* sudut kanan bawah */
    color:#fff; /* teks putih */
    position:relative; /* posisi relatif */
  }

  .topInner,.mainContent{width:min(1600px, calc(100% - 40px));margin:0 auto} /* batasi lebar konten dan rata tengah */
  .topRow{display:flex;justify-content:space-between;align-items:center;gap:12px} /* baris header */
  .profileBox{display:flex;align-items:center;gap:12px;min-width:0} /* box profil kiri */
  .avatar{
    width:48px;height:48px;border-radius:50%;
    background:rgba(255,255,255,.22);
    border:2px solid rgba(255,255,255,.35);
    display:flex;align-items:center;justify-content:center;
    font-size:18px;font-weight:800;flex-shrink:0;
  } /* avatar bundar */
  .profileText h2{font-size:15px;font-weight:700;line-height:1.2} /* nama admin */
  .profileText p{font-size:11px;opacity:.92;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis} /* user id admin */
  .notif{
    width:40px;height:40px;border-radius:50%;
    background:rgba(255,255,255,.17);
    border:1px solid rgba(255,255,255,.22);
    display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;
  } /* icon notifikasi */
  .brandCenter{text-align:center;margin-top:16px} /* area judul tengah */
  .brandCenter .logoText{font-size:28px;font-weight:900;line-height:1;letter-spacing:.4px} /* judul utama */
  .brandCenter .logoSub{margin-top:6px;font-size:12px;opacity:.92} /* subjudul */

  .mainContent{margin:-74px auto 0;padding:0 0 24px;position:relative;z-index:2} /* konten utama naik ke atas */
  .card{background:var(--white);border-radius:22px;box-shadow:var(--shadow)} /* style umum card */
  .summaryCard,.actionCard,.historyCard,.menuSection{width:100%} /* semua section lebar penuh */
  .summaryCard{padding:16px;margin-bottom:16px} /* card ringkasan */
  .summaryStatus{margin-bottom:14px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:#fbfdff} /* box status */
  .summaryStatus .label{font-size:11px;color:var(--muted);margin-bottom:4px} /* label kecil */
  .summaryStatus .value{font-size:15px;font-weight:800;color:#213247} /* nilai status */

  .progressWrap{display:grid;grid-template-columns:repeat(2,1fr);gap:10px} /* grid progress 2 kolom */
  .progressItem{background:#fbfdff;border:1px solid var(--line);border-radius:16px;padding:12px 10px;text-align:center} /* item progress */
  .progressItem .label{font-size:11px;color:var(--muted);margin-bottom:6px} /* label progress */
  .progressItem .value{font-size:18px;font-weight:800;color:#213247;margin-bottom:8px} /* angka progress */
  .bar{height:5px;border-radius:999px;background:#ebf1f8;overflow:hidden} /* background progress bar */
  .bar span{display:block;height:100%;border-radius:999px} /* isi bar */
  .green span{ background:var(--success); width:80%; } /* bar hijau */
  .blue span{ background:var(--primary); width:45%; } /* bar biru */
  .orange span{ background:var(--warning); width:20%; } /* bar oranye */
  .red span{ background:#ef4444; width:55%; } /* bar merah */

  .menuSection{margin-bottom:16px} /* section menu */
  .sectionTitle{font-size:14px;font-weight:800;color:#2c3a4d;margin-bottom:12px;padding:0 2px} /* judul section */
  .menuGrid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px} /* grid menu 4 kolom */
  .menuItem{
    background:var(--white);border-radius:18px;padding:14px 8px;text-align:center;
    box-shadow:var(--shadow);text-decoration:none;color:var(--text);transition:.15s ease;
  } /* item menu */
  .menuItem:hover{transform:translateY(-2px)} /* hover menu */
  .menuIcon{
    width:46px;height:46px;border-radius:14px;margin:0 auto 8px;display:flex;
    align-items:center;justify-content:center;font-size:22px;
    background:linear-gradient(180deg,#eef6ff,#dcecff);border:1px solid #dce9f9;
  } /* icon menu */
  .menuItem span{font-size:11px;font-weight:600;color:#49586e;line-height:1.35;display:block} /* teks menu */

  .actionCard{padding:14px;margin-bottom:16px} /* card aksi */
  .clockBox{background:#f8fbff;border:1px solid var(--line);border-radius:16px;padding:12px;text-align:center;margin-bottom:12px} /* box jam */
  .clockLabel{font-size:11px;color:var(--muted);margin-bottom:4px} /* label jam */
  .clockValue{font-size:16px;font-weight:800;color:#203349} /* nilai jam */

  .statusToday{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px} /* grid status hari ini */
  .statusMini{background:#fbfdff;border:1px solid var(--line);border-radius:14px;padding:12px} /* kotak status kecil */
  .statusMini .small{font-size:11px;color:var(--muted);margin-bottom:4px} /* label kecil */
  .statusMini .big{font-size:14px;font-weight:800;color:#25384a} /* nilai besar */

  .alert{
    margin-bottom:12px;padding:12px 14px;border-radius:14px;background:#eef7ff;color:#1d4f8f;
    border:1px solid #d8eaff;font-size:12px;line-height:1.55;font-weight:500;
  } /* box alert */

  .actions{display:grid;grid-template-columns:1fr 1fr;gap:12px} /* grid tombol aksi */
  .btn{
    border:none;border-radius:16px;padding:14px;font-family:'Poppins',sans-serif;
    font-size:14px;font-weight:800;cursor:pointer;color:#fff;transition:.15s ease;
  } /* style tombol umum */
  .btn:hover{transform:translateY(-1px)} /* efek hover tombol */
  .btnMasuk{background:linear-gradient(180deg,#22c55e,#16a34a)} /* tombol masuk hijau */
  .btnPulang{background:linear-gradient(180deg,#fb7185,#ef4444)} /* tombol pulang merah */
  .btnDisabled{background:linear-gradient(180deg,#94a3b8,#64748b);cursor:not-allowed;opacity:.8} /* tombol disabled */

  .historyCard{padding:14px;margin-bottom:18px} /* card riwayat */
  .historyHead{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px} /* header riwayat */
  .historyHead h3{font-size:14px;font-weight:800;color:#2b3a4e} /* judul riwayat */

  .periodeInfo{
    margin-bottom:12px;
    padding:10px 12px;
    border-radius:12px;
    background:#f8fbff;
    border:1px solid var(--line);
    font-size:12px;
    color:#44556d;
    font-weight:600;
  } /* info periode */

  .tableWrap{
    overflow-x:auto;
    border:1px solid var(--line);
    border-radius:16px;
    background:#fff;
  } /* pembungkus tabel agar bisa scroll horizontal */

  table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
    min-width:1200px;
  } /* tabel utama */

  th, td{
    border:1px solid #e2e8f0;
  } /* border sel */

  th{
    background:#e5e7eb;
    color:#374151;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.4px;
    padding:12px 10px;
    text-align:left;
  } /* header tabel */

  td{
    font-size:12px;
    color:#314256;
    padding:12px 10px;
    vertical-align:top;
  } /* isi tabel */

  tbody tr:nth-child(even){
    background:#f9fbff;
  } /* warna baris genap */

  tbody tr:hover td{
    background:#eef6ff;
  } /* efek hover tabel */

  .empty{text-align:center;color:var(--muted);padding:18px;font-size:12px} /* teks saat data kosong */

  .logoutWrap{text-align:center;margin:12px 0 26px} /* wrapper logout */
  .logoutBtn{
    text-decoration:none;display:inline-block;padding:12px 18px;border-radius:999px;background:#ffffff;
    border:1px solid var(--line);color:#ef4444;font-size:13px;font-weight:800;box-shadow:var(--shadow);
  } /* tombol logout */

  .footer{text-align:center;font-size:11px;color:var(--muted);padding-bottom:14px} /* footer */

  .badge{
    display:inline-block;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;
    line-height:1.2;white-space:nowrap;
  } /* badge status umum */
  .badgeHadir{background:#e8fff1;color:#16a34a} /* badge hadir */
  .badgeIzin{background:#eaf3ff;color:#2563eb} /* badge izin */
  .badgeCuti{background:#fff5df;color:#d97706} /* badge cuti */
  .badgeSakit{background:#ffe9e9;color:#dc2626} /* badge sakit */
  .badgeDefault{background:#f1f5f9;color:#475569} /* badge default */

  @media (max-width: 991px){
    .topInner,.mainContent{width:calc(100% - 24px)}
    .mainContent{margin:-64px auto 0}
  } /* responsive tablet */

  @media (max-width:768px){
    .menuGrid{grid-template-columns:repeat(3,1fr)}
  } /* menu jadi 3 kolom */

  @media (max-width:576px){
    .menuGrid{grid-template-columns:repeat(2,1fr)}
    .actions{grid-template-columns:1fr}
    .statusToday,.progressWrap{grid-template-columns:1fr}
    table{min-width:900px}
  } /* responsive HP kecil */
</style>
</head>
<body>

<div class="app"> <!-- pembungkus utama aplikasi -->
  <div class="topBlue"> <!-- header biru -->
    <div class="topInner"> <!-- pembatas lebar header -->
      <div class="topRow"> <!-- baris atas header -->
        <div class="profileBox"> <!-- box profil admin -->
          <div class="avatar"><?= strtoupper(substr(trim($nama), 0, 1)); ?></div> <!-- tampilkan huruf pertama nama admin sebagai avatar -->
          <div class="profileText"> <!-- teks profil -->
            <h2><?= htmlspecialchars($nama) ?></h2> <!-- tampilkan nama admin -->
            <p><?= htmlspecialchars($user_id) ?></p> <!-- tampilkan user_id admin -->
          </div>
        </div>
        <div class="notif">🔔</div> <!-- icon notifikasi -->
      </div>

      <div class="brandCenter"> <!-- area judul tengah -->
        <div class="logoText">HALAMAN ABSEN ADMIN</div> <!-- judul utama -->
        <div class="logoSub">PT MEINDO ELANG INDAH</div> <!-- subjudul -->
      </div>
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama halaman -->

    <div class="card summaryCard"> <!-- card ringkasan -->
      <div class="summaryStatus"> <!-- box status -->
        <div class="label">Status</div> <!-- label status -->
        <div class="value">
          <?= !empty($dataHariIni['status']) ? htmlspecialchars(ucfirst($dataHariIni['status'])) : '-' ?> <!-- tampilkan status hari ini kalau ada -->
        </div>
      </div>

      <div class="progressWrap"> <!-- grid progress -->
        <div class="progressItem"><div class="label">Hadir</div><div class="value"><?= $hadir ?> Hari</div><div class="bar green"><span></span></div></div> <!-- ringkasan hadir -->
        <div class="progressItem"><div class="label">Izin</div><div class="value"><?= $izin ?> Hari</div><div class="bar blue"><span></span></div></div> <!-- ringkasan izin -->
        <div class="progressItem"><div class="label">Cuti</div><div class="value"><?= $cuti ?> Hari</div><div class="bar orange"><span></span></div></div> <!-- ringkasan cuti -->
        <div class="progressItem"><div class="label">Sakit</div><div class="value"><?= $sakit ?> Hari</div><div class="bar red"><span></span></div></div> <!-- ringkasan sakit -->
      </div>
    </div>

    <div class="menuSection"> <!-- section menu utama -->
      <div class="sectionTitle">Menu Utama</div> <!-- judul section -->

      <div class="menuGrid"> <!-- grid menu -->
        <a href="dashboard_admin.php" class="menuItem"><div class="menuIcon">📋</div><span>Semua Absensi</span></a> <!-- menu semua absensi -->
        <a href="tambah_karyawan.php" class="menuItem"><div class="menuIcon">➕</div><span>Tambah Karyawan</span></a> <!-- menu tambah karyawan -->
        <a href="halaman_izin.php" class="menuItem"><div class="menuIcon">📝</div><span>Izin</span></a> <!-- menu izin -->
        <a href="halaman_cuti.php" class="menuItem"><div class="menuIcon">🏖️</div><span>Cuti</span></a> <!-- menu cuti -->
        <a href="halaman_sakit.php" class="menuItem"><div class="menuIcon">🤒</div><span>Sakit</span></a> <!-- menu sakit -->

        <a href="download_fingerprint_report.php?user_id=<?= urlencode($user_id) ?>&format=pdf&base_date=<?= urlencode($periodeStart) ?>" class="menuItem" target="_blank"> <!-- link download PDF -->
          <div class="menuIcon">⬇️</div>
          <span>Download PDF</span>
        </a>

        <a href="download_fingerprint_report.php?user_id=<?= urlencode($user_id) ?>&format=excel&base_date=<?= urlencode($periodeStart) ?>" class="menuItem" target="_blank"> <!-- link download Excel -->
          <div class="menuIcon">📊</div>
          <span>Download Excel</span>
        </a>
      </div>
    </div>

    <div class="card actionCard"> <!-- card aksi absensi -->
      <div class="clockBox"> <!-- box jam server -->
        <div class="clockLabel">Waktu Server</div> <!-- label -->
        <div class="clockValue" id="clockText" data-serverms="<?= $serverMs; ?>">Memuat waktu...</div> <!-- nilai jam realtime -->
      </div>

      <div class="statusToday"> <!-- status masuk dan pulang hari ini -->
        <div class="statusMini">
          <div class="small">Jam Masuk Hari Ini</div> <!-- label -->
          <div class="big"><?= !isJamKosong($dataHariIni['jam_masuk'] ?? '') ? htmlspecialchars($dataHariIni['jam_masuk']) : '-' ?></div> <!-- tampilkan jam masuk -->
        </div>
        <div class="statusMini">
          <div class="small">Jam Pulang Hari Ini</div> <!-- label -->
          <div class="big"><?= !isJamKosong($dataHariIni['jam_keluar'] ?? '') ? htmlspecialchars($dataHariIni['jam_keluar']) : '-' ?></div> <!-- tampilkan jam pulang -->
        </div>
      </div>

      <?php if ($pesan !== ""): ?> <!-- kalau ada pesan -->
        <div class="alert"><?= htmlspecialchars($pesan) ?></div> <!-- tampilkan pesan -->
      <?php endif; ?>

      <?php if ($blokAbsensi): ?> <!-- kalau absensi diblok -->
        <div class="alert">
          Hari ini status kamu <b><?= htmlspecialchars(ucfirst($statusHariIniTampil)) ?></b>,
          jadi tombol absensi dinonaktifkan.
        </div>

        <div class="actions">
          <button class="btn btnDisabled" type="button" disabled>Absen Masuk</button> <!-- tombol disabled -->
          <button class="btn btnDisabled" type="button" disabled>Absen Pulang</button> <!-- tombol disabled -->
        </div>
      <?php else: ?> <!-- kalau absensi aktif -->
        <form method="POST" action="<?= htmlspecialchars($selfPage) ?>" class="actions"> <!-- form aksi -->
          <button class="btn btnMasuk" type="submit" name="aksi" value="masuk">Absen Masuk</button> <!-- tombol absen masuk -->
          <button class="btn btnPulang" type="submit" name="aksi" value="pulang">Absen Pulang</button> <!-- tombol absen pulang -->
        </form>
      <?php endif; ?>
    </div>

    <div class="card historyCard"> <!-- card riwayat -->
      <div class="historyHead">
        <h3>Riwayat Absensi</h3> <!-- judul riwayat -->
      </div>

      <div class="periodeInfo">
        Periode tabel: <b><?= htmlspecialchars($datePeriode) ?></b> — semua tanggal tampil, kalau tidak ada data maka kolom lainnya dikosongkan. <!-- info periode -->
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
              <?php foreach($listPeriode as $r): ?> <!-- loop semua data -->
                <?php
                  $statusText = strtolower(trim($r['status'] ?? '')); // ambil status row
                  $badgeClass = 'badgeDefault'; // default badge

                  if ($statusText === 'hadir') $badgeClass = 'badgeHadir'; // kalau hadir, badge hijau
                  elseif ($statusText === 'izin') $badgeClass = 'badgeIzin'; // kalau izin, badge biru
                  elseif ($statusText === 'cuti') $badgeClass = 'badgeCuti'; // kalau cuti, badge oranye
                  elseif ($statusText === 'sakit') $badgeClass = 'badgeSakit'; // kalau sakit, badge merah

                  $ket = trim($r['keterangan'] ?? ''); // ambil keterangan
                ?>
                <tr>
                  <td><?= htmlspecialchars(date('d/m/Y', strtotime($r['tanggal'] ?? $tgl_hari_ini))) ?></td> <!-- tanggal -->
                  <td><?= htmlspecialchars($r['user_id'] ?? '') ?></td> <!-- user id -->
                  <td><?= htmlspecialchars($r['nama'] ?? '') ?></td> <!-- nama -->
                  <td><?= htmlspecialchars(!isJamKosong($r['jam_masuk'] ?? '') ? $r['jam_masuk'] : '') ?></td> <!-- jam masuk -->
                  <td><?= htmlspecialchars(!isJamKosong($r['jam_keluar'] ?? '') ? $r['jam_keluar'] : '') ?></td> <!-- jam keluar -->
                  <td>
                    <?php if (!empty($r['status'])): ?> <!-- kalau status ada -->
                      <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($r['status']) ?></span> <!-- badge status -->
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($ket) ?></td> <!-- keterangan -->
                  <td><?= htmlspecialchars($r['mac_address'] ?? '') ?></td> <!-- MAC -->
                </tr>
              <?php endforeach; ?>
            <?php else: ?> <!-- kalau tidak ada data -->
              <tr>
                <td colspan="8" class="empty">Belum ada data absensi.</td> <!-- teks kosong -->
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="logoutWrap"> <!-- area logout -->
      <a class="logoutBtn" href="?logout=1">Logout</a> <!-- tombol logout -->
    </div>

    <div class="footer">
      © <?= date("Y"); ?> Sistem Absensi • PT MEINDO ELANG INDAH <!-- footer -->
    </div>
  </div>
</div>

<script>
(() => { // IIFE = function langsung jalan otomatis
  const el = document.getElementById('clockText'); // ambil elemen jam
  if (!el) return; // kalau elemen tidak ada, hentikan

  const serverMs = Number(el.dataset.serverms || 0); // ambil waktu server dari atribut data-serverms lalu ubah ke number
  const offset = serverMs - Date.now(); // hitung selisih waktu server dengan browser

  const pad = (n) => String(n).padStart(2,'0'); // function menambahkan 0 di depan angka
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; // daftar nama bulan

  function tick(){ // function update jam
    const now = new Date(Date.now() + offset); // waktu sekarang browser disesuaikan dengan offset server
    const dd = pad(now.getDate()); // tanggal
    const mm = bulan[now.getMonth()]; // bulan
    const yy = now.getFullYear(); // tahun
    const hh = pad(now.getHours()); // jam
    const mi = pad(now.getMinutes()); // menit
    const ss = pad(now.getSeconds()); // detik
    el.textContent = `${dd} ${mm} ${yy}, ${hh}:${mi}:${ss} WIB`; // tampilkan ke elemen HTML
  }

  tick(); // jalankan sekali saat halaman dimuat
  const delay = 1000 - ((Date.now() + offset) % 1000); // hitung delay supaya update pas di pergantian detik
  setTimeout(() => { // tunggu sampai detik pas
    tick(); // update lagi
    setInterval(tick, 1000); // lalu update tiap 1 detik
  }, delay);
})();
</script>

</body>
</html>