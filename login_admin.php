<?php
if (session_status() === PHP_SESSION_NONE) { // cek apakah session belum aktif
    session_start(); // kalau belum aktif, maka session dimulai supaya data login bisa disimpan
}

require_once "koneksi.php"; // memanggil file koneksi database, supaya file ini bisa terhubung ke MySQL
date_default_timezone_set("Asia/Jakarta"); // mengatur zona waktu ke WIB, supaya waktu yang tampil sesuai Indonesia

$error = ""; // variabel untuk menampung pesan error, awalnya dikosongkan
$serverMs = (int) round(microtime(true) * 1000); // mengambil waktu server dalam milidetik, nanti dipakai untuk jam realtime di halaman

if (isset($_POST['login'])) { // cek apakah tombol login sudah ditekan
    $user_id  = trim($_POST['user_id'] ?? ''); // ambil input user_id dari form, lalu hilangkan spasi di depan dan belakang
    $password = trim($_POST['password'] ?? ''); // ambil input password dari form, lalu hilangkan spasi di depan dan belakang

    if ($user_id === "" || $password === "") { // validasi, kalau user_id atau password kosong
        $error = "User ID dan Password wajib diisi!"; // tampilkan pesan bahwa keduanya harus diisi
    } else { // kalau input tidak kosong
        $sql = "SELECT user_id, name, password, role
                FROM users
                WHERE user_id = ?
                LIMIT 1"; // query untuk mencari data user berdasarkan user_id, hanya ambil 1 data saja

       $stmt = mysqli_prepare($conn, $sql); // menyiapkan query SQL dengan prepared statement agar lebih aman dari SQL Injection
        if (!$stmt) { // kalau proses prepare gagal
            die("Prepare gagal: " . mysqli_error($conn)); // hentikan program dan tampilkan pesan error database
        }

        mysqli_stmt_bind_param($stmt, "s", $user_id); // mengikat parameter user_id ke tanda tanya (?) di query, s artinya string
        mysqli_stmt_execute($stmt); // menjalankan query yang sudah disiapkan
        $result = mysqli_stmt_get_result($stmt); // mengambil hasil query

        if ($result && mysqli_num_rows($result) > 0) { // cek apakah data user ditemukan di database
            $data = mysqli_fetch_assoc($result); // ambil data user dalam bentuk array associative

            if ($data['password'] === $password) { // cek apakah password dari database sama dengan password yang diinput
                if (strtolower(trim($data['role'])) === "admin") { // cek apakah role user adalah admin
                    $_SESSION['admin_login'] = true; // simpan status bahwa admin sudah login
                    $_SESSION['user_id']     = $data['user_id']; // simpan user_id admin ke session
                    $_SESSION['name']        = $data['name']; // simpan nama admin ke session
                    $_SESSION['role']        = $data['role']; // simpan role admin ke session

                    header("Location: admin.php"); // kalau login berhasil, pindahkan ke halaman admin
                    exit; // hentikan eksekusi setelah redirect
                } else { // kalau password benar tapi role bukan admin
                    $error = "Akun ini bukan ADMIN!"; // tampilkan pesan bahwa akun tersebut bukan akun admin
                }
            } else { // kalau password tidak cocok
                $error = "Password salah!"; // tampilkan pesan password salah
            }
        } else { // kalau user_id tidak ada di database
            $error = "User ID tidak ditemukan!"; // tampilkan pesan user tidak ditemukan
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id"> <!-- dokumen HTML dengan bahasa Indonesia -->
<head>
<meta charset="UTF-8"> <!-- karakter encoding UTF-8 supaya simbol dan huruf tampil normal -->
<meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- supaya tampilan responsive di hp, tablet, dan laptop -->
<title>Login Admin - PT MEINDO</title> <!-- judul tab browser -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"> <!-- mengambil font Poppins dari Google Fonts -->

<style>
  :root{ /* tempat mendefinisikan variabel global CSS */
    --primary:#1f82ff; /* warna utama biru */
    --primary2:#0d67e6; /* warna biru kedua untuk gradasi */
    --bg:#eef4fb; /* warna background utama */
    --text:#18202a; /* warna teks utama */
    --muted:#7c8ca5; /* warna teks sekunder / lebih soft */
    --white:#ffffff; /* warna putih */
    --line:#e7edf5; /* warna border / garis */
    --danger:#ef4444; /* warna merah untuk error / alert */
    --shadow:0 12px 30px rgba(24, 54, 95, 0.10); /* efek bayangan card */
    --radius-xl:28px; /* sudut lengkung paling besar */
    --radius-lg:22px; /* sudut lengkung besar */
    --radius-md:18px; /* sudut lengkung sedang */
    --radius-sm:14px; /* sudut lengkung kecil */
  }

  *{ /* berlaku ke semua elemen */
    margin:0; /* hapus margin bawaan */
    padding:0; /* hapus padding bawaan */
    box-sizing:border-box; /* ukuran elemen termasuk padding dan border */
  }

  html, body{ /* untuk elemen html dan body */
    width:100%; /* lebar penuh */
    min-height:100%; /* tinggi minimal penuh */
    overflow-x:hidden; /* sembunyikan scroll horizontal */
  }

  body{
    font-family:'Poppins',sans-serif; /* memakai font Poppins */
    background:linear-gradient(180deg, #dfeefd 0%, #eef4fb 30%, #eef4fb 100%); /* background gradasi dari atas ke bawah */
    color:var(--text); /* warna teks default */
    min-height:100vh; /* tinggi minimal 1 layar penuh */
  }

  .app{
    width:100%; /* lebar penuh */
    min-height:100vh; /* tinggi penuh layar */
    background:var(--bg); /* warna background utama */
    position:relative; /* posisi relatif supaya bisa diatur layernya */
    overflow-x:hidden; /* tidak ada scroll samping */
  }

  .topBlue{
    background:linear-gradient(180deg, var(--primary), var(--primary2)); /* header dengan gradasi biru */
    padding:18px 18px 120px; /* jarak dalam atas kanan kiri bawah */
    border-bottom-left-radius:32px; /* sudut kiri bawah melengkung */
    border-bottom-right-radius:32px; /* sudut kanan bawah melengkung */
    color:#fff; /* teks putih */
    position:relative; /* posisi relatif */
  }

  .topRow{
    align-items:center; /* rata tengah secara vertikal */
    gap:12px; /* jarak antar elemen */
  }

  .backBtn{
    text-decoration:none; /* hilangkan garis bawah link */
    display:inline-flex; /* flex tapi tetap inline */
    align-items:center; /* isi rata tengah vertikal */
    gap:8px; /* jarak isi tombol */
    padding:10px 14px; /* jarak dalam tombol */
    border-radius:999px; /* bentuk kapsul / bulat panjang */
    background:rgba(255,255,255,.16); /* background putih transparan */
    border:1px solid rgba(255,255,255,.22); /* border tipis transparan */
    color:#fff; /* warna teks putih */
    font-size:12px; /* ukuran teks */
    font-weight:700; /* ketebalan teks */
  }

  .backBtn:hover{
    background:rgba(255,255,255,.22); /* saat mouse diarahkan, background sedikit lebih terang */
  }

  .adminBadge{
    width:40px; /* lebar badge */
    height:40px; /* tinggi badge */
    border-radius:50%; /* bentuk lingkaran */
    background:rgba(255,255,255,.17); /* background transparan */
    border:1px solid rgba(255,255,255,.22); /* border tipis */
    display:flex; /* flexbox */
    align-items:center; /* rata tengah vertikal */
    justify-content:center; /* rata tengah horizontal */
    font-size:18px; /* ukuran icon */
    flex-shrink:0; /* tidak mengecil */
  }

  .brandCenter{
    text-align:center; /* isi rata tengah */
    margin-top:18px; /* jarak atas */
  }

  .brandCenter .logoText{
    font-size:28px; /* ukuran judul */
    font-weight:900; /* sangat tebal */
    line-height:1.1; /* jarak antar baris */
    letter-spacing:.4px; /* jarak antar huruf */
  }

  .brandCenter .logoSub{
    margin-top:6px; /* jarak atas */
    font-size:12px; /* ukuran teks kecil */
    opacity:.92; /* sedikit transparan */
  }

  .mainContent{
    width:100%; /* lebar penuh */
    max-width:1100px; /* maksimal lebar */
    padding:0 16px 20px; /* jarak dalam */
    margin:-82px auto 0; /* konten dinaikkan ke atas supaya masuk ke area header */
    position:relative; /* posisi relatif */
    z-index:2; /* layer di atas */
  }

  .desktopGrid{
    display:grid; /* pakai layout grid */
    grid-template-columns:1fr; /* default 1 kolom */
    gap:16px; /* jarak antar card */
  }

  .card{
    background:var(--white); /* latar putih */
    border-radius:var(--radius-lg); /* sudut melengkung */
    box-shadow:var(--shadow); /* bayangan */
  }

  .infoCard{
    padding:16px; /* jarak dalam card info */
  }

  .infoTop{
    display:flex; /* flexbox */
    align-items:center; /* rata tengah vertikal */
    gap:12px; /* jarak antar isi */
    margin-bottom:14px; /* jarak bawah */
  }

  .logoBox{
    width:54px; /* lebar box logo */
    height:54px; /* tinggi box logo */
    border-radius:16px; /* sudut melengkung */
    background:linear-gradient(180deg,#eef6ff,#dcecff); /* background gradasi */
    border:1px solid #dce9f9; /* border */
    overflow:hidden; /* isi yang keluar dipotong */
    display:flex; /* flex */
    align-items:center; /* tengah vertikal */
    justify-content:center; /* tengah horizontal */
    flex-shrink:0; /* tidak mengecil */
  }

  .logoBox img{
    width:100%; /* gambar memenuhi box */
    height:100%; /* tinggi memenuhi box */
    object-fit:contain; /* gambar tidak terpotong, hanya menyesuaikan */
  }

  .infoText h2{
    font-size:15px; /* ukuran judul kecil */
    font-weight:800; /* tebal */
    line-height:1.2; /* jarak baris */
    color:#2d3b4f; /* warna teks */
    margin-bottom:4px; /* jarak bawah */
  }

  .infoText p{
    font-size:11px; /* ukuran teks kecil */
    color:var(--muted); /* warna abu */
    line-height:1.5; /* jarak baris */
  }
/*
  .heroTitle{
    font-size:22px; /* ukuran judul utama 
    font-weight:900; /* sangat tebal 
    color:#203349; /* warna teks 
    line-height:1.2; /* jarak baris 
    margin-bottom:8px; /* jarak bawah 
  }*/


  .heroDesc{
    font-size:12px; /* ukuran deskripsi */
    color:var(--muted); /* warna teks lembut */
    line-height:1.65; /* jarak baris */
    margin-bottom:12px; /* jarak bawah */
  }

  .clockChip{
    display:inline-flex; /* flex inline */
    align-items:center; /* rata tengah vertikal */
    background:#f3f8ff; /* background box jam */
    color:var(--primary2); /* warna teks */
    border:1px solid #d7e7ff; /* border */
    border-radius:999px; /* bentuk kapsul */
    padding:10px 12px; /* jarak dalam */
    font-size:11px; /* ukuran teks */
    font-weight:700; /* tebal */
    margin-bottom:12px; /* jarak bawah */
   
  }

  .helpText{
    font-size:11px; /* ukuran kecil */
    color:var(--muted); /* warna abu */
    line-height:1.6; /* jarak baris */
  }

  .loginCard{
    padding:16px; /* jarak dalam card login */
  }

  .loginCard h3{
    font-size:18px; /* ukuran judul */
    font-weight:900; /* tebal */
    color:#233548; /* warna */
    margin-bottom:4px; /* jarak bawah */
  }

  .loginCard .sub{
    font-size:12px; /* ukuran teks kecil */
    color:var(--muted); /* warna lembut */
    margin-bottom:14px; /* jarak bawah */
  }

  .alert{
    margin-bottom:12px; /* jarak bawah */
    padding:12px 14px; /* jarak dalam */
    border-radius:14px; /* sudut melengkung */
    background:#fff1f2; /* background merah muda */
    color:#b91c1c; /* warna teks merah */
    border:1px solid #fecdd3; /* border merah muda */
    font-size:12px; /* ukuran teks */
    line-height:1.55; /* jarak baris */
    font-weight:600; /* tebal sedang */
  }

  .field{
    margin-bottom:12px; /* jarak antar field */
  }

  .label{
    display:block; /* label ditampilkan per baris */
    font-size:12px; /* ukuran teks */
    font-weight:700; /* tebal */
    color:#33475b; /* warna */
    margin-bottom:8px; /* jarak bawah */
  }

  .input{
    width:100%; /* lebar penuh */
    border:1px solid var(--line); /* border */
    border-radius:14px; /* sudut melengkung */
    padding:13px 14px; /* jarak dalam */
    font-family:'Poppins',sans-serif; /* font */
    font-size:13px; /* ukuran teks */
    color:#24384a; /* warna teks */
    background:#f8fbff; /* background input */
    outline:none; /* hilangkan outline bawaan */
  }

  .input:focus{
    border-color:#9bc5ff; /* border berubah saat aktif */
    background:#fff; /* background jadi putih */
    box-shadow:0 0 0 4px rgba(31,130,255,.10); /* efek glow saat klik */
  }

  
  .btnLogin{
    width:100%; /* tombol penuh */
    border:none; /* tanpa border */
    border-radius:16px; /* sudut melengkung */
    padding:14px; /* jarak dalam */
    font-family:'Poppins',sans-serif; /* font */
    font-size:14px; /* ukuran teks */
    font-weight:800; /* tebal */
    cursor:pointer; /* cursor berubah jadi tangan */
    color:#fff; /* warna teks putih */
    background:linear-gradient(180deg,#2c90ff,#1675ed); /* tombol gradasi biru */
    transition:.15s ease; /* animasi halus */
    box-shadow:0 12px 24px rgba(22,117,237,.20); /* bayangan tombol */
    margin-top:4px; /* jarak atas */
  }

  .btnLogin:hover{
    transform:translateY(-1px); /* saat diarahkan mouse, tombol naik sedikit */
  }

  .foot{
    margin-top:14px; /* jarak atas */
    padding-top:12px; /* jarak dalam atas */
    border-top:1px solid var(--line); /* garis pemisah atas */
    text-align:center; /* rata tengah */
    font-size:11px; /* ukuran kecil */
    color:var(--muted); /* warna teks */
    line-height:1.5; /* jarak baris */
  }

  @media (min-width: 900px){ /* kalau layar desktop */
    .desktopGrid{
      grid-template-columns:1fr 1fr; /* jadi 2 kolom */
      gap:20px; /* jarak antar kolom */
      align-items:stretch; /* tinggi card disamakan */
    }

    .brandCenter .logoText{
      font-size:34px; /* judul diperbesar */
    }

    .brandCenter .logoSub{
      font-size:13px; /* subjudul diperbesar sedikit */
    }

    .topBlue{
      padding:24px 24px 130px; /* header sedikit diperbesar */
    }

    .mainContent{
      margin:-92px auto 0; /* konten dinaikkan lebih tinggi */
      padding:0 24px 24px; /* jarak samping lebih besar */
    }

    .infoCard,
    .loginCard{
      height:100%; /* tinggi card disamakan */
    }
  }

  @media (max-width: 480px){ /* kalau layar hp kecil */
    .topBlue{
      padding:16px 14px 105px; /* header diperkecil */
    }

    .mainContent{
      padding:0 12px 18px; /* padding diperkecil */
      margin:-74px auto 0; /* konten tetap dinaikkan tapi tidak terlalu tinggi */
    }

    .brandCenter .logoText{
      font-size:22px; /* judul diperkecil */
      line-height:1.2; /* jarak baris */
    }

  }
</style>
</head>
<body>

<div class="app"> <!-- pembungkus utama halaman -->
  <div class="topBlue"> <!-- header biru atas -->
    <div class="topInner"> <!-- pembatas isi header -->
      <div class="topRow"> <!-- baris atas -->
        <a class="backBtn" href="index.php">← Kembali</a> <!-- tombol kembali ke halaman index -->
      </div>

      <div class="brandCenter"> <!-- area judul login -->
        <div class="logoText">LOGIN ADMIN</div> <!-- judul utama -->
        <div class="logoSub">PT MEINDO ELANG INDAH</div> <!-- subjudul -->
      </div>
    </div>
  </div>

  <div class="mainContent"> <!-- isi utama -->
    <div class="desktopGrid"> <!-- grid card -->

      <div class="card infoCard"> <!-- card kiri berisi informasi -->
        <div class="infoTop"> <!-- baris atas info -->
          <div class="logoBox"> <!-- kotak logo -->
            <img src="assets/meindo.png" alt="Logo PT MEINDO"> <!-- logo perusahaan -->
          </div>
          <div class="infoText"> <!-- teks info -->
            <h2>Sistem Absensi Pegawai</h2> <!-- judul kecil -->
            <p>Login khusus administrator</p> <!-- penjelasan -->
          </div>
        </div>

        <div class="heroDesc"> <!-- deskripsi -->
          Silakan login sebagai <b>Admin</b> menggunakan User ID dan Password yang terdaftar untuk mengakses halaman administrasi sistem.
        </div>

        <div class="clockChip"> <!-- box jam -->
          <span id="clockText" data-serverms="<?= $serverMs; ?>">Memuat waktu...</span> <!-- tempat menampilkan jam realtime -->
        </div>

        <div class="helpText"> <!-- teks bantuan -->
          Jika mengalami kendala login, silakan hubungi admin / IT perusahaan.
        </div>
      </div>

      <div class="card loginCard"> <!-- card kanan berisi form login -->
        <h3>Masuk ke Akun Admin</h3> <!-- judul form -->
        <div class="sub">Masukkan User ID dan Password</div> <!-- subjudul form -->

        <?php if ($error !== ""): ?> <!-- kalau ada error -->
          <div class="alert"><?= htmlspecialchars($error) ?></div> <!-- tampilkan pesan error dengan aman -->
        <?php endif; ?>

        <form method="POST"> <!-- form login dengan metode POST -->
          <div class="field"> <!-- field user id -->
            <label class="label">User ID</label> <!-- label input -->
            <input class="input" type="text" name="user_id" placeholder="Contoh: 3001 / ADM001" required> <!-- input user id -->
          </div>

          <div class="field"> <!-- field password -->
            <label class="label">Password</label> <!-- label password -->
            <input class="input" type="password" name="password" placeholder="Masukkan password" required> <!-- input password -->
          </div>

          <button class="btnLogin" type="submit" name="login">LOGIN</button> <!-- tombol submit login -->
        </form>

        <div class="foot"> <!-- footer card -->
          © <?= date("Y"); ?> Sistem Absensi • PT MEINDO ELANG INDAH <!-- tahun otomatis -->
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(() => { // function langsung dijalankan otomatis
  const el = document.getElementById('clockText'); // ambil elemen yang akan menampilkan jam
  if (!el) return; // kalau elemen tidak ada, script berhenti

  const serverMs = Number(el.dataset.serverms || 0); // ambil data waktu server dari atribut HTML lalu ubah ke angka
  const offset = serverMs - Date.now(); // hitung selisih antara waktu server dan waktu browser

  const pad = (n) => String(n).padStart(2, '0'); // fungsi untuk menambah angka 0 di depan jika angka kurang dari 2 digit
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"]; // array nama bulan

  function tick() { // function untuk update jam
    const now = new Date(Date.now() + offset); // waktu sekarang dari browser disesuaikan dengan waktu server
    const dd = pad(now.getDate()); // ambil tanggal
    const mm = bulan[now.getMonth()]; // ambil nama bulan
    const yy = now.getFullYear(); // ambil tahun
    const hh = pad(now.getHours()); // ambil jam
    const mi = pad(now.getMinutes()); // ambil menit
    const ss = pad(now.getSeconds()); // ambil detik
    el.textContent = `${dd} ${mm} ${yy}, ${hh}:${mi}:${ss} WIB`; // tampilkan format waktu ke halaman
  }

  tick(); // jalankan sekali supaya waktu langsung muncul
  const delay = 1000 - ((Date.now() + offset) % 1000); // hitung delay supaya update tepat di pergantian detik
  setTimeout(() => { // tunggu sampai detik pas
    tick(); // update lagi
    setInterval(tick, 1000); // lalu update setiap 1 detik
  }, delay);
})();
</script>

</body>
</html>