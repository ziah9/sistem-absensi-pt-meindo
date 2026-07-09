<?php // membuka tag PHP
error_reporting(E_ALL); // menampilkan semua jenis error PHP agar mudah saat debugging
ini_set('display_errors', 1); // mengaktifkan tampilan error langsung di browser

if (session_status() === PHP_SESSION_NONE) session_start(); // cek apakah session belum aktif, kalau belum maka jalankan session_start()
include "koneksi.php"; // memanggil file koneksi database
date_default_timezone_set("Asia/Jakarta"); // mengatur zona waktu ke WIB

// ======================
// DETEKSI LOGIN ADMIN / KARYAWAN
// ======================
$nama = ''; // variabel nama user, default kosong
$user_id = ''; // variabel user_id, default kosong
$tgl_hari_ini = date("Y-m-d"); // mengambil tanggal hari ini dengan format tahun-bulan-hari
$serverMs = (int) round(microtime(true) * 1000); // mengambil waktu server dalam milidetik untuk jam realtime

$TABEL_ABSENSI = ""; // variabel nama tabel absensi, nanti diisi sesuai role
$dashboard_link = ""; // link kembali ke dashboard, nanti diisi sesuai role
$label_role = ""; // label role untuk tampilan
$login_redirect = "login_karyawan.php"; // default redirect ke login karyawan

if (isset($_SESSION['admin_login']) && $_SESSION['admin_login'] === true) { // kalau session admin_login ada dan bernilai true

    if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'admin') { // cek apakah role session benar-benar admin
        header("Location: login_admin.php"); // kalau tidak valid, arahkan ke login admin
        exit; // hentikan program
    }

    $nama = trim($_SESSION['nama'] ?? 'Admin'); // ambil nama dari session, default Admin
    $user_id = trim($_SESSION['user_id'] ?? ''); // ambil user_id dari session
    $TABEL_ABSENSI = "admin_users"; // tabel absensi yang dipakai admin
    $dashboard_link = "admin.php"; // halaman dashboard admin
    $label_role = "admin"; // label role admin
    $login_redirect = "login_admin.php"; // kalau ada masalah session, balik ke login admin

} elseif (isset($_SESSION['karyawan_login']) && $_SESSION['karyawan_login'] === true) { // kalau bukan admin, cek apakah login sebagai karyawan

    if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'karyawan') { // validasi role harus karyawan
        header("Location: login_karyawan.php"); // kalau role tidak valid, balik ke login karyawan
        exit; // hentikan program
    }

    $nama = trim($_SESSION['nama'] ?? 'Karyawan'); // ambil nama dari session, default Karyawan
    $user_id = trim($_SESSION['user_id'] ?? ''); // ambil user_id dari session
    $TABEL_ABSENSI = "karyawan_users"; // tabel absensi untuk karyawan
    $dashboard_link = "karyawan.php"; // halaman dashboard karyawan
    $label_role = "karyawan"; // label role karyawan
    $login_redirect = "login_karyawan.php"; // kalau ada masalah session, balik ke login karyawan

} else { // kalau tidak login admin dan tidak login karyawan
    header("Location: login_karyawan.php"); // arahkan ke login karyawan
    exit; // hentikan program
}

if ($user_id === '') { // kalau user_id kosong
    header("Location: " . $login_redirect); // arahkan ke halaman login sesuai role
    exit; // hentikan program
}

$pesan = ""; // variabel untuk pesan sukses
$error = ""; // variabel untuk pesan error

// ======================
// AMBIL NAMA DARI USERS JIKA KOSONG
// ======================
if ($nama === '' || strtolower($nama) === 'admin' || strtolower($nama) === 'karyawan') { // kalau nama kosong atau masih nama default
    $safeUserIdNama = mysqli_real_escape_string($conn, $user_id); // amankan user_id untuk query SQL
    $qUser = mysqli_query($conn, "
        SELECT COALESCE(name, '') AS name
        FROM users
        WHERE TRIM(user_id)=TRIM('$safeUserIdNama')
        LIMIT 1
    "); // query ambil nama user asli dari tabel users

    if ($qUser && mysqli_num_rows($qUser) > 0) { // kalau query berhasil dan data ditemukan
        $dUser = mysqli_fetch_assoc($qUser); // ambil data hasil query
        $namaDb = trim($dUser['name'] ?? ''); // ambil nama dari database
        if ($namaDb !== '') { // kalau nama database tidak kosong
            $nama = $namaDb; // isi variabel nama
            $_SESSION['nama'] = $namaDb; // update juga ke session
        }
    }
}

if ($nama === '') { // kalau nama masih kosong
    $nama = $user_id; // pakai user_id sebagai nama cadangan
}

// ======================
// AMBIL MAC ADDRESS
// ======================
$ip_address = $_SERVER['REMOTE_ADDR'] ?? ''; // ambil IP address user yang mengakses

function getMacAddress($ip) // function untuk mencoba membaca MAC address dari IP
{
    $mac = ""; // default MAC kosong

    if ($ip === "") return $mac; // kalau IP kosong, langsung return kosong

    @exec("ping -n 1 " . escapeshellarg($ip)); // ping IP 1 kali supaya masuk ke tabel ARP
    $arp = @shell_exec("arp -a"); // baca isi tabel ARP

    if (!$arp) return $mac; // kalau output ARP kosong, return kosong

    $lines = preg_split("/\r\n|\n|\r/", $arp); // pecah output ARP per baris

    foreach ($lines as $line) { // loop setiap baris
        if (stripos($line, $ip) !== false) { // kalau baris mengandung IP yang dicari
            $parts = preg_split('/\s+/', trim($line)); // pecah baris berdasarkan spasi
            foreach ($parts as $part) { // loop tiap potongan kata
                if (preg_match('/^([0-9a-f]{2}[-:]){5}[0-9a-f]{2}$/i', $part)) { // cek apakah formatnya cocok dengan MAC address
                    return $part; // kalau cocok, return MAC address
                }
            }
        }
    }

    return $mac; // kalau tidak ketemu, return kosong
}

$mac_address = getMacAddress($ip_address); // panggil function getMacAddress
if ($mac_address === '') { // kalau hasil kosong
    $mac_address = 'Tidak terbaca'; // isi default
}

// ======================
// HELPER CEK STATUS
// ======================
function cekStatusHariIni($conn, $tabel, $user_id, $tanggal) // function untuk mengecek absensi user hari ini
{
    $uid = mysqli_real_escape_string($conn, trim($user_id)); // amankan user_id
    $tgl = mysqli_real_escape_string($conn, trim($tanggal)); // amankan tanggal

    $q = mysqli_query($conn, "
        SELECT *
        FROM $tabel
        WHERE TRIM(user_id)=TRIM('$uid')
          AND tanggal='$tgl'
        LIMIT 1
    "); // query ambil data absensi user di tanggal tertentu

    if (!$q) { // kalau query gagal
        die("SQL Error (cekStatusHariIni): " . mysqli_error($conn)); // hentikan program dan tampilkan error
    }

    return mysqli_num_rows($q) > 0 ? mysqli_fetch_assoc($q) : null; // kalau ada data return row, kalau tidak return null
}

function cekSakitHariIni($conn, $user_id, $tanggal) // function untuk mengecek apakah user sudah mengajukan sakit hari ini
{
    $uid = mysqli_real_escape_string($conn, trim($user_id)); // amankan user_id
    $tgl = mysqli_real_escape_string($conn, trim($tanggal)); // amankan tanggal

    $q = mysqli_query($conn, "
        SELECT *
        FROM sakit_karyawan
        WHERE TRIM(user_id)=TRIM('$uid')
          AND tanggal_sakit='$tgl'
        LIMIT 1
    "); // query cek data sakit hari ini di tabel sakit_karyawan

    if (!$q) { // kalau query gagal
        die("SQL Error (cekSakitHariIni): " . mysqli_error($conn)); // hentikan program dan tampilkan error
    }

    return mysqli_num_rows($q) > 0 ? mysqli_fetch_assoc($q) : null; // kalau ada data return row, kalau tidak return null
}

// ======================
// CEK STATUS HARI INI
// ======================
$dataHariIni = cekStatusHariIni($conn, $TABEL_ABSENSI, $user_id, $tgl_hari_ini); // ambil data absensi user hari ini
$dataSakitHariIni = cekSakitHariIni($conn, $user_id, $tgl_hari_ini); // ambil data sakit user hari ini

$blok = false; // penanda apakah form harus diblok
$statusBlok = ''; // menyimpan status yang menyebabkan blok

if ($dataHariIni) { // kalau ada data absensi hari ini
    $statusBlok = strtolower(trim($dataHariIni['status'] ?? '')); // ambil status absensi

    if ($statusBlok === 'iizn') { // kalau ada typo iizn
        $statusBlok = 'izin'; // ubah ke izin
    }

    if (in_array($statusBlok, ['hadir', 'izin', 'sakit', 'cuti', 'lembur'], true)) { // kalau status termasuk daftar blok
        $blok = true; // form diblok
    }
} elseif ($dataSakitHariIni) { // kalau tidak ada di absensi tapi ada di tabel sakit
    $blok = true; // form tetap diblok
    $statusBlok = 'sakit'; // status blok = sakit
}

// ======================
// PROSES SIMPAN SAKIT
// ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // kalau form dikirim dengan method POST

    if ($blok) { // kalau form sudah diblok
        $error = "Hari ini kamu sudah tercatat sebagai " . strtoupper($statusBlok) . ", jadi tidak bisa mengajukan sakit lagi."; // tampilkan error
    } else { // kalau form masih boleh diisi
        $keterangan = trim($_POST['keterangan'] ?? ''); // ambil keterangan sakit

        $cek = cekStatusHariIni($conn, $TABEL_ABSENSI, $user_id, $tgl_hari_ini); // cek ulang absensi hari ini
        $cekSakit = cekSakitHariIni($conn, $user_id, $tgl_hari_ini); // cek ulang data sakit hari ini

        $statusSekarang = ''; // variabel status sekarang
        if ($cek) { // kalau ada data absensi
            $statusSekarang = strtolower(trim($cek['status'] ?? '')); // ambil status
            if ($statusSekarang === 'iizn') { // kalau typo iizn
                $statusSekarang = 'izin'; // ubah jadi izin
            }
        }

        if ($cek && in_array($statusSekarang, ['hadir', 'izin', 'sakit', 'cuti', 'lembur'], true)) { // kalau absensi hari ini sudah ada status
            $error = "Kamu sudah tercatat " . strtoupper($statusSekarang) . " hari ini."; // tampilkan error
            $blok = true; // blok form
            $statusBlok = $statusSekarang; // simpan status blok

        } elseif ($cekSakit) { // kalau sudah pernah mengajukan sakit hari ini
            $error = "Kamu sudah mengajukan sakit hari ini."; // tampilkan error
            $blok = true; // blok form
            $statusBlok = 'sakit'; // status blok sakit

        } elseif ($keterangan === '') { // kalau keterangan kosong
            $error = "Keterangan sakit wajib diisi."; // tampilkan error

        } elseif (!isset($_FILES['surat_dokter']) || $_FILES['surat_dokter']['error'] == 4) { // kalau file surat dokter belum dipilih
            $error = "Surat dokter wajib diupload."; // tampilkan error

        } else { // kalau input dasar valid
            $allowedDoc = ['jpg', 'jpeg', 'png', 'webp', 'pdf']; // daftar ekstensi file yang diizinkan
            $maxSurat = 3 * 1024 * 1024; // ukuran maksimal 3MB

            $suratName = $_FILES['surat_dokter']['name'] ?? ''; // nama file asli
            $suratTmp  = $_FILES['surat_dokter']['tmp_name'] ?? ''; // file sementara
            $suratSize = $_FILES['surat_dokter']['size'] ?? 0; // ukuran file
            $suratErr  = $_FILES['surat_dokter']['error'] ?? 0; // kode error upload
            $suratExt  = strtolower(pathinfo($suratName, PATHINFO_EXTENSION)); // ambil ekstensi file

            if ($suratErr !== 0) { // kalau ada error upload
                $error = "Upload surat dokter gagal. Kode error: " . $suratErr; // tampilkan error
            } elseif (!in_array($suratExt, $allowedDoc, true)) { // kalau format file tidak diizinkan
                $error = "Format surat dokter harus JPG, JPEG, PNG, WEBP, atau PDF."; // tampilkan error
            } elseif ($suratSize > $maxSurat) { // kalau ukuran file melebihi 3MB
                $error = "Ukuran surat dokter maksimal 3MB."; // tampilkan error
            } else { // kalau file valid
                $uploadDir = __DIR__ . "/uploads/sakit/"; // folder tujuan upload

                if (!is_dir($uploadDir)) { // kalau folder belum ada
                    mkdir($uploadDir, 0777, true); // buat folder beserta parent folder jika perlu
                }

                if (!is_dir($uploadDir)) { // kalau setelah dicoba folder tetap belum ada
                    $error = "Folder upload gagal dibuat."; // tampilkan error
                } else { // kalau folder siap
                    $safeUserIdFile = preg_replace('/[^a-zA-Z0-9_-]/', '_', $user_id); // bersihkan user_id agar aman untuk nama file
                    $newSuratName = "surat_dokter_" . $safeUserIdFile . "_" . date("Ymd_His") . "_" . rand(1000, 9999) . "." . $suratExt; // buat nama file baru yang unik
                    $targetSurat = $uploadDir . $newSuratName; // path fisik penyimpanan file
                    $dbSurat = "uploads/sakit/" . $newSuratName; // path yang disimpan di database

                    if (!move_uploaded_file($suratTmp, $targetSurat)) { // kalau file gagal dipindah ke folder upload
                        $error = "Surat dokter gagal dipindahkan ke folder upload."; // tampilkan error
                    } else { // kalau file berhasil dipindah
                        $safeUserId = mysqli_real_escape_string($conn, $user_id); // amankan user_id
                        $safeNama   = mysqli_real_escape_string($conn, $nama); // amankan nama
                        $safeKet    = mysqli_real_escape_string($conn, $keterangan); // amankan keterangan
                        $safeFile   = mysqli_real_escape_string($conn, $dbSurat); // amankan path file
                        $safeMac    = mysqli_real_escape_string($conn, $mac_address); // amankan MAC address

                        mysqli_begin_transaction($conn); // mulai transaksi database

                        try { // blok try
                            $sqlSakit = "INSERT INTO sakit_karyawan
                                (user_id, nama, tanggal_sakit, keterangan, bukti_foto, mac_address, status)
                                VALUES
                                ('$safeUserId', '$safeNama', '$tgl_hari_ini', '$safeKet', '$safeFile', '$safeMac', 'sakit')"; // query insert ke tabel sakit_karyawan

                            $insSakit = mysqli_query($conn, $sqlSakit); // jalankan insert sakit
                            if (!$insSakit) { // kalau gagal
                                throw new Exception("SQL Error insert sakit_karyawan: " . mysqli_error($conn)); // lempar exception
                            }

                            $sqlAbsen = "INSERT INTO $TABEL_ABSENSI
                                (user_id, nama, tanggal, status, keterangan, mac_address)
                                VALUES
                                ('$safeUserId', '$safeNama', '$tgl_hari_ini', 'sakit', '$safeKet', '$safeMac')"; // query insert ke tabel absensi dengan status sakit

                            $insAbsen = mysqli_query($conn, $sqlAbsen); // jalankan insert absensi
                            if (!$insAbsen) { // kalau gagal
                                throw new Exception("SQL Error insert $TABEL_ABSENSI: " . mysqli_error($conn)); // lempar exception
                            }

                            mysqli_commit($conn); // kalau semua berhasil, simpan permanen transaksi

                            $pesan = "Pengajuan sakit berhasil dikirim."; // isi pesan sukses
                            $blok = true; // setelah berhasil, form diblok
                            $statusBlok = "sakit"; // status blok sakit
                            $_POST = []; // kosongkan form
                        } catch (Exception $e) { // kalau ada error
                            mysqli_rollback($conn); // batalkan transaksi

                            if (file_exists($targetSurat)) { // kalau file sudah terlanjur tersimpan
                                unlink($targetSurat); // hapus file
                            }

                            $error = $e->getMessage(); // tampilkan pesan error
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id"> <!-- awal HTML -->
<head>
<meta charset="UTF-8"> <!-- encoding -->
<meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- responsive -->
<title>Form Sakit</title> <!-- judul halaman -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"> <!-- font -->
<style>
:root{
  --primary:#1f82ff; /* warna utama */
  --primary2:#0d67e6; /* warna kedua */
  --bg:#eef4fb; /* background */
  --text:#18202a; /* warna teks */
  --muted:#7c8ca5; /* warna teks sekunder */
  --white:#ffffff; /* putih */
  --line:#e7edf5; /* border */
  --shadow:0 12px 30px rgba(24,54,95,.10); /* bayangan */
  --successBg:#eafaf0; /* latar sukses */
  --successText:#15803d; /* teks sukses */
  --successBorder:#bbf7d0; /* border sukses */
  --dangerBg:#fef2f2; /* latar error */
  --dangerText:#b91c1c; /* teks error */
  --dangerBorder:#fecaca; /* border error */
  --infoBg:#eef7ff; /* latar info */
  --infoText:#1d4f8f; /* teks info */
  --infoBorder:#d8eaff; /* border info */
  --softBox:#f8fafc; /* background box lembut */
  --softBorder:#dbe7f3; /* border box lembut */
}
*{margin:0;padding:0;box-sizing:border-box} /* reset semua elemen */
html, body{width:100%;overflow-x:hidden} /* lebar penuh dan cegah scroll horizontal */
body{
  font-family:'Poppins',sans-serif; /* font utama */
  background:linear-gradient(180deg,#dfeefd 0%,#eef4fb 30%,#eef4fb 100%); /* background gradasi */
  color:var(--text); /* warna teks */
}
.app{
  width:100%; /* lebar penuh */
  min-height:100vh; /* tinggi minimal layar penuh */
  background:var(--bg); /* background */
}
.topBlue{
  background:linear-gradient(180deg,var(--primary),var(--primary2)); /* header gradasi biru */
  padding:20px 16px 72px; /* padding */
  border-bottom-left-radius:28px; /* sudut kiri bawah */
  border-bottom-right-radius:28px; /* sudut kanan bawah */
  color:#fff; /* teks putih */
}
.topRow{
  display:flex; /* flex */
  justify-content:space-between; /* kiri kanan */
  align-items:center; /* tengah vertikal */
  gap:12px; /* jarak */
}
.profileBox{
  display:flex; /* flex */
  align-items:center; /* tengah */
  gap:12px; /* jarak */
  min-width:0; /* biar konten bisa menyusut */
}
.avatar{
  width:48px; /* lebar */
  height:48px; /* tinggi */
  border-radius:50%; /* bulat */
  background:rgba(255,255,255,.22); /* latar transparan */
  border:2px solid rgba(255,255,255,.35); /* border */
  display:flex; /* flex */
  align-items:center; /* tengah */
  justify-content:center; /* tengah */
  font-size:18px; /* ukuran huruf */
  font-weight:800; /* tebal */
  flex-shrink:0; /* jangan mengecil */
}
.profileText h2{
  font-size:15px; /* ukuran nama */
  font-weight:700; /* tebal */
  line-height:1.2; /* jarak baris */
}
.profileText p{
  font-size:11px; /* ukuran user id */
  opacity:.92; /* transparansi */
  margin-top:3px; /* jarak atas */
  word-break:break-word; /* kalau teks panjang, turun baris */
}
.iconTop{
  width:40px; /* lebar */
  height:40px; /* tinggi */
  border-radius:50%; /* bulat */
  background:rgba(255,255,255,.17); /* latar */
  border:1px solid rgba(255,255,255,.22); /* border */
  display:flex; /* flex */
  align-items:center; /* tengah */
  justify-content:center; /* tengah */
  font-size:18px; /* ukuran icon */
  flex-shrink:0; /* jangan mengecil */
}
.brandCenter{
  text-align:center; /* rata tengah */
  margin-top:14px; /* jarak atas */
}
.brandCenter .title{
  font-size:24px; /* ukuran judul */
  font-weight:800; /* tebal */
}
.brandCenter .sub{
  font-size:12px; /* ukuran subjudul */
  margin-top:4px; /* jarak atas */
  opacity:.92; /* transparan */
}
.roleBadge{
  display:inline-block; /* inline block */
  margin-top:8px; /* jarak atas */
  padding:6px 10px; /* padding */
  border-radius:999px; /* kapsul */
  background:rgba(255,255,255,.16); /* latar */
  border:1px solid rgba(255,255,255,.22); /* border */
  font-size:11px; /* ukuran */
  font-weight:700; /* tebal */
  text-transform:uppercase; /* huruf besar */
}
.mainContent{
  padding:0 14px 24px; /* padding */
  margin-top:-42px; /* naik ke atas */
}
.card{
  background:#fff; /* putih */
  border-radius:20px; /* sudut */
  box-shadow:var(--shadow); /* bayangan */
  padding:16px; /* padding */
  margin-bottom:16px; /* jarak bawah */
}
.clockBox{
  background:#f8fbff; /* latar */
  border:1px solid var(--line); /* border */
  border-radius:16px; /* sudut */
  padding:12px; /* padding */
  text-align:center; /* rata tengah */
}
.clockLabel{
  font-size:11px; /* ukuran */
  color:var(--muted); /* warna */
  margin-bottom:4px; /* jarak bawah */
}
.clockValue{
  font-size:16px; /* ukuran */
  font-weight:800; /* tebal */
  color:#203349; /* warna */
}
.pageTitle{
  font-size:18px; /* ukuran judul */
  font-weight:800; /* tebal */
  margin-bottom:6px; /* jarak bawah */
}
.pageDesc{
  font-size:12px; /* ukuran */
  color:var(--muted); /* warna */
  line-height:1.6; /* jarak baris */
}
.formGroup{
  margin-top:14px; /* jarak atas */
}
label{
  display:block; /* tampil block */
  margin-bottom:6px; /* jarak bawah */
  font-size:12px; /* ukuran */
  font-weight:600; /* tebal */
  color:#44556b; /* warna */
}
textarea,
input[type="file"]{
  width:100%; /* lebar penuh */
  padding:12px 14px; /* padding */
  border-radius:14px; /* sudut */
  border:1px solid var(--line); /* border */
  font-family:'Poppins',sans-serif; /* font */
  font-size:13px; /* ukuran */
  outline:none; /* tanpa outline */
  background:#fbfdff; /* latar */
}
textarea{
  min-height:120px; /* tinggi minimum */
  resize:vertical; /* resize vertikal */
}
.helpText{
  margin-top:6px; /* jarak atas */
  font-size:11px; /* ukuran */
  color:var(--muted); /* warna */
  line-height:1.5; /* jarak baris */
}
.btn{
  display:inline-block; /* inline block */
  width:100%; /* lebar penuh */
  border:none; /* tanpa border */
  border-radius:16px; /* sudut */
  padding:14px; /* padding */
  margin-top:16px; /* jarak atas */
  background:linear-gradient(180deg,#22c55e,#16a34a); /* hijau gradasi */
  color:#fff; /* putih */
  font-family:'Poppins',sans-serif; /* font */
  font-size:14px; /* ukuran */
  font-weight:800; /* tebal */
  cursor:pointer; /* kursor tangan */
}
.btnBack{
  display:inline-block; /* inline block */
  text-decoration:none; /* tanpa underline */
  width:100%; /* lebar penuh */
  text-align:center; /* rata tengah */
  padding:13px; /* padding */
  border-radius:16px; /* sudut */
  background:#fff; /* putih */
  border:1px solid var(--line); /* border */
  color:#1f82ff; /* warna biru */
  font-size:13px; /* ukuran */
  font-weight:700; /* tebal */
  margin-top:10px; /* jarak atas */
}
.alert{
  margin-top:14px; /* jarak atas */
  padding:12px 14px; /* padding */
  border-radius:14px; /* sudut */
  font-size:12px; /* ukuran */
  line-height:1.6; /* jarak baris */
  font-weight:500; /* tebal sedang */
}
.alertSuccess{
  background:var(--successBg); /* latar sukses */
  color:var(--successText); /* teks sukses */
  border:1px solid var(--successBorder); /* border sukses */
}
.alertError{
  background:var(--dangerBg); /* latar error */
  color:var(--dangerText); /* teks error */
  border:1px solid var(--dangerBorder); /* border error */
}
.alertInfo{
  background:var(--infoBg); /* latar info */
  color:var(--infoText); /* teks info */
  border:1px solid var(--infoBorder); /* border info */
}
.disabledBox{
  margin-top:12px; /* jarak atas */
  padding:14px; /* padding */
  border-radius:14px; /* sudut */
  background:var(--softBox); /* latar */
  border:1px solid var(--softBorder); /* border */
  font-size:13px; /* ukuran */
  line-height:1.7; /* jarak baris */
  color:#334155; /* warna */
}
</style>
</head>
<body>
<div class="app"> <!-- pembungkus utama -->
  <div class="topBlue"> <!-- header biru -->
    <div class="topRow"> <!-- baris atas -->
      <div class="profileBox"> <!-- box profil -->
        <div class="avatar"><?= strtoupper(substr(trim($nama), 0, 1)); ?></div> <!-- huruf pertama nama -->
        <div class="profileText"> <!-- teks profil -->
          <h2><?= htmlspecialchars($nama) ?></h2> <!-- tampilkan nama -->
          <p><?= htmlspecialchars($user_id) ?></p> <!-- tampilkan user_id -->
        </div>
      </div>
      <div class="iconTop">🤒</div> <!-- icon sakit -->
    </div>

    <div class="brandCenter"> <!-- area judul tengah -->
      <div class="title">Form Sakit</div> <!-- judul -->
      <div class="sub">Pengajuan sakit <?= htmlspecialchars($label_role) ?></div> <!-- subjudul -->
      <div class="roleBadge"><?= htmlspecialchars($label_role) ?></div> <!-- badge role -->
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama -->
    <div class="card"> <!-- card jam -->
      <div class="clockBox">
        <div class="clockLabel">Waktu Server</div> <!-- label -->
        <div class="clockValue" id="clockText" data-serverms="<?= $serverMs; ?>">Memuat waktu...</div> <!-- jam realtime -->
      </div>
    </div>

    <div class="card"> <!-- card form sakit -->
      <div class="pageTitle">Form Sakit</div> <!-- judul -->
      <div class="pageDesc">Upload surat dokter untuk pengajuan sakit.</div> <!-- deskripsi -->

      <?php if ($pesan !== ''): ?> <!-- kalau ada pesan sukses -->
        <div class="alert alertSuccess"><?= htmlspecialchars($pesan) ?></div> <!-- tampilkan pesan sukses -->
      <?php endif; ?>

      <?php if ($error !== ''): ?> <!-- kalau ada pesan error -->
        <div class="alert alertError"><?= htmlspecialchars($error) ?></div> <!-- tampilkan error -->
      <?php endif; ?>

      <?php if ($blok): ?> <!-- kalau form diblok -->
        <div class="alert alertInfo">
          Hari ini kamu sudah tercatat sebagai <b><?= htmlspecialchars(strtoupper($statusBlok)) ?></b>, <!-- tampilkan status blok -->
          jadi tidak bisa mengajukan sakit lagi.
        </div>

        <div class="disabledBox">
          Form sakit dinonaktifkan karena status absensi hari ini sudah terisi. <!-- penjelasan -->
        </div>
      <?php else: ?> <!-- kalau form masih aktif -->
        <form method="post" enctype="multipart/form-data"> <!-- form POST dengan upload file -->
          <div class="formGroup">
            <label>Keterangan Sakit</label> <!-- label -->
            <textarea name="keterangan" required><?= htmlspecialchars($_POST['keterangan'] ?? '') ?></textarea> <!-- textarea keterangan -->
          </div>

          <div class="formGroup">
            <label>Upload Surat Dokter</label> <!-- label -->
            <input type="file" name="surat_dokter" accept=".jpg,.jpeg,.png,.webp,.pdf" required> <!-- upload surat dokter -->
            <div class="helpText">Format JPG, JPEG, PNG, WEBP, atau PDF. Maksimal 3MB.</div> <!-- petunjuk -->
          </div>

          <button type="submit" class="btn">Kirim Pengajuan Sakit</button> <!-- tombol submit -->
        </form>
      <?php endif; ?>

      <a href="<?= htmlspecialchars($dashboard_link) ?>" class="btnBack">Kembali ke Dashboard</a> <!-- tombol kembali -->
    </div>
  </div>
</div>

<script>
(() => { // function langsung jalan otomatis
  const el = document.getElementById('clockText'); // ambil elemen jam
  if (!el) return; // kalau elemen tidak ada, stop

  const serverMs = Number(el.dataset.serverms || 0); // ambil waktu server dari atribut HTML
  const offset = serverMs - Date.now(); // hitung selisih waktu server dengan browser
  const pad = n => String(n).padStart(2,'0'); // function untuk menambah nol di depan angka
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; // array nama bulan

  function tick(){ // function update jam
    const now = new Date(Date.now() + offset); // waktu browser disesuaikan dengan server
    el.textContent = `${pad(now.getDate())} ${bulan[now.getMonth()]} ${now.getFullYear()}, ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())} WIB`; // tampilkan format jam
  }

  tick(); // jalankan sekali di awal
  setInterval(tick, 1000); // update setiap 1 detik
})();
</script>
</body>
</html>