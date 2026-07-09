<?php
if (session_status() === PHP_SESSION_NONE) { // cek apakah session belum aktif
    session_start(); // kalau belum aktif, jalankan session untuk menyimpan data login
}

require_once "koneksi.php"; // panggil file koneksi database
date_default_timezone_set("Asia/Jakarta"); // set waktu ke zona WIB

$serverMs = (int) round(microtime(true) * 1000); // ambil waktu server dalam milidetik (dipakai untuk jam realtime)

// cek apakah user sudah login sebagai karyawan
if (isset($_SESSION['karyawan_login']) && $_SESSION['karyawan_login'] === true) {
    header("Location: karyawan.php"); // kalau sudah login, langsung ke halaman karyawan
    exit; // hentikan program
}

$error = ""; // variabel untuk menyimpan pesan error

// cek apakah tombol login ditekan
if (isset($_POST['login'])) {

    // ambil input dari form
    $user_id  = trim($_POST['user_id'] ?? ''); // ambil user_id dan hilangkan spasi
    $password = trim($_POST['password'] ?? ''); // ambil password dan hilangkan spasi

    // validasi jika kosong
    if ($user_id === "" || $password === "") {
        $error = "User ID dan Password wajib diisi!"; // tampilkan pesan error
    } else {

        // query untuk mencari user di database
        $sql = "SELECT user_id, name, password, role
                FROM users
                WHERE TRIM(user_id)=TRIM(?)
                LIMIT 1";

       $stmt = mysqli_prepare($conn, $sql); // prepare query (aman dari SQL Injection)

        if (!$stmt) die("Prepare gagal: " . mysqli_error($conn)); // kalau gagal, tampilkan error

        mysqli_stmt_bind_param($stmt, "s", $user_id); // masukkan user_id ke query
        mysqli_stmt_execute($stmt); // jalankan query
        $res = mysqli_stmt_get_result($stmt); // ambil hasil query

       // cek apakah data ditemukan
        if ($res && mysqli_num_rows($res) > 0) {

            $data = mysqli_fetch_assoc($res); // ambil data user

            // cek password
            if (trim($data['password']) === $password) {

                // cek role apakah karyawan
                if (strtolower(trim($data['role'])) === 'karyawan') {

                    // simpan data ke session
                    $_SESSION['karyawan_login'] = true;
                    $_SESSION['user_id'] = $data['user_id'];
                    $_SESSION['name']    = $data['name'];
                    $_SESSION['role']    = $data['role'];

                    header("Location: karyawan.php"); // redirect ke halaman karyawan
                    exit;

             } else {
                    $error = "Akun ini bukan KARYAWAN!"; // kalau bukan karyawan
                }

            } else {
                $error = "Password salah!"; // kalau password salah
            }

        } else {
            $error = "User ID tidak ditemukan!"; // kalau user tidak ada
        }
    }
}
?>

<!DOCTYPE html> <!-- tipe dokumen HTML5 -->
<html lang="id"> <!-- bahasa Indonesia -->

<head>
<meta charset="UTF-8" /> <!-- encoding karakter -->
<meta name="viewport" content="width=device-width, initial-scale=1.0" /> <!-- supaya responsive di HP -->
<title>Login Karyawan - PT MEINDO</title> <!-- judul tab -->

<link href="https://fonts.googleapis.com/css2?family=Poppins..." rel="stylesheet"> <!-- ambil font Poppins -->

<style>
:root{ /* variabel global CSS */
  --primary:#1f82ff; /* warna biru utama */
  --primary2:#0d67e6; /* biru lebih gelap */
  --bg:#eef4fb; /* background */
  --text:#18202a; /* warna teks */
  --muted:#7c8ca5; /* warna teks abu */
  --white:#ffffff; /* putih */
  --line:#e7edf5; /* garis */
  --danger:#ef4444; /* merah error */
  --shadow:0 12px 30px rgba(24, 54, 95, 0.10); /* bayangan */
  --radius-xl:28px; /* sudut besar */
  --radius-lg:22px;
  --radius-md:18px;
  --radius-sm:14px;
}

  /* reset CSS */
*{
  margin:0; /* hilangkan margin default */
  padding:0; /* hilangkan padding default */
  box-sizing:border-box; /* ukuran elemen termasuk padding */
}

body{
  font-family:'Poppins',sans-serif; /* font */
  background:linear-gradient(180deg, #dfeefd 0%, #eef4fb 30%, #eef4fb 100%); /* background gradasi */
  color:var(--text); /* warna teks */
  min-height:100vh; /* tinggi 1 layar */
}

.app{ /* class utama sebagai pembungkus seluruh halaman */
  width:100%; /* lebar dibuat penuh mengikuti layar */
  min-height:100vh; /* tinggi minimal 1 layar penuh (viewport height) */
  background:var(--bg); /* warna background diambil dari variabel --bg */
    position:relative;
    overflow-x:hidden;
  }

/* header biru */
.topBlue{
  background:linear-gradient(180deg, var(--primary), var(--primary2)); /* gradasi biru */
  padding:28px 18px 120px; /* jarak dalam */
  border-bottom-left-radius:32px; /* sudut kiri bawah */
  border-bottom-right-radius:32px; /* sudut kanan bawah */
  color:#fff; /* teks putih */
}

.topRow{

  align-items:center; /* tengah */
}

 .backBtn{ /* class tombol kembali */
  text-decoration:none; /* hilangkan garis bawah link */
  display:inline-flex; /* flex tapi tetap inline */
  align-items:center; /* isi rata tengah vertikal */
  gap:8px; /* jarak antar isi (icon & teks) */
  padding:10px 14px; /* jarak dalam tombol */
  border-radius:999px; /* bentuk kapsul (bulat panjang) */
  background:rgba(255,255,255,.16); /* background putih transparan */
  border:1px solid rgba(255,255,255,.22); /* border tipis transparan */
  color:#fff; /* warna teks putih */
  font-size:12px; /* ukuran teks kecil */
  font-weight:700; /* teks tebal */
}

  .brandCenter{ /* pembungkus judul tengah */
  text-align:center; /* teks rata tengah */
  margin-top:60px; /* jarak atas */
}

 .brandCenter .logoText{ /* judul utama */
  font-size:28px; /* ukuran besar */
  font-weight:900; /* sangat tebal */
  line-height:1.1; /* jarak baris */
  letter-spacing:.4px; /* jarak antar huruf */
}

  .brandCenter .logoSub{ /* subjudul */
  margin-top:6px; /* jarak atas */
  font-size:12px; /* ukuran kecil */
  opacity:.92; /* sedikit transparan */
}

  .mainContent{ /* isi utama halaman */
  width:100%; /* lebar penuh */
  max-width:1100px; /* batas maksimal */
  padding:0 16px 20px; /* jarak dalam */
  margin:-82px auto 0; /* dinaikkan ke atas (overlap header) */
  position:relative; /* posisi relatif */
  z-index:2; /* supaya di atas elemen lain */
}

.desktopGrid{ /* layout grid */
  display:grid; /* aktifkan grid */
  grid-template-columns:1fr; /* 1 kolom */
  gap:16px; /* jarak antar elemen */
}

 .card{ /* card umum */
  background:var(--white); /* background putih */
  border-radius:var(--radius-lg); /* sudut melengkung */
  box-shadow:var(--shadow); /* bayangan */
}

.infoCard{ /* card kiri */
  padding:16px; /* jarak dalam */
}

.infoTop{ /* bagian atas info */
  display:flex; /* flex */
  align-items:center; /* tengah */
  gap:12px; /* jarak */
  margin-bottom:14px; /* jarak bawah */
}

.logoBox{ /* kotak logo */
  width:72px; /* lebar */
  height:72px; /* tinggi */
  border-radius:18px; /* sudut */
  background:linear-gradient(180deg,#eef6ff,#dcecff); /* gradasi */
  border:1px solid #dce9f9; /* border */
  overflow:hidden; /* isi tidak keluar */
  display:flex; /* flex */
  align-items:center; /* tengah */
  justify-content:center; /* tengah */
  flex-shrink:0; /* tidak mengecil */
  padding:8px; /* jarak dalam */
}

 .logoBox img{ /* gambar logo */
  width:100%; /* penuh */
  height:100%; /* penuh */
  object-fit:contain; /* tidak terpotong */
  display:block; /* block */
}

.infoText h2{ /* judul kecil */
  font-size:15px; /* ukuran */
  font-weight:800; /* tebal */
  line-height:1.2; /* jarak */
  color:#2d3b4f; /* warna */
  margin-bottom:4px; /* jarak */
}

.infoText p{ /* deskripsi */
  font-size:11px; /* kecil */
  color:var(--muted); /* abu */
  line-height:1.5; /* jarak */
}

.heroDesc{ /* deskripsi utama silahkan login....*/
  font-size:12px; /* kecil */
  color:var(--muted); /* abu */
  line-height:1.65; /* jarak */
  margin-bottom:12px; /* jarak */
}

.clockChip{ /* box untuk menampilkan jam */
  display:inline-flex; /* flex tapi tetap inline (tidak full baris) */
  align-items:center; /* isi ditengah secara vertikal */
  background:#f3f8ff; /* warna background biru muda */
  color:var(--primary2); /* warna teks dari variabel */
  border:1px solid #d7e7ff; /* garis pinggir */
  border-radius:999px; /* bentuk kapsul (bulat panjang) */
  padding:10px 12px; /* jarak dalam */
  font-size:11px; /* ukuran teks kecil */
  font-weight:700; /* teks tebal */
  margin-bottom:12px; /* jarak bawah */
  }

.helpText{ /* teks bantuan */
  font-size:11px; /* kecil */
  color:var(--muted); /* warna abu */
  line-height:1.6; /* jarak antar baris */
}

 .loginCard{ /* card form login */
  padding:16px; /* jarak dalam */
}

.loginCard h3{ /* judul login */
  font-size:18px; /* ukuran */
  font-weight:900; /* tebal */
  color:#233548; /* warna */
  margin-bottom:4px; /* jarak bawah */
}

 .loginCard .sub{ /* subjudul */
  font-size:12px; /* kecil */
  color:var(--muted); /* abu */
  margin-bottom:14px; /* jarak bawah */
}

.alert{ /* box error */
  margin-bottom:12px; /* jarak bawah */
  padding:12px 14px; /* jarak dalam */
  border-radius:14px; /* sudut melengkung */
  background:#fff1f2; /* background merah muda */
  color:#b91c1c; /* warna teks merah */
  border:1px solid #fecdd3; /* border merah */
  font-size:12px; /* ukuran */
  line-height:1.55; /* jarak baris */
  font-weight:600; /* agak tebal */
}

.field{ /* pembungkus input */
  margin-bottom:12px; /* jarak antar field */
}

.label{ /* label input */
  display:block; /* tampil per baris */
  font-size:12px; /* ukuran */
  font-weight:700; /* tebal */
  color:#33475b; /* warna */
  margin-bottom:8px; /* jarak bawah */
}

.input{ /* input form */
  width:100%; /* lebar penuh */
  border:1px solid var(--line); /* border */
  border-radius:14px; /* sudut */
  padding:13px 14px; /* jarak dalam */
  font-family:'Poppins',sans-serif; /* font */
  font-size:13px; /* ukuran */
  color:#24384a; /* warna teks */
  background:#f8fbff; /* background */
  outline:none; /* hilangkan outline */

}

.input:focus{ /* saat input diklik */
  border-color:#9bc5ff; /* warna border berubah */
  background:#fff; /* background jadi putih */
  box-shadow:0 0 0 4px rgba(31,130,255,.10); /* efek glow */
}


.btnLogin{ /* tombol login */
  width:100%; /* penuh */
  border:none; /* tanpa border */
  border-radius:16px; /* sudut */
  padding:14px; /* jarak dalam */
  font-family:'Poppins',sans-serif; /* font */
  font-size:14px; /* ukuran */
  font-weight:800; /* tebal */
  cursor:pointer; /* cursor jadi tangan */
  color:#fff; /* teks putih */
  background:linear-gradient(180deg,#2c90ff,#1675ed); /* gradasi biru */
  transition:.15s ease; /* animasi */
  box-shadow:0 12px 24px rgba(22,117,237,.20); /* bayangan */
  margin-top:4px; /* jarak atas */
}

  .btnLogin:hover{ /* saat mouse diarahkan */
  transform:translateY(-1px); /* naik sedikit */
}

.foot{ /* footer */
  margin-top:14px; /* jarak atas */
  padding-top:12px; /* jarak dalam atas */
  border-top:1px solid var(--line); /* garis atas */
  text-align:center; /* rata tengah */
  font-size:11px; /* kecil */
  color:var(--muted); /* warna abu */
  line-height:1.5; /* jarak */
}

 @media (min-width: 900px){ /* kalau layar minimal 900px (desktop / laptop) */
  .desktopGrid{ /* grid utama */
    grid-template-columns:1fr 1fr; /* jadi 2 kolom kiri dan kanan */
    gap:20px; /* jarak antar kolom */
    align-items:stretch; /* tinggi elemen dibuat sama */
  }

  .brandCenter .logoText{ /* judul utama */
    font-size:34px; /* diperbesar di desktop */
  }

  .brandCenter .logoSub{ /* subjudul */
    font-size:13px; /* diperbesar sedikit */
  }

  .topBlue{ /* header biru */
    padding:24px 24px 130px; /* padding diperbesar */
  }

  .mainContent{ /* konten utama */
    margin:-92px auto 0; /* dinaikkan ke atas */
    padding:0 24px 24px; /* jarak dalam */
  }

  .infoCard,
  .loginCard{ /* kedua card */
    height:100%; /* tinggi dibuat sama */
  }
}

@media (max-width: 480px){ /* kalau layar hp kecil */
  .topBlue{
    padding:16px 14px 105px; /* header diperkecil */
  }

  .mainContent{
    padding:0 12px 18px; /* padding lebih kecil */
    margin:-74px auto 0; /* posisi disesuaikan */
  }

  .brandCenter .logoText{
    font-size:22px; /* judul diperkecil */
    line-height:1.2; /* jarak baris */
  }

  .logoBox{
    width:64px; /* logo lebih kecil */
    height:64px;
    padding:7px;
  }
}
</style>
</head>

<body>

<div class="app"> <!-- container utama halaman -->

  <div class="topBlue"> <!-- header biru -->
    <div class="topInner"> <!-- pembungkus isi header -->
      <div class="topRow"> <!-- baris atas -->
        <a href="index.php" class="backBtn">← Kembali</a> <!-- tombol kembali -->
      </div>

      <div class="brandCenter"> <!-- bagian tengah -->
        <div class="logoText">LOGIN KARYAWAN</div> <!-- judul -->
        <div class="logoSub">PT MEINDO ELANG INDAH</div> <!-- subjudul -->
      </div>
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama -->
    <div class="desktopGrid"> <!-- grid layout -->

      <!-- CARD KIRI -->
      <div class="card infoCard">
        <div class="infoTop"> <!-- bagian atas -->
          <div class="logoBox"> <!-- kotak logo -->
            <img src="assets/meindo.png" alt="Logo PT MEINDO"> <!-- gambar logo -->
          </div>

          <div class="infoText"> <!-- teks info -->
            <h2>Sistem Absensi Pegawai</h2> <!-- judul -->
            <p>Login khusus karyawan terdaftar</p> <!-- deskripsi -->
          </div>
        </div>

        <div class="heroDesc"> <!-- deskripsi -->
          Silakan login menggunakan <b>User ID</b> dan <b>Password</b> yang sudah terdaftar untuk mengakses halaman absensi karyawan.
        </div>

        <div class="clockChip"> <!-- box jam -->
          <span id="clockText" data-serverms="<?= $serverMs; ?>">Memuat waktu...</span> <!-- tempat jam -->
        </div>

        <div class="helpText"> <!-- bantuan -->
          Jika mengalami kendala login, silakan hubungi admin HR / IT perusahaan.
        </div>
      </div>

      <!-- CARD KANAN -->
      <div class="card loginCard">
        <h3>Masuk ke Akun Karyawan</h3> <!-- judul -->
        <div class="sub">Masukkan User ID dan Password</div> <!-- subjudul -->

        <?php if ($error !== ""): ?> <!-- kalau ada error -->
          <div class="alert"><?= htmlspecialchars($error) ?></div> <!-- tampilkan error -->
        <?php endif; ?>

        <form method="POST" autocomplete="off"> <!-- form login -->

          <div class="field"> <!-- field user -->
            <label class="label">User ID</label> <!-- label -->
            <input class="input" type="text" name="user_id" placeholder="Contoh: 3002 / KRY001" required> <!-- input -->
          </div>

          <div class="field"> <!-- field password -->
            <label class="label">Password</label> <!-- label -->
            <input class="input" type="password" name="password" placeholder="Masukkan password" required> <!-- input -->
          </div>

          <button class="btnLogin" type="submit" name="login">LOGIN</button> <!-- tombol login -->
        </form>

        <div class="foot"> <!-- footer -->
          © <?= date("Y"); ?> Sistem Absensi • PT MEINDO ELANG INDAH <!-- tahun otomatis -->
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(() => { // function langsung jalan

  const el = document.getElementById("clockText"); // ambil elemen jam
  if (!el) return; // kalau tidak ada, hentikan

  const serverMs = Number(el.dataset.serverms || 0); // ambil waktu dari PHP
  const offset = serverMs - Date.now(); // hitung selisih waktu server dan browser

  const pad = (n) => String(n).padStart(2, "0"); // fungsi tambah 0 di depan angka
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; // nama bulan

  function tick(){ // fungsi update jam
    const now = new Date(Date.now() + offset); // waktu sekarang

    const d = pad(now.getDate()); // tanggal
    const m = bulan[now.getMonth()]; // bulan
    const y = now.getFullYear(); // tahun

    const h = pad(now.getHours()); // jam
    const i = pad(now.getMinutes()); // menit
    const s = pad(now.getSeconds()); // detik

    el.textContent = `${d} ${m} ${y}, ${h}:${i}:${s} WIB`; // tampilkan waktu
  }

  tick(); // jalankan pertama

  const delay = 1000 - ((Date.now() + offset) % 1000); // supaya sinkron per detik

  setTimeout(() => {
    tick(); // update
    setInterval(tick, 1000); // update tiap 1 detik
  }, delay);

})();
</script>

</body>
</html>