<?php // membuka tag PHP

if (session_status() === PHP_SESSION_NONE) session_start(); // cek apakah session belum aktif, kalau belum maka aktifkan session
include "koneksi.php"; // memanggil file koneksi database
date_default_timezone_set("Asia/Jakarta"); // mengatur zona waktu server ke Asia/Jakarta (WIB)

// ======================
// DETEKSI LOGIN ADMIN / KARYAWAN
// ======================
$nama = ''; // variabel nama user, default kosong
$user_id = ''; // variabel user_id, default kosong
$tgl_hari_ini = date("Y-m-d"); // ambil tanggal hari ini format tahun-bulan-hari
$serverMs = (int) round(microtime(true) * 1000); // ambil waktu server dalam milidetik untuk jam realtime
$TABEL_ABSENSI = ''; // nama tabel absensi akan ditentukan berdasarkan role login
$dashboard_link = ''; // link dashboard akan ditentukan berdasarkan role login
$label_role = ''; // label role untuk ditampilkan di halaman
$TABEL_CUTI = 'pengajuan_cuti'; // nama tabel pengajuan cuti

if (isset($_SESSION['admin_login']) && $_SESSION['admin_login'] === true) { // cek apakah session admin_login ada dan bernilai true

    if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'admin') { // validasi role harus admin
        header("Location: login_admin.php"); // kalau role tidak valid, arahkan ke login admin
        exit; // hentikan program
    }

    $nama = trim($_SESSION['nama'] ?? 'Admin'); // ambil nama dari session, default Admin
    $user_id = trim($_SESSION['user_id'] ?? ''); // ambil user_id dari session
    $TABEL_ABSENSI = "admin_users"; // kalau admin, pakai tabel absensi admin_users
    $dashboard_link = "admin.php"; // kalau admin, tombol kembali diarahkan ke admin.php
    $label_role = "admin"; // label role = admin

} elseif (isset($_SESSION['karyawan_login']) && $_SESSION['karyawan_login'] === true) { // kalau bukan admin, cek apakah login sebagai karyawan

    if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'karyawan') { // validasi role harus karyawan
        header("Location: login_karyawan.php"); // kalau role tidak valid, arahkan ke login karyawan
        exit; // hentikan program
    }

    $nama = trim($_SESSION['nama'] ?? 'Karyawan'); // ambil nama session, default Karyawan
    $user_id = trim($_SESSION['user_id'] ?? ''); // ambil user_id dari session
    $TABEL_ABSENSI = "karyawan_users"; // kalau karyawan, pakai tabel absensi karyawan_users
    $dashboard_link = "karyawan.php"; // kalau karyawan, tombol kembali diarahkan ke karyawan.php
    $label_role = "karyawan"; // label role = karyawan

} else { // kalau tidak login admin dan tidak login karyawan
    header("Location: login_karyawan.php"); // arahkan ke login_karyawan.php
    exit; // hentikan program
}

if ($user_id === '') { // kalau user_id kosong
    die("Session user_id kosong. Silakan login ulang."); // hentikan program dan tampilkan pesan error
}

$pesan = ""; // variabel pesan sukses
$error = ""; // variabel pesan error

// ======================
// CEK TABEL CUTI ADA / TIDAK
// ======================
$cekTabelCuti = mysqli_query($conn, "SHOW TABLES LIKE '$TABEL_CUTI'"); // query cek apakah tabel pengajuan_cuti ada
if (!$cekTabelCuti || mysqli_num_rows($cekTabelCuti) == 0) { // kalau query gagal atau tabel tidak ditemukan
    die("Tabel '$TABEL_CUTI' tidak ditemukan."); // hentikan program dan tampilkan error
}

// ======================
// AMBIL MAC ADDRESS
// ======================
$ip_address = $_SERVER['REMOTE_ADDR'] ?? ''; // ambil IP address user yang sedang mengakses

function getMacAddress($ip) // function untuk mencoba membaca MAC address dari IP
{
    $mac = ""; // default MAC kosong

    if ($ip === "") { // kalau IP kosong
        return $mac; // langsung return kosong
    }

    @exec("ping -n 1 " . escapeshellarg($ip)); // ping 1 kali ke IP agar masuk ke ARP table, @ untuk sembunyikan warning
    $arp = @shell_exec("arp -a"); // ambil isi tabel ARP

    if (!$arp) { // kalau hasil ARP kosong
        return $mac; // return kosong
    }

    $lines = preg_split("/\r\n|\n|\r/", $arp); // pecah output ARP menjadi beberapa baris

    foreach ($lines as $line) { // loop setiap baris
        if (stripos($line, $ip) !== false) { // kalau baris tersebut mengandung IP
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

$mac_address = getMacAddress($ip_address); // panggil function untuk ambil MAC address
if ($mac_address === '') { // kalau hasil kosong
    $mac_address = 'Tidak terbaca'; // isi default Tidak terbaca
}

// ======================
// FUNGSI CEK STATUS ABSENSI HARI INI
// ======================
function cekStatusHariIni($conn, $tabel, $user_id, $tanggal) // function untuk cek apakah hari ini user sudah punya data absensi
{
    $uid = mysqli_real_escape_string($conn, trim($user_id)); // amankan user_id
    $tgl = mysqli_real_escape_string($conn, trim($tanggal)); // amankan tanggal

    $q = mysqli_query($conn, "
        SELECT *
        FROM $tabel
        WHERE TRIM(user_id)=TRIM('$uid')
          AND tanggal='$tgl'
        LIMIT 1
    "); // query ambil data absensi user hari ini

    if (!$q) { // kalau query gagal
        die("SQL Error (cekStatusHariIni): " . mysqli_error($conn)); // hentikan program dan tampilkan error SQL
    }

    return mysqli_num_rows($q) > 0 ? mysqli_fetch_assoc($q) : null; // kalau ada data, return row; kalau tidak ada, return null
}

// ======================
// FUNGSI CEK PENGAJUAN CUTI HARI INI
// ======================
function cekCutiHariIni($conn, $tabel, $user_id, $tanggal) // function untuk cek apakah user sudah mengajukan cuti hari ini
{
    $uid = mysqli_real_escape_string($conn, trim($user_id)); // amankan user_id
    $tgl = mysqli_real_escape_string($conn, trim($tanggal)); // amankan tanggal

    $q = mysqli_query($conn, "
        SELECT *
        FROM $tabel
        WHERE TRIM(user_id)=TRIM('$uid')
          AND tanggal_pengajuan='$tgl'
        LIMIT 1
    "); // query ambil pengajuan cuti user pada tanggal hari ini

    if (!$q) { // kalau query gagal
        die("SQL Error (cekCutiHariIni): " . mysqli_error($conn)); // hentikan program dan tampilkan error SQL
    }

    return mysqli_num_rows($q) > 0 ? mysqli_fetch_assoc($q) : null; // kalau ada data return row, kalau tidak return null
}

$dataHariIni = cekStatusHariIni($conn, $TABEL_ABSENSI, $user_id, $tgl_hari_ini); // ambil data absensi user hari ini

$blok = false; // variabel penanda apakah form cuti diblok
$statusBlok = ''; // variabel untuk menyimpan status yang menyebabkan blokir

if ($dataHariIni) { // kalau ada data absensi hari ini
    $statusBlok = strtolower(trim($dataHariIni['status'] ?? '')); // ambil status dan ubah jadi huruf kecil

    if ($statusBlok === 'iizn') { // kalau ada typo iizn
        $statusBlok = 'izin'; // perbaiki jadi izin
    }

    // SHIFT TIDAK MEMBLOKIR CUTI
    if (in_array($statusBlok, ['hadir', 'izin', 'sakit', 'cuti', 'lembur'])) { // kalau status termasuk daftar ini
        $blok = true; // maka form cuti diblok
    }
}

// ======================
// PROSES SIMPAN CUTI
// ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // kalau form dikirim dengan method POST

    if ($blok) { // kalau form memang sedang diblok
        $error = "Hari ini kamu sudah tercatat sebagai " . strtoupper($statusBlok) . ", jadi tidak bisa mengajukan cuti lagi."; // tampilkan pesan error
    } else { // kalau tidak diblok
        $mulai_cuti = trim($_POST['mulai_cuti'] ?? ''); // ambil tanggal mulai cuti
        $akhir_cuti = trim($_POST['akhir_cuti'] ?? ''); // ambil tanggal akhir cuti
        $keterangan = trim($_POST['keterangan'] ?? ''); // ambil alasan/keterangan cuti

        $cekAbsensi = cekStatusHariIni($conn, $TABEL_ABSENSI, $user_id, $tgl_hari_ini); // cek ulang status absensi hari ini
        $cekCuti = cekCutiHariIni($conn, $TABEL_CUTI, $user_id, $tgl_hari_ini); // cek ulang apakah sudah pernah kirim cuti hari ini

        $statusSekarang = ''; // variabel status sekarang
        if ($cekAbsensi) { // kalau ada absensi
            $statusSekarang = strtolower(trim($cekAbsensi['status'] ?? '')); // ambil status
            if ($statusSekarang === 'iizn') { // kalau typo iizn
                $statusSekarang = 'izin'; // perbaiki jadi izin
            }
        }

        // SHIFT TIDAK MEMBLOKIR CUTI
        if ($cekAbsensi && in_array($statusSekarang, ['hadir', 'izin', 'sakit', 'cuti', 'lembur'])) { // kalau status absensi sudah termasuk daftar ini
            $error = "⚠️ Kamu sudah tercatat " . strtoupper($statusSekarang) . " hari ini."; // tampilkan error
            $blok = true; // blok form
            $statusBlok = $statusSekarang; // simpan penyebab blok

        } elseif ($cekCuti) { // kalau sudah pernah kirim cuti hari ini
            $error = "⚠️ Pengajuan cuti hari ini sudah pernah dikirim."; // tampilkan error

        } elseif ($mulai_cuti === '' || $akhir_cuti === '') { // kalau tanggal mulai atau akhir kosong
            $error = "Tanggal mulai cuti dan akhir cuti wajib diisi."; // tampilkan error

        } elseif ($mulai_cuti > $akhir_cuti) { // kalau tanggal awal lebih besar dari tanggal akhir
            $error = "Tanggal akhir cuti harus sama atau setelah tanggal mulai cuti."; // tampilkan error

        } elseif ($keterangan === '') { // kalau keterangan kosong
            $error = "Keterangan cuti wajib diisi."; // tampilkan error

        } else { // kalau semua validasi lolos
            $uid      = mysqli_real_escape_string($conn, $user_id); // amankan user_id
            $namaSafe = mysqli_real_escape_string($conn, $nama); // amankan nama
            $mulai    = mysqli_real_escape_string($conn, $mulai_cuti); // amankan tanggal mulai
            $akhir    = mysqli_real_escape_string($conn, $akhir_cuti); // amankan tanggal akhir
            $ket      = mysqli_real_escape_string($conn, $keterangan); // amankan keterangan
            $macSafe  = mysqli_real_escape_string($conn, $mac_address); // amankan MAC address

            mysqli_begin_transaction($conn); // mulai transaksi database

            try { // mulai blok try
                // 1. simpan ke tabel pengajuan cuti
                $insCuti = mysqli_query($conn, "
                    INSERT INTO $TABEL_CUTI
                    (user_id, nama, tanggal_pengajuan, mulai_cuti, akhir_cuti, keterangan, status, created_at)
                    VALUES
                    ('$uid', '$namaSafe', '$tgl_hari_ini', '$mulai', '$akhir', '$ket', 'cuti', NOW())
                "); // query insert ke tabel pengajuan_cuti

                if (!$insCuti) { // kalau insert cuti gagal
                    throw new Exception("SQL Error pengajuan_cuti: " . mysqli_error($conn)); // lempar exception
                }

                // 2. simpan juga ke tabel absensi
                $insAbsensi = mysqli_query($conn, "
                    INSERT INTO $TABEL_ABSENSI
                    (user_id, nama, tanggal, status, keterangan, mac_address)
                    VALUES
                    ('$uid', '$namaSafe', '$tgl_hari_ini', 'cuti', '$ket', '$macSafe')
                "); // query insert ke tabel absensi dengan status cuti

                if (!$insAbsensi) { // kalau insert absensi gagal
                    throw new Exception("SQL Error absensi: " . mysqli_error($conn)); // lempar exception
                }

                mysqli_commit($conn); // kalau semua berhasil, simpan permanen transaksi

                $pesan = "✅ Cuti berhasil dikirim dan status absensi hari ini menjadi CUTI."; // isi pesan sukses
                $blok = true; // setelah sukses, blok form
                $statusBlok = "cuti"; // status blok jadi cuti
                $_POST = []; // kosongkan input POST agar form bersih

            } catch (Exception $e) { // kalau ada error di dalam try
                mysqli_rollback($conn); // batalkan semua query dalam transaksi
                $error = $e->getMessage(); // simpan pesan error
            }
        }
    }
}
?>
<!DOCTYPE html> <!-- deklarasi HTML5 -->
<html lang="id"> <!-- awal dokumen HTML bahasa Indonesia -->
<head>
<meta charset="UTF-8"> <!-- set encoding UTF-8 -->
<meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- supaya responsive -->
<title>Form Cuti</title> <!-- judul halaman -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"> <!-- import font Poppins -->
<style>
:root{
  --primary:#1f82ff; /* warna biru utama */
  --primary2:#0d67e6; /* warna biru kedua */
  --bg:#eef4fb; /* warna background */
  --text:#18202a; /* warna teks utama */
  --muted:#7c8ca5; /* warna teks sekunder */
  --white:#ffffff; /* putih */
  --line:#e7edf5; /* warna border */
  --shadow:0 12px 30px rgba(24,54,95,.10); /* bayangan */
  --successBg:#eafaf0; /* background sukses */
  --successText:#15803d; /* teks sukses */
  --successBorder:#bbf7d0; /* border sukses */
  --dangerBg:#fef2f2; /* background error */
  --dangerText:#b91c1c; /* teks error */
  --dangerBorder:#fecaca; /* border error */
  --infoBg:#eef7ff; /* background info */
  --infoText:#1d4f8f; /* teks info */
  --infoBorder:#d8eaff; /* border info */
  --softBox:#f8fafc; /* box soft */
  --softBorder:#dbe7f3; /* border soft */
}
*{
  margin:0; /* reset margin */
  padding:0; /* reset padding */
  box-sizing:border-box; /* ukuran termasuk padding dan border */
}
body{
  font-family:'Poppins',sans-serif; /* font utama */
  background:linear-gradient(180deg,#dfeefd 0%,#eef4fb 30%,#eef4fb 100%); /* background gradasi */
  color:var(--text); /* warna teks */
}
.app{
  width:100%; /* lebar penuh */
  min-height:100vh; /* tinggi minimal 1 layar */
  margin:0 auto; /* rata tengah */
  background:var(--bg); /* background */
}
.topBlue{
  background:linear-gradient(180deg,var(--primary),var(--primary2)); /* gradasi header */
  padding:20px 16px 72px; /* padding header */
  border-bottom-left-radius:28px; /* sudut kiri bawah */
  border-bottom-right-radius:28px; /* sudut kanan bawah */
  color:#fff; /* teks putih */
}
.topRow{
  display:flex; /* flexbox */
  justify-content:space-between; /* kiri kanan */
  align-items:center; /* tengah vertikal */
  gap:12px; /* jarak */
}
.profileBox{
  display:flex; /* flex */
  align-items:center; /* tengah */
  gap:12px; /* jarak */
}
.avatar{
  width:48px; /* lebar */
  height:48px; /* tinggi */
  border-radius:50%; /* bulat */
  background:rgba(255,255,255,.22); /* latar transparan */
  border:2px solid rgba(255,255,255,.35); /* border */
  display:flex; /* flex */
  align-items:center; /* tengah vertikal */
  justify-content:center; /* tengah horizontal */
  font-size:18px; /* ukuran huruf */
  font-weight:800; /* tebal */
}
.profileText h2{
  font-size:15px; /* ukuran nama */
  font-weight:700; /* tebal */
}
.profileText p{
  font-size:11px; /* ukuran user_id */
  opacity:.92; /* transparansi */
  margin-top:3px; /* jarak atas */
}
.iconTop{
  width:40px; /* lebar */
  height:40px; /* tinggi */
  border-radius:50%; /* bulat */
  background:rgba(255,255,255,.17); /* latar transparan */
  border:1px solid rgba(255,255,255,.22); /* border */
  display:flex; /* flex */
  align-items:center; /* tengah */
  justify-content:center; /* tengah */
  font-size:18px; /* ukuran icon */
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
  opacity:.92; /* transparansi */
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
}
.mainContent{
  padding:0 14px 24px; /* padding */
  margin-top:-42px; /* naik ke atas menumpuk header */
}
.card{
  background:var(--white); /* putih */
  border-radius:20px; /* sudut */
  box-shadow:var(--shadow); /* bayangan */
  padding:16px; /* padding */
  margin-bottom:16px; /* jarak bawah */
  width:100%; /* lebar penuh */
}
.pageTitle{
  font-size:18px; /* ukuran judul */
  font-weight:800; /* tebal */
  margin-bottom:6px; /* jarak bawah */
}
.pageDesc{
  font-size:12px; /* ukuran kecil */
  color:var(--muted); /* abu */
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
input, textarea{
  width:100%; /* lebar penuh */
  padding:12px 14px; /* padding */
  border-radius:14px; /* sudut */
  border:1px solid var(--line); /* border */
  font-family:'Poppins',sans-serif; /* font */
  font-size:13px; /* ukuran */
  outline:none; /* hilangkan outline default */
  background:#fbfdff; /* background */
}
textarea{
  min-height:120px; /* tinggi minimum */
  resize:vertical; /* bisa diresize vertikal */
}
.row2{
  display:grid; /* grid */
  grid-template-columns:1fr 1fr; /* dua kolom */
  gap:12px; /* jarak */
}
.btn{
  display:inline-block; /* inline block */
  width:100%; /* lebar penuh */
  border:none; /* tanpa border */
  border-radius:16px; /* sudut */
  padding:14px; /* padding */
  margin-top:16px; /* jarak atas */
  background:linear-gradient(180deg,#22c55e,#16a34a); /* hijau gradasi */
  color:#fff; /* teks putih */
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
  color:#1f82ff; /* biru */
  font-size:13px; /* ukuran */
  font-weight:700; /* tebal */
  margin-top:10px; /* jarak atas */
}
.clockBox{
  background:#f8fbff; /* background box jam */
  border:1px solid var(--line); /* border */
  border-radius:16px; /* sudut */
  padding:12px; /* padding */
  text-align:center; /* rata tengah */
}
.clockLabel{
  font-size:11px; /* ukuran */
  color:var(--muted); /* abu */
  margin-bottom:4px; /* jarak bawah */
}
.clockValue{
  font-size:16px; /* ukuran */
  font-weight:800; /* tebal */
  color:#203349; /* warna */
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
@media (max-width:480px){
  .brandCenter .title{
    font-size:22px; /* judul lebih kecil di hp */
  }
  .brandCenter .sub{
    font-size:11px; /* subjudul lebih kecil */
  }
  .mainContent{
    padding:0 12px 20px; /* padding lebih kecil */
    margin-top:-34px; /* tumpukan lebih kecil */
  }
  .card{
    padding:14px; /* padding card lebih kecil */
    border-radius:18px; /* sudut sedikit lebih kecil */
  }
  .pageTitle{
    font-size:17px; /* judul card lebih kecil */
  }
  input,textarea{
    padding:11px 12px; /* padding input lebih kecil */
    font-size:13px; /* ukuran */
  }
  .btn,.btnBack{
    padding:13px; /* padding tombol lebih kecil */
    font-size:13px; /* ukuran */
  }
}
@media (max-width:380px){
  .row2{
    grid-template-columns:1fr; /* kalau layar sangat kecil, 2 kolom jadi 1 kolom */
  }
}
</style>
</head>
<body>
<div class="app"> <!-- pembungkus utama -->
  <div class="topBlue"> <!-- header biru -->
    <div class="topRow"> <!-- baris atas -->
      <div class="profileBox"> <!-- box profil -->
        <div class="avatar"><?= strtoupper(substr(trim($nama), 0, 1)); ?></div> <!-- avatar berisi huruf pertama nama -->
        <div class="profileText"> <!-- teks profil -->
          <h2><?= htmlspecialchars($nama) ?></h2> <!-- tampilkan nama -->
          <p><?= htmlspecialchars($user_id) ?></p> <!-- tampilkan user_id -->
        </div>
      </div>
      <div class="iconTop">🏖️</div> <!-- icon cuti -->
    </div>

    <div class="brandCenter"> <!-- area judul tengah -->
      <div class="title">Form Cuti</div> <!-- judul -->
      <div class="sub">Pengajuan cuti <?= htmlspecialchars($label_role) ?></div> <!-- subjudul -->
      <div class="roleBadge"><?= strtoupper($label_role) ?></div> <!-- badge role -->
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama -->
    <div class="card"> <!-- card jam server -->
      <div class="clockBox">
        <div class="clockLabel">Waktu Server</div> <!-- label -->
        <div class="clockValue" id="clockText" data-serverms="<?= $serverMs; ?>">Memuat waktu...</div> <!-- jam realtime -->
      </div>
    </div>

    <div class="card"> <!-- card form cuti -->
      <div class="pageTitle">Form Cuti</div> <!-- judul card -->
      <div class="pageDesc">Isi tanggal cuti dan alasan dengan lengkap.</div> <!-- deskripsi -->

      <?php if ($pesan !== ''): ?> <!-- kalau ada pesan sukses -->
        <div class="alert alertSuccess"><?= htmlspecialchars($pesan) ?></div> <!-- tampilkan pesan sukses -->
      <?php endif; ?>

      <?php if ($error !== ''): ?> <!-- kalau ada pesan error -->
        <div class="alert alertError"><?= htmlspecialchars($error) ?></div> <!-- tampilkan pesan error -->
      <?php endif; ?>

      <?php if ($blok): ?> <!-- kalau form diblok -->
        <div class="alert alertInfo">
          Hari ini kamu sudah tercatat sebagai <b><?= htmlspecialchars(strtoupper($statusBlok)) ?></b>, <!-- tampilkan status yang memblok -->
          jadi tidak bisa mengajukan cuti lagi.
        </div>

        <div class="disabledBox">
          Form cuti dinonaktifkan karena status absensi hari ini sudah terisi. <!-- keterangan tambahan -->
        </div>
      <?php else: ?> <!-- kalau form boleh dipakai -->
        <form method="post"> <!-- form kirim POST -->
          <div class="formGroup row2"> <!-- group 2 kolom -->
            <div>
              <label>Mulai Cuti</label> <!-- label mulai -->
              <input type="date" name="mulai_cuti" value="<?= htmlspecialchars($_POST['mulai_cuti'] ?? '') ?>" required> <!-- input mulai cuti -->
            </div>
            <div>
              <label>Akhir Cuti</label> <!-- label akhir -->
              <input type="date" name="akhir_cuti" value="<?= htmlspecialchars($_POST['akhir_cuti'] ?? '') ?>" required> <!-- input akhir cuti -->
            </div>
          </div>

          <div class="formGroup">
            <label>Keterangan Cuti</label> <!-- label keterangan -->
            <textarea name="keterangan" placeholder="Tulis alasan cuti..." required><?= htmlspecialchars($_POST['keterangan'] ?? '') ?></textarea> <!-- textarea alasan cuti -->
          </div>

          <button type="submit" class="btn">Kirim Cuti</button> <!-- tombol submit -->
        </form>
      <?php endif; ?>

      <a href="<?= htmlspecialchars($dashboard_link) ?>" class="btnBack">Kembali ke Dashboard</a> <!-- tombol kembali -->
    </div>
  </div>
</div>

<script>
(() => { // function langsung jalan otomatis
  const el = document.getElementById('clockText'); // ambil elemen jam
  if (!el) return; // kalau elemen tidak ada, hentikan

  const serverMs = Number(el.dataset.serverms || 0); // ambil waktu server dari data-serverms
  const offset = serverMs - Date.now(); // hitung selisih waktu server dengan browser
  const pad = n => String(n).padStart(2,'0'); // function untuk tambah angka nol di depan
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; // array nama bulan

  function tick(){ // function update jam
    const now = new Date(Date.now() + offset); // waktu sekarang disesuaikan dengan offset server
    el.textContent = `${pad(now.getDate())} ${bulan[now.getMonth()]} ${now.getFullYear()}, ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())} WIB`; // tampilkan format jam
  }

  tick(); // tampilkan sekali saat awal
  setInterval(tick, 1000); // update setiap 1 detik
})();
</script>
</body>
</html>