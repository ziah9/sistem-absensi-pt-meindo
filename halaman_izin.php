<?php // membuka tag PHP

if (session_status() === PHP_SESSION_NONE) { // cek apakah session belum aktif
    session_start(); // kalau belum aktif, jalankan session
}

include "koneksi.php"; // memanggil file koneksi database
date_default_timezone_set("Asia/Jakarta"); // mengatur zona waktu ke WIB

$selfPage = basename($_SERVER['PHP_SELF']); // ambil nama file PHP yang sedang dibuka sekarang

// ======================
// CEK LOGIN ADMIN / KARYAWAN
// ======================
$isAdmin    = isset($_SESSION['admin_login']) && $_SESSION['admin_login'] === true; // cek apakah user login sebagai admin
$isKaryawan = isset($_SESSION['karyawan_login']) && $_SESSION['karyawan_login'] === true; // cek apakah user login sebagai karyawan

if (!$isAdmin && !$isKaryawan) { // kalau tidak login admin dan tidak login karyawan
    header("Location: login_karyawan.php"); // arahkan ke halaman login karyawan
    exit; // hentikan program
}

$role     = strtolower(trim($_SESSION['role'] ?? '')); // ambil role dari session lalu ubah ke huruf kecil
$user_id  = trim($_SESSION['user_id'] ?? ''); // ambil user_id dari session
$nama     = trim($_SESSION['nama'] ?? ''); // ambil nama dari session
$tanggal  = date("Y-m-d"); // ambil tanggal hari ini
$serverMs = (int) round(microtime(true) * 1000); // ambil waktu server dalam milidetik
// microtime(true) = waktu pecahan detik
// * 1000 = ubah ke milidetik
// round = bulatkan
// (int) = ubah jadi integer

if ($user_id === '') { // kalau user_id kosong
    header("Location: " . ($isAdmin ? "login_admin.php" : "login_karyawan.php")); // kalau admin ke login_admin, kalau karyawan ke login_karyawan
    exit; // hentikan program
}

// ======================
// TABEL / BACKPAGE / JUDUL ROLE
// ======================
if ($isAdmin) { // kalau login sebagai admin
    $tabel_absen = "admin_users"; // pakai tabel absensi admin
    $tabel_izin  = "izin_karyawan"; // tabel izin yang dipakai
    $backPage    = "admin.php"; // tombol kembali mengarah ke admin.php
    $judulRole   = "ADMIN"; // judul role untuk tampilan
} else { // kalau login sebagai karyawan
    $tabel_absen = "karyawan_users"; // pakai tabel absensi karyawan
    $tabel_izin  = "izin_karyawan"; // tabel izin yang dipakai tetap sama
    $backPage    = "karyawan.php"; // tombol kembali mengarah ke karyawan.php
    $judulRole   = "KARYAWAN"; // judul role untuk tampilan
}

$pesan = ""; // variabel pesan sukses
$error = ""; // variabel pesan error

// ======================
// AMBIL NAMA DARI DB JIKA SESSION NAMA KOSONG
// ======================
if ($nama === '') { // kalau nama di session kosong
    $safeUserIdNama = mysqli_real_escape_string($conn, $user_id); // amankan user_id untuk query SQL
    $qNama = mysqli_query($conn, "
        SELECT COALESCE(name, '') AS name, COALESCE(role, '') AS role
        FROM users
        WHERE TRIM(user_id)=TRIM('$safeUserIdNama')
        LIMIT 1
    "); // query ambil nama dan role dari tabel users berdasarkan user_id

    if ($qNama && mysqli_num_rows($qNama) > 0) { // kalau query berhasil dan data ditemukan
        $dNama = mysqli_fetch_assoc($qNama); // ambil data hasil query
        $namaDb = trim($dNama['name'] ?? ''); // ambil nama dari database
        $roleDb = strtolower(trim($dNama['role'] ?? '')); // ambil role dari database

        if ($namaDb !== '') { // kalau nama dari database tidak kosong
            $nama = $namaDb; // isi variabel nama
            $_SESSION['nama'] = $namaDb; // simpan juga ke session
        }

        if ($role === '' && $roleDb !== '') { // kalau role session kosong tapi role database ada
            $role = $roleDb; // isi variabel role
            $_SESSION['role'] = $roleDb; // simpan juga ke session
        }
    }
}

if ($nama === '') { // kalau nama masih kosong juga
    $nama = $user_id; // pakai user_id sebagai nama fallback
}

// ======================
// AMBIL MAC
// ======================
$ip = $_SERVER['REMOTE_ADDR'] ?? ''; // ambil IP address user

function getMacAddressGabung($ip) // function untuk mencoba membaca MAC address dari IP
{
    $mac = ""; // default MAC kosong

    if ($ip === "") { // kalau IP kosong
        return $mac; // return kosong
    }

    @exec("ping -n 1 " . escapeshellarg($ip)); // ping IP 1 kali supaya masuk ke ARP cache
    $arp = @shell_exec("arp -a"); // baca tabel ARP

    if (!$arp) { // kalau output ARP kosong
        return $mac; // return kosong
    }

    $lines = preg_split("/\r\n|\n|\r/", $arp); // pecah output ARP per baris

    foreach ($lines as $line) { // loop tiap baris
        if (stripos($line, $ip) !== false) { // kalau baris itu mengandung IP yang dicari
            $parts = preg_split('/\s+/', trim($line)); // pecah baris berdasarkan spasi
            foreach ($parts as $part) { // loop tiap potongan kata
                if (preg_match('/^([0-9a-f]{2}[-:]){5}[0-9a-f]{2}$/i', $part)) { // cek apakah formatnya MAC address
                    return $part; // kalau iya, langsung return MAC address
                }
            }
        }
    }

    return $mac; // kalau tidak ketemu, return kosong
}

$mac_address = getMacAddressGabung($ip); // panggil function untuk ambil MAC
if ($mac_address === '') { // kalau hasil kosong
    $mac_address = 'Tidak terbaca'; // isi default
}

// ======================
// CEK STATUS HARI INI
// ======================
$safeUser = mysqli_real_escape_string($conn, $user_id); // amankan user_id
$safeTgl  = mysqli_real_escape_string($conn, $tanggal); // amankan tanggal

$cek = mysqli_query($conn, "
    SELECT *
    FROM $tabel_absen
    WHERE TRIM(user_id)=TRIM('$safeUser')
      AND tanggal='$safeTgl'
    LIMIT 1
"); // query cek data absensi hari ini

if (!$cek) { // kalau query gagal
    die("SQL Error (cek status): " . mysqli_error($conn)); // hentikan program dan tampilkan error
}

$data = mysqli_fetch_assoc($cek); // ambil data absensi hari ini

$sudah = false; // penanda apakah hari ini sudah ada status absensi
$statusBlok = ''; // menyimpan status yang memblok

if ($data) { // kalau ada data absensi hari ini
    $statusRaw = strtolower(trim($data['status'] ?? '')); // ambil status dan ubah jadi huruf kecil

    if ($statusRaw === 'iizn') { // kalau ada typo iizn
        $statusRaw = 'izin'; // ubah jadi izin
    }

    if (in_array($statusRaw, ['hadir', 'izin', 'cuti', 'sakit', 'lembur'], true)) { // kalau status termasuk daftar ini
        $sudah = true; // tandai sudah ada status
        $statusBlok = $statusRaw; // simpan status pemblokir
    }
}

// ======================
// PROSES KIRIM IZIN
// ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim'])) { // kalau form dikirim dan tombol kirim ditekan

    if ($sudah) { // kalau hari ini sudah punya status
        $error = "Hari ini kamu sudah tercatat sebagai " . strtoupper($statusBlok) . ", jadi tidak bisa mengajukan izin lagi."; // tampilkan error
    } else { // kalau belum ada status
        $tgl    = trim($_POST['tanggal'] ?? ''); // ambil tanggal izin dari form
        $jenis  = trim($_POST['jenis'] ?? ''); // ambil jenis izin dari form
        $alasan = trim($_POST['alasan'] ?? ''); // ambil alasan izin dari form

        if ($tgl === '') { // kalau tanggal kosong
            $error = "Tanggal izin wajib diisi."; // error tanggal
        } elseif ($jenis === '') { // kalau jenis kosong
            $error = "Jenis izin wajib dipilih."; // error jenis
        } elseif ($alasan === '') { // kalau alasan kosong
            $error = "Alasan izin wajib diisi."; // error alasan
        } else { // kalau input dasar sudah lengkap
            $safeTglInput = mysqli_real_escape_string($conn, $tgl); // amankan tanggal input

            $cekTanggal = mysqli_query($conn, "
                SELECT *
                FROM $tabel_absen
                WHERE TRIM(user_id)=TRIM('$safeUser')
                  AND tanggal='$safeTglInput'
                LIMIT 1
            "); // cek apakah di tanggal itu sudah ada data absensi

            if (!$cekTanggal) { // kalau query gagal
                die("SQL Error (cek tanggal): " . mysqli_error($conn)); // hentikan program dan tampilkan error
            }

            $dataTanggal = mysqli_fetch_assoc($cekTanggal); // ambil data di tanggal itu

            if ($dataTanggal) { // kalau sudah ada data di tanggal itu
                $statusTanggal = strtolower(trim($dataTanggal['status'] ?? '')); // ambil statusnya

                if ($statusTanggal === 'iizn') { // kalau typo iizn
                    $statusTanggal = 'izin'; // ubah jadi izin
                }

                if (in_array($statusTanggal, ['hadir', 'izin', 'cuti', 'sakit', 'lembur'], true)) { // kalau status termasuk daftar blok
                    $error = "Pada tanggal $tgl kamu sudah tercatat sebagai " . strtoupper($statusTanggal) . "."; // tampilkan error
                } else { // kalau ada data tapi status tidak dikenal
                    $error = "Pada tanggal $tgl sudah ada data absensi."; // tampilkan error umum
                }
            } else { // kalau di tanggal itu belum ada data
                $safeNama   = mysqli_real_escape_string($conn, $nama); // amankan nama
                $safeJenis  = mysqli_real_escape_string($conn, $jenis); // amankan jenis izin
                $safeAlasan = mysqli_real_escape_string($conn, $alasan); // amankan alasan
                $safeMac    = mysqli_real_escape_string($conn, $mac_address); // amankan MAC address
                $statusIzin = 'izin'; // status yang akan disimpan

                mysqli_begin_transaction($conn); // mulai transaksi database

                try { // blok try untuk menangani dua query sekaligus
                    $ins1 = mysqli_query($conn, "
                        INSERT INTO $tabel_absen
                        (user_id, nama, tanggal, status, keterangan, mac_address)
                        VALUES
                        ('$safeUser', '$safeNama', '$safeTglInput', 'izin', '$safeAlasan', '$safeMac')
                    "); // simpan juga ke tabel absensi dengan status izin

                    if (!$ins1) { // kalau insert pertama gagal
                        throw new Exception("Gagal simpan ke absensi: " . mysqli_error($conn)); // lempar error
                    }

                    $ins2 = mysqli_query($conn, "
                        INSERT INTO $tabel_izin
                        (user_id, nama, tanggal_izin, jenis_izin, alasan, mac_address, status, created_at)
                        VALUES
                        ('$safeUser', '$safeNama', '$safeTglInput', '$safeJenis', '$safeAlasan', '$safeMac', '$statusIzin', NOW())
                    "); // simpan juga ke tabel izin

                    if (!$ins2) { // kalau insert kedua gagal
                        throw new Exception("Gagal simpan ke tabel izin: " . mysqli_error($conn)); // lempar error
                    }

                    mysqli_commit($conn); // kalau dua query berhasil, simpan permanen
                    echo "<script>alert('Izin berhasil dikirim'); window.location='" . $selfPage . "';</script>"; // tampilkan alert sukses lalu reload halaman
                    exit; // hentikan program
                } catch (Exception $e) { // kalau ada error di try
                    mysqli_rollback($conn); // batalkan semua perubahan database
                    $error = $e->getMessage(); // simpan pesan error
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
<title>Form Izin</title> <!-- judul halaman -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"> <!-- font Poppins -->
<style>
:root{
  --primary:#1f82ff; /* warna utama */
  --primary2:#0d67e6; /* warna kedua */
  --bg:#eef4fb; /* background */
  --text:#18202a; /* teks utama */
  --muted:#7c8ca5; /* teks sekunder */
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
  --softBox:#f8fafc; /* box lembut */
  --softBorder:#dbe7f3; /* border lembut */
}
*{margin:0;padding:0;box-sizing:border-box} /* reset semua elemen */
body{
  font-family:'Poppins',sans-serif; /* font utama */
  background:linear-gradient(180deg,#dfeefd 0%,#eef4fb 30%,#eef4fb 100%); /* gradasi */
  color:var(--text); /* warna teks */
}
.app{
  width:100%; /* lebar penuh */
  min-height:100vh; /* tinggi minimal layar penuh */
  background:var(--bg); /* background */
}
.topBlue{
  background:linear-gradient(180deg,var(--primary),var(--primary2)); /* header biru */
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
}
.avatar{
  width:48px; /* lebar */
  height:48px; /* tinggi */
  border-radius:50%; /* bulat */
  background:rgba(255,255,255,.22); /* background transparan */
  border:2px solid rgba(255,255,255,.35); /* border */
  display:flex; /* flex */
  align-items:center; /* tengah */
  justify-content:center; /* tengah */
  font-size:18px; /* ukuran huruf */
  font-weight:800; /* tebal */
}
.profileText h2{
  font-size:15px; /* ukuran nama */
  font-weight:700; /* tebal */
}
.profileText p{
  font-size:11px; /* ukuran user id */
  opacity:.92; /* transparansi */
  margin-top:3px; /* jarak atas */
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
  background:rgba(255,255,255,.16); /* background */
  border:1px solid rgba(255,255,255,.22); /* border */
  font-size:11px; /* ukuran */
  font-weight:700; /* tebal */
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
  background:#f8fbff; /* latar box jam */
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
  display:block; /* block */
  margin-bottom:6px; /* jarak bawah */
  font-size:12px; /* ukuran */
  font-weight:600; /* tebal */
  color:#44556b; /* warna */
}
input, select, textarea{
  width:100%; /* lebar penuh */
  padding:12px 14px; /* padding */
  border-radius:14px; /* sudut */
  border:1px solid var(--line); /* border */
  font-family:'Poppins',sans-serif; /* font */
  font-size:13px; /* ukuran */
  outline:none; /* tanpa outline */
  background:#fbfdff; /* background */
}
textarea{
  min-height:120px; /* tinggi minimum */
  resize:vertical; /* resize vertikal */
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
  background:#fff; /* background putih */
  border:1px solid var(--line); /* border */
  color:#1f82ff; /* teks biru */
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
  background:var(--successBg); /* background sukses */
  color:var(--successText); /* teks sukses */
  border:1px solid var(--successBorder); /* border sukses */
}
.alertError{
  background:var(--dangerBg); /* background error */
  color:var(--dangerText); /* teks error */
  border:1px solid var(--dangerBorder); /* border error */
}
.alertInfo{
  background:var(--infoBg); /* background info */
  color:var(--infoText); /* teks info */
  border:1px solid var(--infoBorder); /* border info */
}
.disabledBox{
  margin-top:12px; /* jarak atas */
  padding:14px; /* padding */
  border-radius:14px; /* sudut */
  background:var(--softBox); /* background */
  border:1px solid var(--softBorder); /* border */
  font-size:13px; /* ukuran */
  line-height:1.7; /* jarak baris */
  color:#334155; /* warna */
}
</style>
</head>
<body>
<div class="app"> <!-- pembungkus utama -->
  <div class="topBlue"> <!-- header -->
    <div class="topRow"> <!-- baris atas -->
      <div class="profileBox"> <!-- box profil -->
        <div class="avatar"><?= strtoupper(substr(trim($nama), 0, 1)); ?></div> <!-- huruf pertama nama -->
        <div class="profileText"> <!-- teks profil -->
          <h2><?= htmlspecialchars($nama) ?></h2> <!-- nama -->
          <p><?= htmlspecialchars($user_id) ?></p> <!-- user_id -->
        </div>
      </div>
      <div class="iconTop">📝</div> <!-- icon izin -->
    </div>

    <div class="brandCenter"> <!-- area judul -->
      <div class="title">Form Izin</div> <!-- judul -->
      <div class="sub">Pengajuan izin <?= htmlspecialchars(strtolower($judulRole)) ?></div> <!-- subjudul -->
      <div class="roleBadge"><?= htmlspecialchars($judulRole) ?></div> <!-- badge role -->
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama -->
    <div class="card"> <!-- card jam -->
      <div class="clockBox">
        <div class="clockLabel">Waktu Server</div> <!-- label -->
        <div class="clockValue" id="clockText" data-serverms="<?= $serverMs; ?>">Memuat waktu...</div> <!-- jam realtime -->
      </div>
    </div>

    <div class="card"> <!-- card form -->
      <div class="pageTitle">Form Izin</div> <!-- judul form -->
      <div class="pageDesc">Isi tanggal, jenis izin, dan alasan dengan lengkap.</div> <!-- deskripsi -->

      <?php if ($pesan !== ''): ?> <!-- kalau ada pesan sukses -->
        <div class="alert alertSuccess"><?= htmlspecialchars($pesan) ?></div> <!-- tampilkan pesan sukses -->
      <?php endif; ?>

      <?php if ($error !== ''): ?> <!-- kalau ada error -->
        <div class="alert alertError"><?= htmlspecialchars($error) ?></div> <!-- tampilkan error -->
      <?php endif; ?>

      <?php if ($sudah): ?> <!-- kalau hari ini sudah punya status -->
        <div class="alert alertInfo">
          Hari ini kamu sudah tercatat sebagai <b><?= htmlspecialchars(strtoupper($statusBlok)) ?></b>, <!-- tampilkan status -->
          jadi tidak bisa mengajukan izin lagi.
        </div>

        <div class="disabledBox">
          Form izin dinonaktifkan karena status absensi hari ini sudah terisi. <!-- keterangan tambahan -->
        </div>
      <?php else: ?> <!-- kalau masih boleh kirim izin -->
        <form method="POST" action="<?= htmlspecialchars($selfPage) ?>"> <!-- form kirim POST ke halaman ini -->
          <div class="formGroup">
            <label>Tanggal Izin</label> <!-- label -->
            <input type="date" name="tanggal" value="<?= htmlspecialchars($_POST['tanggal'] ?? $tanggal) ?>" required> <!-- input tanggal -->
          </div>

          <div class="formGroup">
            <label>Jenis Izin</label> <!-- label -->
            <select name="jenis" required> <!-- dropdown jenis izin -->
              <option value="">-- pilih --</option> <!-- default -->
              <option value="Acara keluarga" <?= (($_POST['jenis'] ?? '') === 'Acara keluarga') ? 'selected' : '' ?>>Acara keluarga</option> <!-- opsi -->
              <option value="Keperluan pribadi" <?= (($_POST['jenis'] ?? '') === 'Keperluan pribadi') ? 'selected' : '' ?>>Keperluan pribadi</option> <!-- opsi -->
              <option value="Urusan administrasi" <?= (($_POST['jenis'] ?? '') === 'Urusan administrasi') ? 'selected' : '' ?>>Urusan administrasi</option> <!-- opsi -->
              <option value="Lainnya" <?= (($_POST['jenis'] ?? '') === 'Lainnya') ? 'selected' : '' ?>>Lainnya</option> <!-- opsi -->
            </select>
          </div>

          <div class="formGroup">
            <label>Alasan</label> <!-- label -->
            <textarea name="alasan" required><?= htmlspecialchars($_POST['alasan'] ?? '') ?></textarea> <!-- textarea alasan -->
          </div>

          <button type="submit" name="kirim" class="btn">Kirim Izin</button> <!-- tombol kirim -->
        </form>
      <?php endif; ?>

      <a href="<?= htmlspecialchars($backPage) ?>" class="btnBack">Kembali ke Dashboard</a> <!-- tombol kembali -->
    </div>
  </div>
</div>

<script>
(() => { // function langsung jalan otomatis
  const el = document.getElementById('clockText'); // ambil elemen jam
  if (!el) return; // kalau tidak ada, hentikan

  const serverMs = Number(el.dataset.serverms || 0); // ambil waktu server dari data attribute
  const offset = serverMs - Date.now(); // hitung selisih waktu server dan browser
  const pad = n => String(n).padStart(2,'0'); // function untuk menambah nol di depan angka
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; // array nama bulan

  function tick(){ // function update jam
    const now = new Date(Date.now() + offset); // waktu browser disesuaikan dengan offset server
    el.textContent = `${pad(now.getDate())} ${bulan[now.getMonth()]} ${now.getFullYear()}, ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())} WIB`; // tampilkan format waktu
  }

  tick(); // jalankan sekali saat awal
  setInterval(tick, 1000); // update setiap 1 detik
})();
</script>
</body>
</html>