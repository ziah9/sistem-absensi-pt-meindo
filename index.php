<?php
session_start();  // Memulai session (menyimpan data login user)
date_default_timezone_set("Asia/Jakarta"); // Mengatur zona waktu ke WIB

// AUTO REDIRECT LOGIN
if (!isset($_GET['from'])) { // Cek apakah parameter URL 'from' tidak ada from = penanda tambahan di URL untuk kontrol alur program
    if (isset($_SESSION['admin_login']) && $_SESSION['admin_login'] === true) { // Mengecek apakah session admin ada, $_SESSION['admin_login'] === true // Cek apakah admin sudah login
        header("Location: index.php"); // Redirect (pindah halaman)
        exit; // Menghentikan eksekusi kode
    }

    if (isset($_SESSION['karyawan_login']) && $_SESSION['karyawan_login'] === true) { // Session untuk user karyawan
        header("Location: karyawan.php"); // Redirect ke halaman karyawan
        exit;
    }
}

$serverMs = (int) round(microtime(true) * 1000); // Mengambil waktu server dalam milidetik, microtime(true) Mengambil waktu sekarang (dari server), * 1000 Mengubah dari detik → milidetik, round(...) Membulatkan angka biar rapi (tanpa koma), int Mengubah jadi bilangan bulat (integer), $serverMs berisi waktu
$logoFile = "assets/meindo.png"; // Menyimpan path/logo perusahaan
?>
<!doctype html>
<html lang="id"> <!-- Menentukan tipe dokumen HTML -->
<head>
<meta charset="utf-8"> <!-- Encoding karakter -->
<meta name="viewport" content="width=device-width,initial-scale=1"><!--Viewport = area layar yang dipakai untuk menampilkan website "Ini pengaturan untuk tampilan layar, content="..."Isi pengaturannya, width=device-width Lebar website = mengikuti lebar layar device"-->
<title>Sistem Absensi - PT MEINDO</title> <!-- Judul tab -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{ /* Root = variabel global CSS (bisa dipakai di seluruh file) */
  --primary:#1f82ff; /* Warna utama (biru utama aplikasi) */
  --primary2:#0d67e6; /* Warna gradasi kedua (biru lebih gelap) */
  --bg:#eef4fb; /* Warna background utama halaman */
  --text:#18202a; /* Warna teks utama */
  --muted:#7c8ca5; /* Warna teks sekunder / lebih pudar */
  --white:#ffffff; 
  --line:#e7edf5; /* Warna garis/border */
  --shadow:0 12px 30px rgba(24, 54, 95, 0.10); /* Efek bayangan (x, y, blur, warna transparan) */
  --radius-xl:28px; /* Radius sudut sangat besar (rounded besar) */
  --radius-lg:22px; /* Radius sudut besar */
  --radius-md:18px; /* Radius sudut sedang */
  --radius-sm:14px; /* Radius sudut kecil */
}

*{ /* Semua elemen */
  margin:0; /* Hilangkan jarak luar default */
  padding:0; /* Hilangkan jarak dalam default */
  box-sizing:border-box; /* Lebar elemen termasuk padding & border */
}


body{
  font-family:"Poppins",sans-serif; /* Font utama, "Poppins",sans-serif ini nama font*/
  background:linear-gradient(180deg, #dfeefd 0%, #eef4fb 30%, #eef4fb 100%);/* Background gradas, "Buat background gradasi dari atas ke bawah, dari biru muda ke lebih terang" */
  color:var(--text);/* Warna teks dari variabel */
  min-height:100vh; /* Tinggi minimal 1 layar penuh */
}

.app{
  width:100%;  /* Lebar penuh */
  min-height:100vh; /* Tinggi penuh layar */
  background:var(--bg);  /* background:Properti untuk memberi warna/latar, var(...):engambil nilai dari variabel, --bg: Nama variabel CSS (Kenapa pakai var()?Bisa dipakai ulang di banyak tempat*/
  overflow-x:hidden; /* Tidak bisa scroll ke samping */
}

.topBlue{
  background:linear-gradient(180deg, var(--primary), var(--primary2)); /* linear-gradient(...):Membuat warna gradasi (perpindahan warna halus), 180deg:Arah gradasi dari atas ke bawah, var(--primary):Ambil warna dari variabel(biru muda), var(--primary2):biru tua*/
  padding:28px 18px 130px; /* Jarak dalam (atas kanan bawah kiri) */
  border-bottom-left-radius:32px;/* Sudut kiri bawah melengkung */
  border-bottom-right-radius:32px;  /* Sudut kanan bawah melengkung */
  color:#fff; /* Warna teks putih */
}

.topRow{
  align-items:center;  /* Tengah vertikal */
}

.brandCenter{
  text-align:center; /* Teks rata tengah */
  margin-top:12px; /* Jarak atas */
}

/* Ukuran logo dan jarak logo pt */
.logoMain{
  width:110px; /* Ukuran logo */
  margin:0 auto 14px; /* Tengah + jarak bawah */
  display:block; /* Supaya bisa center */
}

.brandCenter .logoText{
  font-size:30px; /* Ukuran teks */
  font-weight:900; /* Tebal */
  line-height:1.1; /* Jarak antar baris */
  letter-spacing:.4px; /* Jarak huruf */
}

.brandCenter .logoSub{
  margin-top:6px; /* Jarak atas */
  font-size:13px; /* Ukuran kecil */
  opacity:.92; /* Sedikit transparan */
}

.mainContent{
  width:100%; /* Lebar penuh */
  max-width:1100px; /* Batas maksimal */
  padding:0 16px 20px; /* Jarak dalam */
  margin:-82px auto 0; /* Naik ke atas (overlap) */
  position:relative; /* Posisi relatif: Elemen tetap di tempat normalnya, tapi bisa digeser sedikit */
  z-index:2; /* Di atas layer lain */
}

.desktopGrid{/*atur ukuran sesuai lyr
  display:grid;/*Mengubah cara susun elemen jadi grid (kotak-kotak seperti tabel)*/
  grid-template-columns:1fr;/*Buat 1 kolom penuh, fr = fraction (bagian ruang) jadi adlah Semua isi turun ke bawah (1 kolom)*/
  gap:16px; /*Jarak antar kotak = 16px*/
}

.card{
  background:var(--white); /* Background putih */
  border-radius:var(--radius-lg); /* Sudut melengkung */
  box-shadow:var(--shadow); /* Bayangan */
   margin-bottom:20px;  /* JARAK CARD PUTIH ATAS DAN BAWHA */
}

.infoCard{
  padding:20px; /* Jarak dalam */
}

.heroTitle{
  font-size:24px; /* Ukuran besar */
  font-weight:900; /* Tebal */
  color:#203349; /* Warna teks */
  line-height:1.2; /* Jarak baris */
  margin-bottom:10px; /* Jarak bawah */
}

.heroDesc{
  font-size:13px; /* Ukuran kecil */
  color:var(--muted); /* Warna abu */
  line-height:1.75; /* Spasi baris */
  margin-bottom:14px; /* Jarak bawah */
}

.clockChip{
  display:inline-flex; /* Flex inline */
  align-items:center; /* Tengah vertikal */
  background:#f3f8ff; /* Background */
  color:var(--primary2); /* Warna teks */
  border:1px solid #d7e7ff; /* garis tepi kapsul */
  border-radius:999px; /* Bentuk lengkung kapsul */
  padding:10px 12px; /* Jarak dalam/ lebar atas bawah kapsul */
  font-size:11px; /* Ukuran font kecil */
  font-weight:700; /* Tebal */
  margin-bottom:12px; /* Jarak dengan deskripsi bawah */
}

.helpText{
  font-size:11px; /* Kecil */
  color:var(--muted); /* Abu */
  line-height:1.6; /* Spasi */
}

.actionCard{
  padding:20px; /* Jarak dalam */
}


.actionCard .sub{
  font-size:12px; /* Kecil */
  color:var(--muted); /* Abu */
  margin-bottom:14px; /* Jarak */
}

.actionButtons{
  display:flex; /* Flex */
  flex-direction:column; /* Vertikal */
  gap:12px; /* Jarak */
}

.btn{
  display:flex; /* Flex */
  align-items:center; /* Tengah */
  justify-content:space-between; /* Kiri-kanan */
  gap:10px; /* Jarak */
  text-decoration:none; /* Hilangkan garis link */
  border-radius:16px; /* Sudut */
  padding:14px 16px; /* Jarak */
  font-size:13px; /* Ukuran */
  font-weight:800; /* Tebal */
  transition:.15s ease; /* Animasi */
}

.btnSecondary{
  color:#284055; /* Warna teks hitam */
  background:#f8fbff; /* Background biru muda */
  border:1px solid var(--line); /* Border */
}

.tipBox{
  margin-top:12px; /* Jarak atas */
  padding:12px 14px; /* Jarak dalam 12 atas bawah, 14 kanan kiei*/
  border-radius:14px; /* Sudut */
  background:#f8fbff; /* Background */
  font-size:11px; /* Kecil */
  line-height:1.6; /* Spasi */
  color:var(--muted); /* Warna abu */
}

.foot{
  margin-top:16px; /* Jarak atas */
  padding:14px 16px; /* Jarak */
  display:flex; /* Flex / font center*/
  justify-content:center; /* Tengah */
  border-radius:18px; /* Sudut */
  background:rgba(255,255,255,.7); /* bg Transparan kotak */
  color:var(--muted); /* Warna abu*/
  font-size:11px; /* Kecil */
  box-shadow:var(--shadow); /* Bayangan bawah*/
}

@media (min-width: 900px){ /* kalau layar minimal 900px (desktop / laptop) */
  .desktopGrid{ /* bagian grid utama */
    grid-template-columns:1fr 1fr; /* jadi 2 kolom, kiri dan kanan sama besar */
    gap:20px; /* jarak antar kolom / card 20px */
    align-items:stretch; /* tinggi item dibuat mengikuti / sama rata */
  }

  .brandCenter .logoText{ /* tulisan judul utama di bagian logo */
    font-size:36px; /* ukuran tulisan diperbesar untuk desktop */
  }

  .brandCenter .logoSub{ /* tulisan kecil di bawah judul logo */
    font-size:14px; /* ukuran subjudul sedikit diperbesar */
  }

 .topBlue{ /* bagian header biru atas */
    padding:24px 24px 140px; /* jarak dalam atas kanan kiri bawah, bawah dibuat lebih besar */
  }

  .mainContent{ /* isi utama halaman */
    margin:-92px auto 0; /* konten dinaikkan ke atas supaya masuk ke area header */
    padding:0 24px 24px; /* jarak dalam kanan kiri bawah */
  }

  .infoCard,
  .actionCard{ /* card kiri dan kanan */
    height:100%; /* tinggi card dibuat penuh / sama tinggi */
  }
}

@media (max-width: 480px){ /* kalau layar maksimal 480px (hp kecil) */
  .topBlue{ /* header biru atas */
    padding:16px 14px 110px; /* padding diperkecil supaya pas di layar hp */
  }

  .mainContent{ /* isi utama */
    padding:0 12px 18px; /* jarak kanan kiri bawah diperkecil */
    margin:-74px auto 0; /* konten tetap dinaikkan, tapi tidak terlalu tinggi */
  }

  .logoMain{ /* gambar logo utama */
    width:90px; /* ukuran logo diperkecil untuk hp */
    margin-bottom:12px; /* jarak bawah logo */
  }

  .brandCenter .logoText{ /* teks judul sistem absensi */
    font-size:22px; /* ukuran tulisan diperkecil */
    line-height:1.2; /* jarak antar baris teks */
  }

  .heroTitle{ /* judul sambutan */
    font-size:19px; /* ukuran judul diperkecil supaya muat di hp */
  }

  .foot{ /* bagian footer bawah */
    text-align:center; /* tulisan footer dibuat rata tengah */
  }
}
</style> <!-- penutup CSS -->
</head> <!-- penutup bagian head -->
<body> <!-- awal isi halaman yang tampil di browser -->

<div class="app"> <!-- pembungkus utama semua isi halaman -->
  <div class="topBlue"> <!-- area header biru atas -->
    <div class="topInner"> <!-- pembungkus isi header supaya rapi dan ada batas lebar -->
      <div class="topRow"> <!-- baris atas header, sekarang masih kosong -->
      </div>

      <div class="brandCenter"> <!-- area logo dan nama aplikasi, posisi tengah -->
        <img src="<?=($logoFile) ?>?v=3" alt="Logo PT MEINDO" class="logoMain"> <!-- tampilkan logo, htmlspecialchars untuk keamanan, ?v=3 biasanya buat refresh cache -->
        <div class="logoText">SISTEM ABSENSI</div> <!-- judul utama -->
        <div class="logoSub">PT MEINDO ELANG INDAH</div> <!-- subjudul / nama perusahaan -->
      </div>
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama halaman -->
    <div class="desktopGrid"> <!-- grid / susunan card -->
      <div class="card infoCard"> <!-- card kiri untuk info -->
        <div class="heroTitle">Selamat Datang 👋</div> <!-- judul sambutan -->
        <div class="heroDesc"> <!-- deskripsi penjelasan -->
          Silakan pilih akses masuk sesuai akun yang kamu miliki untuk menggunakan sistem absensi PT Meindo Elang Indah.
        </div>

        <div class="clockChip"> <!-- kotak kecil untuk jam -->
          <span id="clockText" data-serverms="<?= $serverMs; ?>">Memuat waktu...</span> <!-- tempat menampilkan jam, data-serverms isi waktu dari server -->
        </div>

        <div class="helpText"> <!-- teks bantuan -->
          Jika belum memiliki akun atau mengalami kendala akses, hubungi admin / IT.
        </div>
      </div>

      <div class="card actionCard"> <!-- card kanan untuk pilihan login -->
        <h3>Pilih Login</h3> <!-- judul card -->
        <div class="sub">Masuk ke sistem sesuai peran pengguna</div> <!-- subjudul card -->

        <div class="actionButtons"> <!-- pembungkus tombol login -->
          <a class="btn btnSecondary" href="login_karyawan.php"> <!-- tombol login karyawan -->
            <span>Login Karyawan</span> <!-- teks tombol -->
          </a>

          <a class="btn btnSecondary" href="login_admin.php"> <!-- tombol login admin -->
            <span>Login Admin</span> <!-- teks tombol -->
          </a>
        </div>

        <div class="tipBox"> <!-- kotak tips -->
          Tips: pastikan koneksi jaringan sesuai kebijakan kantor sebelum login ke sistem.
        </div>
      </div>
    </div>

    <div class="foot"> <!-- footer bawah -->
      <div>© <?= date("Y"); ?> Sistem Absensi • PT MEINDO ELANG INDAH</div> <!-- tampilkan tahun otomatis sesuai tahun sekarang -->
    </div>
  </div>
</div>

<script> /* awal javascript */
(() => { /* function langsung jalan otomatis */
  const el = document.getElementById("clockText"); /* ambil elemen yang id nya clockText */
  if (!el) return; /* kalau elemen tidak ada, hentikan script */

  const serverMs = Number(el.dataset.serverms || 0); /* ambil data waktu server dari attribute data-serverms lalu ubah jadi angka */
  const offset = serverMs - Date.now(); /* hitung selisih waktu server dengan waktu browser */

  const pad = (n) => String(n).padStart(2, "0"); /* fungsi untuk tambah 0 di depan angka, misal 1 jadi 01 */
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; /* daftar nama bulan */

  function tick(){ /* function untuk update jam */
    const now = new Date(Date.now() + offset); /* waktu sekarang dari browser ditambah selisih server supaya sinkron */
    const d = pad(now.getDate()); /* ambil tanggal */
    const m = bulan[now.getMonth()]; /* ambil nama bulan */
    const y = now.getFullYear(); /* ambil tahun */
    const h = pad(now.getHours()); /* ambil jam */
    const i = pad(now.getMinutes()); /* ambil menit */
    const s = pad(now.getSeconds()); /* ambil detik */
    el.textContent = `${d} ${m} ${y}, ${h}:${i}:${s} WIB`; /* tampilkan hasil waktu ke elemen */
  }

  tick(); /* jalankan sekali langsung supaya jam muncul */
  const delay = 1000 - ((Date.now() + offset) % 1000); /* hitung sisa waktu menuju detik berikutnya biar update pas */
  setTimeout(() => { /* tunggu sesuai delay */
    tick(); /* update lagi */
    setInterval(tick, 1000); /* lalu update tiap 1 detik terus menerus */
  }, delay);
})(); /* tutup function otomatis */
</script> <!-- akhir javascript -->
</body> <!-- akhir isi halaman -->
</html> <!-- akhir dokumen html -->